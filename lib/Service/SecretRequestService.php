<?php

/**
 * Doriath Secret Request Service
 *
 * The SecretRequest lifecycle: create / fill / approve / decline / list
 * over SecretRequest rows. The preconditions and authorization rules live
 * in SecretRequestPolicy, the outbound audit + notification signalling in
 * SecretRequestOutbox, and the compromise-recovery locking in
 * SecretRequestSuiteLockService — this class is the state machine that
 * moves the rows between those decisions.
 *
 * @category Service
 * @package  OCA\Doriath\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Doriath\Service;

use DateTime;
use InvalidArgumentException;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\SecretRequest;
use OCA\Doriath\Db\SecretRequestMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Business logic for the SecretRequest lifecycle.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) 14 against a threshold of 13,
 * and one of the fourteen cannot be removed: SecretService takes a nullable
 * SecretRequestService and this class reaches SecretService through the
 * container, which is how the cycle between them is broken. Injecting
 * SecretService directly to drop ContainerInterface would trade one coupling
 * for a construction-time circular dependency. The remainder are two mappers,
 * two Db entities, two lookup exceptions and the value types (DateTime, Uuid) —
 * none of them indirection worth adding a class to hide.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) 11 against a threshold of 10.
 * The public surface IS the request state machine — create (plain / fresh /
 * application / re-request), fill, approve, decline, and the read paths. The
 * eleventh is `createForUserVault()`, which exists because a fresh request has
 * to create its own Secret before it can create the request; splitting the
 * creation paths across classes would scatter the rollback and the
 * fresh-vs-re-request distinction that the spec turns on.
 */
class SecretRequestService {
	/*
	 * The field model lives on SecretRequestPolicy so validation and persistence
	 * cannot drift apart: the validator decides which bucket a requested name
	 * must arrive in, and this class decides which column it lands on. Two
	 * copies of that mapping would eventually disagree.
	 */

	/**
	 * Constructor for SecretRequestService.
	 *
	 * @param SecretRequestMapper $mapper The mapper
	 * @param SecretRequestPolicy $policy The precondition/authorization policy
	 * @param SecretRequestOutbox $outbox The audit + notification outbox
	 * @param LoggerInterface $logger The logger
	 * @param WriteLockService $writeLockService The compromise-recovery write lock
	 * @param SecretMapper $secretMapper Reads the Secret a request writes to
	 * @param ContainerInterface $container Resolves SecretService lazily (see fill)
	 * @param SecretPlaceholderCleaner $placeholderCleaner Removes an unfilled placeholder
	 *
	 * @return void
	 */
	public function __construct(
		private SecretRequestMapper $mapper,
		private SecretRequestPolicy $policy,
		private SecretRequestOutbox $outbox,
		private LoggerInterface $logger,
		private WriteLockService $writeLockService,
		private SecretMapper $secretMapper,
		private ContainerInterface $container,
		private SecretPlaceholderCleaner $placeholderCleaner,
	) {
	}//end __construct()

	/**
	 * Look up a pending, non-expired request by its public access token.
	 *
	 * Distinguishes locked / expired / fulfilled / unknown via specific
	 * error codes so the public fill page can render targeted messaging.
	 *
	 * @param string $token The access token
	 *
	 * @return SecretRequest
	 *
	 * @throws InvalidArgumentException With code 404 (unknown), 410 (fulfilled),
	 *                                  423 (locked) or 408 (expired).
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-fill-in-via-link
	 */
	public function getByToken(string $token): SecretRequest {
		return $this->policy->requireOpenByToken(token: $token);
	}//end getByToken()

	/**
	 * Why a token cannot be filled, as a machine-readable reason.
	 *
	 * Pass-through to the policy, which classifies refusals, so the public fill
	 * page can render the refusal in the recipient's language instead of the
	 * English message this server composes. See SecretRequestPolicy::REASONS.
	 *
	 * @param string $token The access token
	 *
	 * @return string One of SecretRequestPolicy::REASONS
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-fill-in-via-link
	 */
	public function refusalReason(string $token): string {
		return $this->policy->refusalReason(token: $token);
	}//end refusalReason()

	/**
	 * Mark a pending request as fulfilled from the public fill endpoint.
	 *
	 * Validates the lifecycle / expiry / requested-fields invariants, atomically
	 * flips status to fulfilled, and announces it to the original requester.
	 *
	 * `$encryptedFields` IS persisted onto the linked Secret, which the spec
	 * requires ("store them in the linked Secret" — Fill In via Link). It used
	 * not to be: this method validated the blobs, flipped the status to
	 * fulfilled and discarded them, so a recipient's submission was silently
	 * lost and the request was marked fulfilled with nothing to show for it.
	 *
	 * The write goes through SecretService rather than the mapper, so it
	 * inherits that path's guarantees: a version-history snapshot, and
	 * `possibly_compromised_at` clearing, which is bound to an actual `key`
	 * write. Writing via the mapper would silently skip both.
	 *
	 * Order matters. Values are persisted BEFORE the status flip, so a failed
	 * write leaves the request `pending` and retryable rather than fulfilled
	 * and empty.
	 *
	 * @param string $token The access token
	 * @param array<string,mixed> $encryptedFields A map of fieldName => encryptedValue
	 * @param array<string,mixed> $plainFields Plaintext metadata (url), unencrypted by design
	 *
	 * @return SecretRequest
	 *
	 * @throws InvalidArgumentException
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.5
	 */
	public function fill(string $token, array $encryptedFields, array $plainFields = []): SecretRequest {
		$entity = $this->policy->requireOpenByToken(token: $token);
		$this->policy->requireAllRequestedFields(
			entity: $entity,
			encryptedFields: $encryptedFields,
			plainFields: $plainFields
		);

		// Atomic transition: re-load + flip to defend against a parallel
		// fill that may have raced us between the token lookup and here.
		$current = $this->policy->requirePendingById(requestId: $entity->getId());

		// BEFORE the flip: a throw here leaves the request pending, which is
		// the recoverable failure. Flipping first would mark it fulfilled with
		// the value lost — the defect this method used to have.
		$this->persistFilledValues(
			request: $current,
			encryptedFields: $encryptedFields,
			plainFields: $plainFields
		);

		$current->setStatus(SecretRequest::STATUS_FULFILLED);
		$current->setFulfilledAt(new DateTime());
		$persisted = $this->mapper->update($current);

		$this->logger->info(
			'Filled secret request ' . $current->getId() . ' for secret ' . $current->getSecretId(),
			['app' => 'doriath']
		);

		$this->outbox->announceFulfilled(request: $current);

		return $persisted;
	}//end fill()

	/**
	 * Write the submitted ciphertext onto the Secret the request points at.
	 *
	 * The blobs arrive already encrypted to the REQUESTER's suite certificate
	 * (the public fill endpoint hands the recipient that certificate), so they
	 * are stored verbatim — the server neither encrypts nor decrypts here
	 * (ADR-003).
	 *
	 * Routed by owner type because the two write paths enforce different
	 * scoping: `update()` requires `ownerType === 'user'` and matches on the
	 * user id, while `updateByApplication()` requires `ownerType ===
	 * 'application'`. An application-owned request — the whole point of
	 * application-initiated requests — cannot go through the user path at all.
	 *
	 * SecretService is resolved from the container rather than injected: it
	 * already declares an optional `SecretRequestService` of its own, so a
	 * constructor dependency here would close an autowiring cycle whose
	 * resolution depends on construction order. Resolving at call time has no
	 * such ordering.
	 *
	 * @param SecretRequest $request The request being fulfilled
	 * @param array<string,mixed> $encryptedFields fieldName => ciphertext
	 * @param array<string,mixed> $plainFields fieldName => plaintext metadata
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a field name has nowhere to be stored
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-fill-in-via-link
	 */
	private function persistFilledValues(
		SecretRequest $request,
		array $encryptedFields,
		array $plainFields,
	): void {
		$data = [];

		// Encrypted bucket: the Secret's two ciphertext value columns, plus the
		// one blob that carries every additional member.
		foreach ($encryptedFields as $field => $value) {
			$allowed = array_merge(SecretRequestPolicy::ENCRYPTED_FIELDS, [SecretRequestPolicy::ADDITIONAL_BLOB]);

			if (in_array($field, SecretRequestPolicy::PLAINTEXT_FIELDS, true) === true) {
				// Refused rather than stored: this column is searchable
				// plaintext, so a ciphertext here would break search and render
				// the value unreadable to its owner.
				throw new InvalidArgumentException(
					message: sprintf('Field "%s" is plaintext metadata and must be sent in plainFields', $field),
					code: 400
				);
			}

			if (in_array($field, $allowed, true) === false) {
				throw new InvalidArgumentException(
					message: sprintf(
						'Field "%s" cannot be stored on a secret. Additional members belong inside the '
						. '"%s" blob, keyed by their own names.',
						$field,
						SecretRequestPolicy::ADDITIONAL_BLOB
					),
					code: 400
				);
			}

			$data[$field] = $value;
		}

		// Plaintext bucket: metadata the owner searches on.
		foreach ($plainFields as $field => $value) {
			if (in_array($field, SecretRequestPolicy::PLAINTEXT_FIELDS, true) === false) {
				throw new InvalidArgumentException(
					message: sprintf('Field "%s" is not plaintext metadata and must be encrypted', $field),
					code: 400
				);
			}

			$data[$field] = $value;
		}

		if ($data === []) {
			return;
		}

		try {
			$secret = $this->secretMapper->findById($request->getSecretId());
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			throw new InvalidArgumentException(
				message: 'The secret this request writes to no longer exists',
				code: 404
			);
		}

		$secretService = $this->container->get(SecretService::class);

		if ($secret->getOwnerType() === 'application') {
			$secretService->updateByApplication(
				id: $secret->getId(),
				data: $data,
				applicationId: $secret->getOwnerId()
			);

			return;
		}

		$secretService->update(
			id: $secret->getId(),
			data: $data,
			userId: $secret->getOwnerId()
		);
	}//end persistFilledValues()

	/**
	 * Create a new pending secret request.
	 *
	 * @param string $secretId The Secret ID (unfilled or re-request)
	 * @param string $encryptionSuiteId The recipient's active suite ID
	 * @param array<string> $requestedFields Field names to be filled in
	 * @param bool $isReRequest Whether this is a re-request
	 * @param DateTime|null $expiresAt Optional expiry
	 * @param string $userId The Nextcloud user creating the request
	 *
	 * @return SecretRequest
	 *
	 * @throws InvalidArgumentException
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.5
	 */
	public function create(
		string $secretId,
		string $encryptionSuiteId,
		array $requestedFields,
		bool $isReRequest,
		?DateTime $expiresAt,
		string $userId,
	): SecretRequest {
		// Pending requests are locked for the duration of a migration and
		// re-pointed to the new suite on completion. A request created now would
		// be born locked, so refuse it with an explanation instead.
		$this->writeLockService->assertNotWriteLocked(ownerId: $userId);

		if ($secretId === '') {
			throw new InvalidArgumentException(message: 'secretId is required');
		}

		if ($encryptionSuiteId === '') {
			throw new InvalidArgumentException(message: 'encryptionSuiteId is required');
		}

		if ($requestedFields === []) {
			throw new InvalidArgumentException(message: 'requestedFields cannot be empty');
		}

		if ($userId === '') {
			throw new InvalidArgumentException(message: 'userId is required');
		}

		$entity = new SecretRequest();
		$entity->setId(Uuid::uuid4()->toString());
		$entity->setSecretId($secretId);
		$entity->setEncryptionSuiteId($encryptionSuiteId);
		$entity->setToken(bin2hex(random_bytes(16)));
		$encodedFields = json_encode(array_values($requestedFields));
		if ($encodedFields === false) {
			$encodedFields = '[]';
		}

		$entity->setRequestedFields($encodedFields);
		$entity->setStatus(SecretRequest::STATUS_PENDING);
		$entity->setIsReRequest($isReRequest);
		$entity->setExpiresAt($expiresAt);
		$entity->setCreatedBy($userId);
		$entity->setCreatedAt(new DateTime());

		$persisted = $this->mapper->insert($entity);

		// Re-requests dispatch their own REQUEST_RE_REQUESTED event from
		// createReRequest(); a plain create dispatches REQUEST_CREATED.
		if ($isReRequest === false) {
			$this->outbox->recordCreated(userId: $userId, requestId: $persisted->getId());
		}

		return $persisted;
	}//end create()

	/**
	 * Create a FRESH request in the requester's own vault, creating the Secret.
	 *
	 * This is the path a person takes when they have nothing to point at yet.
	 * The spec has always required it — "the system MUST create an unfilled
	 * Secret and a SecretRequest" — but the only surface that existed demanded a
	 * pre-existing Secret, and `create()` refused an empty key, so a requester
	 * had to invent a value for the credential they were about to ask for.
	 *
	 * Mirrors `ApplicationSecretRequestService::createForApplicationVault()`,
	 * including its rollback: the shell exists only to receive this request, so
	 * it must not survive a failed creation.
	 *
	 * A RE-REQUEST does not come through here. It targets an existing Secret and
	 * keeps using `create()` / `createReRequest()`, which is what keeps the two
	 * flows structurally distinct rather than distinguished by a boolean.
	 *
	 * @param string $userId The Nextcloud user creating the request
	 * @param array<string> $requestedFields Field names to be filled in
	 * @param string|null $name Name for the created Secret
	 * @param string|null $folderId Optional folder to file it under
	 * @param DateTime|null $expiresAt Optional expiry
	 *
	 * @return SecretRequest
	 *
	 * @throws InvalidArgumentException When userId or requestedFields are missing
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-create-secret-request
	 */
	public function createForUserVault(
		string $userId,
		array $requestedFields,
		?string $name = null,
		?string $folderId = null,
		?DateTime $expiresAt = null,
	): SecretRequest {
		if ($userId === '') {
			throw new InvalidArgumentException(message: 'userId is required');
		}

		if ($requestedFields === []) {
			throw new InvalidArgumentException(message: 'requestedFields cannot be empty');
		}

		$secretService = $this->container->get(SecretService::class);

		$shell = $secretService->create(
			data: [
				'name' => ($name ?? 'Unfilled request'),
				'folderId' => $folderId,
				// Keyless by design: the recipient supplies the value and the
				// server never holds a plaintext one (ADR-003). Permitted only
				// because a pending request will target this Secret.
				'key' => '',
			],
			userId: $userId,
			allowUnfilled: true
		);

		try {
			return $this->create(
				secretId: $shell->getId(),
				// Read off the Secret the system just created rather than taken
				// from a caller parameter: SecretService linked the shell to the
				// user's active suite, so re-deriving it here cannot drift from
				// the Secret the values will actually be written to.
				encryptionSuiteId: (string)$shell->getEncryptionSuiteId(),
				requestedFields: $requestedFields,
				isReRequest: false,
				expiresAt: $expiresAt,
				userId: $userId
			);
		} catch (Throwable $exception) {
			// No orphan shell: it holds no value and exists only for this
			// request. Rollback failure must not mask the original error.
			try {
				$secretService->delete($shell->getId(), $userId);
			} catch (Throwable $cleanup) {
				$this->logger->error(
					'Doriath: could not roll back the request placeholder: ' . $cleanup->getMessage(),
					['exception' => $cleanup]
				);
			}

			throw $exception;
		}//end try
	}//end createForUserVault()

	/**
	 * Create a new pending secret request keyed to an application.
	 *
	 * Resolves the application's active EncryptionSuite through the policy
	 * so the caller does not need to know the suite ID. Enforces the same
	 * invariants as `create()` plus an explicit application-active check
	 * (no requests for pending or rejected applications).
	 *
	 * @param string $secretId The Secret ID
	 * @param string $applicationId The recipient application ID
	 * @param array<string> $requestedFields Field names to be filled in
	 * @param DateTime|null $expiresAt Optional expiry
	 * @param string $userId The Nextcloud user creating the request
	 *
	 * @return SecretRequest
	 *
	 * @throws InvalidArgumentException When the application has no active suite
	 *
	 * @spec openspec/changes/implement-secret-requests/tasks.md#task-3.3
	 */
	public function createForApplication(
		string $secretId,
		string $applicationId,
		array $requestedFields,
		?DateTime $expiresAt,
		string $userId,
	): SecretRequest {
		$suiteId = $this->policy->requireApplicationSuiteId(applicationId: $applicationId);

		return $this->create(
			secretId: $secretId,
			encryptionSuiteId: $suiteId,
			requestedFields: $requestedFields,
			isReRequest: false,
			expiresAt: $expiresAt,
			userId: $userId,
		);
	}//end createForApplication()

	/**
	 * Create a re-request for an existing secret.
	 *
	 * A re-request renews the access to a Secret whose recipient lost
	 * the plaintext (rotation, recovery, etc). Guards:
	 *   - the Secret must already exist (lookup via SecretMapper);
	 *   - no other pending request may be open for the same Secret;
	 *   - the caller must own the Secret being re-requested.
	 *
	 * The EncryptionSuite is reused from the Secret's current suite ID
	 * (the recipient encrypts under their existing key material).
	 *
	 * @param string $secretId The Secret ID
	 * @param array<string> $requestedFields Field names to be re-filled
	 * @param DateTime|null $expiresAt Optional expiry
	 * @param string $userId The Nextcloud user creating the re-request
	 *
	 * @return SecretRequest
	 *
	 * @throws InvalidArgumentException When the Secret is missing, a pending request exists,
	 *                                  or the caller is not the owner.
	 *
	 * @spec openspec/changes/implement-secret-requests/tasks.md#task-3.4
	 */
	public function createReRequest(
		string $secretId,
		array $requestedFields,
		?DateTime $expiresAt,
		string $userId,
	): SecretRequest {
		$secret = $this->policy->requireReRequestableSecret(secretId: $secretId, userId: $userId);
		$this->policy->requireNoPendingRequest(secretId: $secretId);

		$persisted = $this->create(
			secretId: $secretId,
			encryptionSuiteId: $secret->getEncryptionSuiteId(),
			requestedFields: $requestedFields,
			isReRequest: true,
			expiresAt: $expiresAt,
			userId: $userId,
		);

		$this->outbox->recordReRequested(userId: $userId, requestId: $persisted->getId());

		return $persisted;
	}//end createReRequest()

	/**
	 * Approve (mark fulfilled) a pending secret request.
	 *
	 * The caller (controller) is responsible for the encryption-blob writes
	 * to the linked Secret row before flipping the status. This method
	 * only enforces the lifecycle transition and the expiry/ownership
	 * checks.
	 *
	 * @param string $requestId The request ID
	 * @param string $userId The Nextcloud user approving the request
	 *
	 * @return SecretRequest
	 *
	 * @throws InvalidArgumentException
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-write-once
	 */
	public function approve(string $requestId, string $userId): SecretRequest {
		$entity = $this->policy->requireOwnRequest(requestId: $requestId, userId: $userId);

		if ($entity->getStatus() !== SecretRequest::STATUS_PENDING) {
			throw new InvalidArgumentException(message: 'Request is not pending');
		}

		if ($entity->isExpired() === true) {
			throw new InvalidArgumentException(message: 'Request has expired');
		}

		$entity->setStatus(SecretRequest::STATUS_FULFILLED);
		$entity->setFulfilledAt(new DateTime());

		$this->logger->info(
			'Approved secret request ' . $requestId . ' for secret ' . $entity->getSecretId(),
			['app' => 'doriath']
		);

		return $this->mapper->update($entity);
	}//end approve()

	/**
	 * Decline a pending secret request.
	 *
	 * @param string $requestId The request ID
	 * @param string $userId The Nextcloud user declining the request
	 *
	 * @return SecretRequest
	 *
	 * @throws InvalidArgumentException
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.5
	 */
	public function decline(string $requestId, string $userId): SecretRequest {
		$entity = $this->policy->requireOwnRequest(requestId: $requestId, userId: $userId);

		if ($entity->getStatus() !== SecretRequest::STATUS_PENDING) {
			throw new InvalidArgumentException(message: 'Request is not pending');
		}

		$entity->setStatus(SecretRequest::STATUS_DECLINED);

		$this->logger->info(
			'Declined secret request ' . $requestId,
			['app' => 'doriath']
		);

		$updated = $this->mapper->update($entity);

		// Revoking releases the only justification a keyless Secret has for
		// existing, so the placeholder goes with it — "the unfilled Secret MUST
		// be deleted" (Revoke Request). This was never implemented; it only
		// becomes reachable now that a fresh request creates its own Secret.
		//
		// The discriminator is EMPTINESS, not `isReRequest`. A plain request also
		// carries `isReRequest === false` while targeting a Secret the USER
		// picked, so deleting on that flag would destroy real credentials. A
		// Secret holding no value is worthless to keep; one holding a value is
		// never ours to remove here.
		//
		// After the status flip on purpose: if this cleanup fails, the request is
		// already revoked and the worst case is an orphan empty Secret. The other
		// order risks deleting a Secret while its request stays pending.
		$this->placeholderCleaner->removeIfUnfilled(request: $updated, expectedOwnerType: 'user', expectedOwnerId: $userId);

		$this->outbox->recordRevoked(userId: $userId, requestId: $requestId);

		return $updated;
	}//end decline()

	/**
	 * Transition a lapsed request to the terminal `expired` status.
	 *
	 * Called by the sweeper, never by a person, so there is no ownership check to
	 * make here — the caller has already selected rows by `expires_at`. What this
	 * does enforce is that only a PENDING request can lapse: a fulfilled or
	 * declined request has already reached its end state, and re-terminating it
	 * would rewrite history.
	 *
	 * Cleanup matches revoke exactly, because the keyless-placeholder invariant
	 * does not care why the request ended: an unfilled placeholder goes, a
	 * re-request's Secret and its values stay. Reusing the same helper is what
	 * keeps the two paths from drifting.
	 *
	 * @param SecretRequest $request The lapsed request
	 *
	 * @return SecretRequest|null The updated request, or null when it was not pending
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-optional-expiry
	 */
	public function expire(SecretRequest $request): ?SecretRequest {
		// Fast path only. The entity may have been loaded a whole batch ago, so this
		// check proves nothing on its own — the conditional write below is the
		// guarantee.
		if ($request->getStatus() !== SecretRequest::STATUS_PENDING) {
			return null;
		}

		// Conditional transition, not read-then-write. `QBMapper::update()` issues
		// `WHERE id = ?` with no status guard, so a recipient filling the request
		// between the job's SELECT and this write had `fulfilled` replaced by
		// `expired` — with `fulfilled_at` left set, so the requester was told their
		// request lapsed while the credential sat in their vault. If the row is no
		// longer pending we did nothing, and nothing downstream may run: no
		// placeholder delete, no audit event claiming an expiry that never happened.
		$transitioned = $this->mapper->transitionIfPending(
			requestId: $request->getId(),
			toStatus: SecretRequest::STATUS_EXPIRED
		);
		if ($transitioned === false) {
			return null;
		}

		// The write succeeded, so mirror it onto the in-memory entity for the caller
		// and for the cleanup below. Re-reading the row would only cost a query.
		$request->setStatus(SecretRequest::STATUS_EXPIRED);

		$this->placeholderCleaner->removeIfUnfilled(request: $request, expectedOwnerType: null, expectedOwnerId: null);

		$this->outbox->recordExpired(requestId: $request->getId());

		$this->logger->info(
			'Expired secret request ' . $request->getId(),
			['app' => 'doriath']
		);

		return $request;
	}//end expire()

	/**
	 * List secret requests created by a given user.
	 *
	 * @param string $userId The Nextcloud user ID
	 *
	 * @return SecretRequest[]
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-create-secret-request
	 */
	public function listByUser(string $userId): array {
		return $this->mapper->findByCreatedBy($userId);
	}//end listByUser()

	/**
	 * List all secret requests for a given Secret — visible only to the
	 * Secret owner. Used by the Secret detail sidebar to render the
	 * "Requests" history block.
	 *
	 * @param string $secretId The Secret ID
	 * @param string $userId The requesting Nextcloud user ID
	 *
	 * @return SecretRequest[]
	 *
	 * @throws InvalidArgumentException When the Secret does not exist or
	 *                                  the caller is not its owner.
	 *
	 * @spec openspec/changes/implement-secret-requests/tasks.md#task-3.8
	 */
	public function listBySecret(string $secretId, string $userId): array {
		$this->policy->requireListableSecret(secretId: $secretId, userId: $userId);

		return $this->mapper->findBySecretId($secretId);
	}//end listBySecret()

	/**
	 * Cascade-delete all secret requests for a Secret.
	 *
	 * @param string $secretId The Secret ID
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-revoke-request
	 */
	public function deleteAllForSecret(string $secretId): void {
		$this->mapper->deleteBySecretId($secretId);
	}//end deleteAllForSecret()
}//end class
