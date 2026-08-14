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
	 * Create a request in an application's OWN vault, with no user session.
	 *
	 * The session-bound siblings (`create`, `createForApplication`) both require
	 * a `userId`, which an unattended application does not have — that is the
	 * gap this closes. Authority comes from the caller having already proven the
	 * application's identity (JWT-Bearer on the machine route, or a verified
	 * signed proof on the DI seam); this method takes the application id as
	 * established fact and never accepts a user.
	 *
	 * The Secret shell is created here rather than demanded from the caller,
	 * because an application has nothing to point at yet. Shell and request are
	 * created together, and a failure after the shell is written removes it: an
	 * empty orphan Secret in an application's vault is indistinguishable from a
	 * real unfilled credential, so it must not survive a failed creation.
	 *
	 * @param string $applicationId The application whose vault receives the request
	 * @param array<int,string> $requestedFields Field names being asked for
	 * @param string|null $name Optional secret name
	 * @param string|null $folderId Optional folder placement
	 * @param DateTime|null $expiresAt Optional fill-link expiry
	 *
	 * @return SecretRequest
	 *
	 * @throws InvalidArgumentException When the application has no usable suite
	 *
	 * @spec openspec/changes/application-secret-request-creation/specs/secret-requests/spec.md#requirement-session-less-application-initiated-request-creation
	 */
	public function createForApplicationVault(
		string $applicationId,
		array $requestedFields,
		?string $name = null,
		?string $folderId = null,
		?DateTime $expiresAt = null,
	): SecretRequest {
		if ($requestedFields === []) {
			throw new InvalidArgumentException(message: 'requestedFields is required', code: 400);
		}

		// Guard parity with token issuance: refuses a pending/rejected/deleted
		// application and a revoked/compromised suite, and yields the suite the
		// submitted values will be encrypted under.
		$suiteId = $this->policy->requireApplicationSuiteId(applicationId: $applicationId);

		// The application's own lock, not a user's: a rotation in the
		// application's vault must refuse new pending requests for the same
		// reason it refuses them for a user.
		$this->writeLockService->assertNotWriteLocked(
			ownerId: $applicationId,
			ownerType: 'application'
		);

		$secretService = $this->container->get(SecretService::class);

		$shell = $secretService->createByApplication(
			data: [
				'name' => ($name ?? 'Unfilled request'),
				'folderId' => $folderId,
				// An unfilled shell: the recipient supplies the value, and the
				// server never holds a plaintext one (ADR-003).
				'key' => '',
			],
			applicationId: $applicationId
		);

		try {
			return $this->createInApplicationVault(
				secretId: $shell->getId(),
				applicationId: $applicationId,
				encryptionSuiteId: $suiteId,
				requestedFields: $requestedFields,
				expiresAt: $expiresAt
			);
		} catch (Throwable $exception) {
			// No orphan shell: it exists only to receive this request.
			try {
				$secretService->deleteByApplication(
					secretId: $shell->getId(),
					applicationId: $applicationId
				);
			} catch (Throwable $cleanupFailure) {
				$this->logger->error(
					'Doriath: failed to remove the shell of a failed application request',
					[
						'secretId' => $shell->getId(),
						'applicationId' => $applicationId,
						'exception' => $cleanupFailure,
					]
				);
			}

			throw $exception;
		}//end try
	}//end createForApplicationVault()

	/**
	 * Persist an application-owned request row.
	 *
	 * Split from the shell creation so the rollback above has exactly one
	 * failure point to guard, and kept separate from `create()` because that
	 * method's contract is user-session bound: it takes a `userId`, locks
	 * against a user's vault and records that user as the creator.
	 *
	 * @param string $secretId The shell Secret
	 * @param string $applicationId The owning application
	 * @param string $encryptionSuiteId The suite values will be encrypted under
	 * @param array<int,string> $requestedFields Field names being asked for
	 * @param DateTime|null $expiresAt Optional fill-link expiry
	 *
	 * @return SecretRequest
	 *
	 * @spec openspec/changes/application-secret-request-creation/specs/secret-requests/spec.md#requirement-session-less-application-initiated-request-creation
	 */
	private function createInApplicationVault(
		string $secretId,
		string $applicationId,
		string $encryptionSuiteId,
		array $requestedFields,
		?DateTime $expiresAt,
	): SecretRequest {
		$entity = new SecretRequest();
		$entity->setId(Uuid::uuid4()->toString());
		$entity->setSecretId($secretId);
		$entity->setEncryptionSuiteId($encryptionSuiteId);
		$entity->setRequestedFields((string)json_encode(array_values($requestedFields)));
		$entity->setStatus(SecretRequest::STATUS_PENDING);
		$entity->setIsReRequest(false);
		$entity->setExpiresAt($expiresAt);
		// The actor is the application, not a user. `created_by` is a plain
		// string column, so the prefix keeps an application id from being read
		// as a Nextcloud user id by anything that consumes this field.
		$entity->setCreatedBy('application:' . $applicationId);
		$entity->setCreatedAt(new DateTime());
		$entity->setToken(bin2hex(random_bytes(16)));

		$persisted = $this->mapper->insert($entity);

		$this->logger->info(
			'Created application-initiated secret request ' . $persisted->getId(),
			['app' => 'doriath', 'applicationId' => $applicationId]
		);

		return $persisted;
	}//end createInApplicationVault()

	/**
	 * The pending requests an application itself created.
	 *
	 * Exists so a fill-link is retrievable after creation: the token is returned
	 * once at creation, and an application that lost the response has no other
	 * way back to it.
	 *
	 * Scoping is structural rather than filtered-after-the-fact. Rows are keyed
	 * on `created_by = "application:<id>"`, a value only this service writes —
	 * no request body can influence it, so one application cannot enumerate
	 * another's. Requests a USER created against this application's vault are
	 * deliberately not listed: they are the user's to manage, and surfacing them
	 * here would widen what a machine token can see.
	 *
	 * @param string $applicationId The calling application
	 *
	 * @return array<int,SecretRequest> Pending requests, newest first
	 *
	 * @spec openspec/changes/application-secret-request-creation/specs/secret-requests/spec.md#requirement-session-less-application-initiated-request-creation
	 */
	public function listPendingForApplicationVault(string $applicationId): array {
		if ($applicationId === '') {
			throw new InvalidArgumentException(message: 'applicationId is required', code: 400);
		}

		$rows = $this->mapper->findByCreatedBy('application:' . $applicationId);

		$pending = array_values(
			array_filter(
				$rows,
				static fn (SecretRequest $row): bool => $row->getStatus() === SecretRequest::STATUS_PENDING
			)
		);

		usort(
			$pending,
			static fn (SecretRequest $a, SecretRequest $b): int => ($b->getCreatedAt()?->getTimestamp() ?? 0)
				<=> ($a->getCreatedAt()?->getTimestamp() ?? 0)
		);

		return $pending;
	}//end listPendingForApplicationVault()

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

		$this->outbox->recordRevoked(userId: $userId, requestId: $requestId);

		return $updated;
	}//end decline()

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
