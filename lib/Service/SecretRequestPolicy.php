<?php

/**
 * Keepiq Secret Request Policy
 *
 * The precondition and authorization surface of the secret-request
 * lifecycle. Every "may this happen / is this request still open" decision
 * lives here: the token state machine that distinguishes locked, expired,
 * fulfilled and unknown for the public fill page, the requested-field
 * completeness rule, the creator-only rule on approve/decline, the
 * owner-only rules on re-request and listing, and the one-pending-request
 * -per-secret invariant. Keeping them in one class is what lets the
 * lifecycle service stay a description of state transitions.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
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

namespace OCA\Keepiq\Service;

use InvalidArgumentException;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretRequest;
use OCA\Keepiq\Db\SecretRequestMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use RuntimeException;

/**
 * Preconditions and authorization for the SecretRequest lifecycle.
 */
class SecretRequestPolicy {
	/**
	 * Requested names mapping to an encrypted Secret column.
	 *
	 * @var array<int,string>
	 */
	public const ENCRYPTED_FIELDS = ['key', 'login'];

	/**
	 * Requested names mapping to a plaintext (searchable) Secret column.
	 *
	 * @var array<int,string>
	 */
	public const PLAINTEXT_FIELDS = ['url'];

	/**
	 * The single encrypted blob carrying every additional member.
	 *
	 * @var string
	 */
	public const ADDITIONAL_BLOB = 'additionalFields';

	/**
	 * Machine-readable reasons a fill link can be refused.
	 *
	 * The recipient is a stranger with no account, and the fill page is the only
	 * thing they ever see. It has to tell them WHICH of these happened, in their
	 * own language. A human-readable message cannot do that: it is composed here,
	 * server-side, in English, and it is the one string the recipient reads.
	 *
	 * Measured before adding this, on a real expired request: the page rendered
	 * "Request has expired" — this file's English, not the translated
	 * `This request has expired.` that SecretRequestFill.vue already carried. That
	 * translation was unreachable, because the client had nothing but prose to
	 * switch on.
	 *
	 * @var array<int,string>
	 */
	public const REASONS = [
		self::REASON_NOT_FOUND,
		self::REASON_EXPIRED,
		self::REASON_FULFILLED,
		self::REASON_DECLINED,
		self::REASON_LOCKED,
		self::REASON_UNKNOWN,
	];

	/**
	 * No request carries this token.
	 *
	 * @var string
	 */
	public const REASON_NOT_FOUND = 'not-found';

	/**
	 * The request's expiry has passed, or it was swept to `expired`.
	 *
	 * @var string
	 */
	public const REASON_EXPIRED = 'expired';

	/**
	 * Somebody already filled this request.
	 *
	 * @var string
	 */
	public const REASON_FULFILLED = 'fulfilled';

	/**
	 * The requester revoked this request.
	 *
	 * @var string
	 */
	public const REASON_DECLINED = 'declined';

	/**
	 * A compromise recovery is in progress on the target vault.
	 *
	 * @var string
	 */
	public const REASON_LOCKED = 'locked';

	/**
	 * A status this version does not know how to explain.
	 *
	 * @var string
	 */
	public const REASON_UNKNOWN = 'unknown';

	/**
	 * The request is open and may be filled.
	 *
	 * @var string
	 */
	private const REASON_OPEN = 'open';

	/**
	 * The message and HTTP code each refusal answers with.
	 *
	 * Kept as data next to the classifier rather than as arms of a switch, so the
	 * reason a caller is told and the reason the client is handed can never
	 * disagree. Two predicates answering the same question separately is how the
	 * revoke path came to delete filled secrets earlier in this same feature.
	 *
	 * @var array<string,array{message:string,code:int}>
	 */
	private const REFUSALS = [
		self::REASON_NOT_FOUND => ['message' => 'Request not found', 'code' => 404],
		self::REASON_EXPIRED => ['message' => 'Request has expired', 'code' => 408],
		self::REASON_FULFILLED => ['message' => 'Request was already fulfilled', 'code' => 410],
		self::REASON_DECLINED => ['message' => 'Request was declined', 'code' => 410],
		self::REASON_LOCKED => ['message' => 'Request is temporarily unavailable', 'code' => 423],
		self::REASON_UNKNOWN => ['message' => 'Request is in an unknown state', 'code' => 500],
	];

	/**
	 * Constructor for SecretRequestPolicy.
	 *
	 * @param SecretRequestMapper $mapper The request mapper
	 * @param SecretMapper|null $secretMapper Optional Secret mapper for owner lookups
	 * @param EncryptionSuiteMapper|null $suiteMapper Optional suite mapper
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only — no domain logic.
	 */
	public function __construct(
		private SecretRequestMapper $mapper,
		private ?SecretMapper $secretMapper = null,
		private ?EncryptionSuiteMapper $suiteMapper = null,
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
	public function requireOpenByToken(string $token): SecretRequest {
		if ($token === '') {
			throw new InvalidArgumentException(message: 'token is required', code: 400);
		}

		try {
			$entity = $this->mapper->findByToken($token);
		} catch (DoesNotExistException) {
			throw new InvalidArgumentException(message: 'Request not found', code: 404);
		}

		$reason = $this->classify(entity: $entity);
		if ($reason === self::REASON_OPEN) {
			return $entity;
		}

		throw new InvalidArgumentException(
			message: self::REFUSALS[$reason]['message'],
			code: self::REFUSALS[$reason]['code']
		);
	}//end requireOpenByToken()

	/**
	 * Why a token cannot be filled, as a machine-readable reason.
	 *
	 * Exists so the public fill page can render the refusal in the recipient's
	 * language. It returns the SAME classification `requireOpenByToken()` refuses
	 * on, by calling the same classifier, so the reason shown can never contradict
	 * the status returned.
	 *
	 * Discloses nothing the refusal did not already disclose: the English message
	 * on the error response says "already fulfilled" or "expired" in words. This
	 * hands the client the same fact in a form it can translate.
	 *
	 * @param string $token The access token
	 *
	 * @return string One of self::REASONS, or REASON_NOT_FOUND for an empty token
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-fill-in-via-link
	 */
	public function refusalReason(string $token): string {
		if ($token === '') {
			return self::REASON_NOT_FOUND;
		}

		try {
			$entity = $this->mapper->findByToken($token);
		} catch (DoesNotExistException) {
			return self::REASON_NOT_FOUND;
		}

		$reason = $this->classify(entity: $entity);

		// An open request has no refusal to explain. Answering "unknown" rather
		// than "open" keeps this method from becoming a status oracle for a live
		// token: a caller learns only that there is nothing to report.
		if ($reason === self::REASON_OPEN) {
			return self::REASON_UNKNOWN;
		}

		return $reason;
	}//end refusalReason()

	/**
	 * Classify a request as open, or as the reason it is refused.
	 *
	 * Expiry is evaluated FIRST, independently of the stored status. Being precise
	 * about what that buys: a lapsed PENDING request was already refused before
	 * this was hoisted, because the pending branch checked expiry itself. Hoisting
	 * changes PRECEDENCE for every other status — a locked request whose expiry
	 * passed now reports "expired" instead of "temporarily unavailable", which is
	 * the truer answer, since locked invites the recipient to retry and an expired
	 * request never can be. It also means a status added later cannot bypass expiry
	 * by omission. The sweeper remains cleanup; enforcement is here.
	 *
	 * `expired` keeps an arm of its own even though the expiry check above catches
	 * every request the sweeper produces — measured: all expired rows carry a past
	 * `expires_at`, necessarily, since that is what the sweeper selects on. The arm
	 * is the fallback for a request whose status says expired while its timestamp
	 * does not, which without it would answer 500 'unknown state'. It answers with
	 * the same reason and therefore the same 408 as the timestamp path: one
	 * condition, one code.
	 *
	 * @param SecretRequest $entity The request to classify
	 *
	 * @return string REASON_OPEN, or the reason it is refused
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-fill-in-via-link
	 */
	private function classify(SecretRequest $entity): string {
		if ($entity->isExpired() === true) {
			return self::REASON_EXPIRED;
		}

		return match ($entity->getStatus()) {
			SecretRequest::STATUS_LOCKED => self::REASON_LOCKED,
			SecretRequest::STATUS_FULFILLED => self::REASON_FULFILLED,
			SecretRequest::STATUS_DECLINED => self::REASON_DECLINED,
			SecretRequest::STATUS_EXPIRED => self::REASON_EXPIRED,
			SecretRequest::STATUS_PENDING => self::REASON_OPEN,
			default => self::REASON_UNKNOWN,
		};
	}//end classify()

	/**
	 * Re-read a request by ID and assert it is still pending. Used by the
	 * fill flow to defend against a parallel fill that raced the token
	 * lookup.
	 *
	 * @param string $requestId The request ID
	 *
	 * @return SecretRequest
	 *
	 * @throws InvalidArgumentException With code 404 (gone) or 409 (not pending).
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-write-once
	 */
	public function requirePendingById(string $requestId): SecretRequest {
		try {
			$current = $this->mapper->findById($requestId);
		} catch (DoesNotExistException) {
			throw new InvalidArgumentException(message: 'Request not found', code: 404);
		}

		if ($current->getStatus() !== SecretRequest::STATUS_PENDING) {
			throw new InvalidArgumentException(message: 'Request is not pending', code: 409);
		}

		return $current;
	}//end requirePendingById()

	/**
	 * Every field the request asked for must arrive as a non-empty string.
	 *
	 * @param SecretRequest $entity The request being filled
	 * @param array<string,mixed> $encryptedFields The client-encrypted values
	 * @param array<string,mixed> $plainFields Plaintext metadata bucket (url)
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a requested field is absent or empty.
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-field-validation
	 */
	public function requireAllRequestedFields(
		SecretRequest $entity,
		array $encryptedFields,
		array $plainFields = [],
	): void {
		$requested = json_decode(json: $entity->getRequestedFields(), associative: true);
		if (is_array($requested) === false) {
			return;
		}

		foreach ($requested as $field) {
			// `url` is plaintext metadata and arrives in its own bucket; the two
			// ciphertext columns arrive in the encrypted one. Anything else is an
			// additional member, which lives INSIDE the single encrypted blob —
			// so the most that can be checked is that the blob arrived. Looking
			// for the member by name would require decrypting it, which the
			// server never does (ADR-003), so per-member completeness is a
			// client-side concern and is documented as such in the spec.
			$bucket = $encryptedFields;
			$lookFor = $field;

			if (in_array($field, self::PLAINTEXT_FIELDS, true) === true) {
				$bucket = $plainFields;
			} elseif (in_array($field, self::ENCRYPTED_FIELDS, true) === false) {
				$lookFor = self::ADDITIONAL_BLOB;
			}

			if (array_key_exists($lookFor, $bucket) === false) {
				throw new InvalidArgumentException(message: 'Missing required field: ' . $field, code: 400);
			}

			$value = $bucket[$lookFor];
			if (is_string($value) === false || $value === '') {
				throw new InvalidArgumentException(message: 'Empty value for field: ' . $field, code: 400);
			}
		}
	}//end requireAllRequestedFields()

	/**
	 * Look up a request and verify the caller is its creator.
	 *
	 * @param string $requestId The request ID
	 * @param string $userId The Nextcloud user
	 *
	 * @return SecretRequest
	 *
	 * @throws InvalidArgumentException When the request is missing or the
	 *                                  caller did not create it.
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-revoke-request
	 */
	public function requireOwnRequest(string $requestId, string $userId): SecretRequest {
		try {
			$entity = $this->mapper->findById($requestId);
		} catch (DoesNotExistException) {
			throw new InvalidArgumentException(message: 'Request not found');
		}

		if ($entity->getCreatedBy() !== $userId) {
			throw new InvalidArgumentException(message: 'Not authorized for this request');
		}

		return $entity;
	}//end requireOwnRequest()

	/**
	 * The Secret a re-request may renew: it must exist and the caller must
	 * be its owner.
	 *
	 * @param string $secretId The Secret ID
	 * @param string $userId The Nextcloud user creating the re-request
	 *
	 * @return Secret
	 *
	 * @throws InvalidArgumentException When the Secret is missing or the
	 *                                  caller is not the owner.
	 * @throws RuntimeException When the Secret mapper dependency is not wired.
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-re-request-update-in-place
	 */
	public function requireReRequestableSecret(string $secretId, string $userId): Secret {
		if ($secretId === '') {
			throw new InvalidArgumentException(message: 'secretId is required');
		}

		if ($this->secretMapper === null) {
			throw new RuntimeException(message: 'Secret mapper not wired for re-requests');
		}

		try {
			$secret = $this->secretMapper->findById($secretId);
		} catch (DoesNotExistException) {
			throw new InvalidArgumentException(message: 'Secret not found', code: 404);
		}

		if ($secret->getOwnerId() !== $userId) {
			throw new InvalidArgumentException(message: 'Only the secret owner may create a re-request', code: 403);
		}

		return $secret;
	}//end requireReRequestableSecret()

	/**
	 * Assert the caller may see the request history of a Secret — the
	 * Secret owner only.
	 *
	 * @param string $secretId The Secret ID
	 * @param string $userId The requesting Nextcloud user ID
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the ownership lookup is
	 *                                  unavailable, the Secret does not
	 *                                  exist, or the caller is not its owner.
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-create-secret-request
	 */
	public function requireListableSecret(string $secretId, string $userId): void {
		if ($this->secretMapper === null) {
			// Defensive: the bind is optional only to preserve test-mock
			// call sites that do not exercise this path. When invoked
			// without the mapper, refuse rather than skip the ownership
			// check (fail closed).
			throw new InvalidArgumentException(message: 'Ownership lookup unavailable');
		}

		try {
			$secret = $this->secretMapper->findById($secretId);
		} catch (DoesNotExistException) {
			throw new InvalidArgumentException(message: 'Secret not found');
		}

		if ($secret->getOwnerType() !== 'user' || $secret->getOwnerId() !== $userId) {
			throw new InvalidArgumentException(message: 'Not authorized for this secret');
		}
	}//end requireListableSecret()

	/**
	 * Resolve the active EncryptionSuite of an application. An application
	 * without an active suite cannot receive a request (no pending or
	 * rejected applications).
	 *
	 * @param string $applicationId The recipient application ID
	 *
	 * @return string The active EncryptionSuite ID
	 *
	 * @throws InvalidArgumentException When the application ID is blank or
	 *                                  it has no active suite.
	 * @throws RuntimeException When the suite mapper dependency is not wired.
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-create-secret-request
	 */
	public function requireApplicationSuiteId(string $applicationId): string {
		if ($applicationId === '') {
			throw new InvalidArgumentException(message: 'applicationId is required');
		}

		if ($this->suiteMapper === null) {
			throw new RuntimeException(message: 'EncryptionSuite mapper not wired for application requests');
		}

		try {
			$suite = $this->suiteMapper->findActiveByOwner('application', $applicationId);
			// The mapper bounds this query with maxResults(1), so findEntity()
			// can never see two rows and MultipleObjectsReturnedException is
			// unreachable here.
		} catch (DoesNotExistException) {
			throw new InvalidArgumentException(
				message: 'No active EncryptionSuite for application ' . $applicationId,
				code: 400,
			);
		}

		return $suite->getId();
	}//end requireApplicationSuiteId()

	/**
	 * Reject when a pending request is already open for the Secret —
	 * fence-posts both double-submits and the spec invariant "one pending
	 * request per secret at a time".
	 *
	 * @param string $secretId The Secret ID
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a pending request already exists.
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-re-request-update-in-place
	 */
	public function requireNoPendingRequest(string $secretId): void {
		try {
			$this->mapper->findPendingBySecretId($secretId);
			throw new InvalidArgumentException(
				message: 'A pending request already exists for this secret',
				code: 409,
			);
		} catch (DoesNotExistException) {
			// Expected — no pending request, continue.
		}
	}//end requireNoPendingRequest()
}//end class
