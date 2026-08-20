<?php

/**
 * Doriath Link Share Service
 *
 * Business logic for password-protected link shares: creation, public
 * access (two-phase protocol), usage-limit enforcement, brute-force
 * protection, manual revocation, and cascade deletion.
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
use OCA\Doriath\Db\LinkShare;
use OCA\Doriath\Db\LinkShareMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Business logic for link share lifecycle.
 */
class LinkShareService {
	/**
	 * Minimum allowed usage limit.
	 *
	 * @var int
	 */
	public const MIN_USAGE_LIMIT = 1;

	/**
	 * Maximum allowed usage limit. No unlimited option is permitted.
	 *
	 * @var int
	 */
	public const MAX_USAGE_LIMIT = 10;

	/**
	 * Number of consecutive failed attempts after which a link share is
	 * permanently deleted.
	 *
	 * @var int
	 */
	public const MAX_FAILED_ATTEMPTS = 5;

	/**
	 * The audit trail of the link-share lifecycle.
	 *
	 * @var LinkShareAuditTrail
	 */
	private LinkShareAuditTrail $auditTrail;

	/**
	 * Constructor for LinkShareService.
	 *
	 * @param LinkShareMapper $mapper The link share mapper
	 * @param LoggerInterface $logger The logger interface
	 * @param WriteLockService $writeLockService The compromise-recovery write lock
	 * @param LinkShareAuditTrail|null $auditTrail The link-share audit trail
	 *
	 * @return void
	 */
	public function __construct(
		private LinkShareMapper $mapper,
		private LoggerInterface $logger,
		private WriteLockService $writeLockService,
		?LinkShareAuditTrail $auditTrail = null,
	) {
		$this->auditTrail = ($auditTrail ?? new LinkShareAuditTrail());
	}//end __construct()

	/**
	 * Create a link share for a secret owned by the given user.
	 *
	 * The caller (controller) is responsible for confirming the user owns
	 * the secret and for resolving the user's active encryption suite ID.
	 * Ownership of the resulting link share is recorded in created_by, which
	 * is the sole authority used by delete()/listBySecret() — this keeps the
	 * feature self-contained and IDOR-safe.
	 *
	 * @param string $secretId The secret ID the snapshot is taken from
	 * @param string $encryptedSnapshot The AES-256-GCM encrypted snapshot blob (base64)
	 * @param string $salt The base64-encoded Argon2id salt
	 * @param string $encryptionSuiteId The active encryption suite ID at creation
	 * @param int $usageLimit The usage limit (1-10)
	 * @param DateTime|null $expiresAt Optional expiry timestamp
	 * @param string $userId The owning Nextcloud user ID
	 *
	 * @return LinkShare
	 *
	 * @throws InvalidArgumentException When validation fails
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.4
	 */
	public function create(
		string $secretId,
		string $encryptedSnapshot,
		string $salt,
		string $encryptionSuiteId,
		int $usageLimit,
		?DateTime $expiresAt,
		string $userId,
	): LinkShare {
		// A link share bakes an encrypted snapshot against a specific suite, and
		// completing a migration cascade-revokes every link share anyway. One
		// created mid-migration would be destroyed moments later, so refuse it
		// rather than take the user's time.
		$this->writeLockService->assertNotWriteLocked(ownerId: $userId);

		if ($secretId === '' || $encryptedSnapshot === '' || $salt === '') {
			throw new InvalidArgumentException('Missing required link share fields');
		}

		if ($usageLimit < self::MIN_USAGE_LIMIT || $usageLimit > self::MAX_USAGE_LIMIT) {
			throw new InvalidArgumentException(
				'Usage limit must be between ' . self::MIN_USAGE_LIMIT . ' and ' . self::MAX_USAGE_LIMIT
			);
		}

		$linkShare = new LinkShare();
		$linkShare->setId(Uuid::uuid4()->toString());
		$linkShare->setSecretId($secretId);
		$linkShare->setToken($this->generateToken());
		$linkShare->setEncryptedSecretSnapshot($encryptedSnapshot);
		$linkShare->setArgon2idSalt($salt);
		$linkShare->setEncryptionSuiteId($encryptionSuiteId);
		$linkShare->setUsageLimit($usageLimit);
		$linkShare->setUsageCount(0);
		$linkShare->setFailedAttempts(0);
		$linkShare->setCreatedBy($userId);
		$linkShare->setCreatedAt(new DateTime());
		$linkShare->setExpiresAt($expiresAt);

		$this->mapper->insert($linkShare);

		$this->logger->info("Doriath: link share created for secret {$secretId} by {$userId}");

		$expiresAtIso = null;
		if ($expiresAt !== null) {
			$expiresAtIso = $expiresAt->format(DateTime::ATOM);
		}

		$this->auditTrail->recordCreated(
			userId: $userId,
			linkShareId: $linkShare->getId(),
			// Always true here: the guard above throws when $salt === '', so a
			// link share cannot reach this point without one.
			hasPassword: true,
			expiresAtIso: $expiresAtIso,
		);

		return $linkShare;
	}//end create()

	/**
	 * Fetch a valid link share by token for public access (Phase 1).
	 *
	 * Validates that the token exists, has not expired, has not exhausted
	 * its usage limit, and has not been brute-force deleted. Expired link
	 * shares are deleted on access. A NotFoundException-equivalent
	 * (RuntimeException) is thrown on every failure case so the controller
	 * can return a uniform 404 and prevent token enumeration.
	 *
	 * @param string $token The access token
	 *
	 * @return LinkShare
	 *
	 * @throws RuntimeException When the token is invalid, expired, or exhausted
	 */
	public function getByToken(string $token): LinkShare {
		try {
			$linkShare = $this->mapper->findByToken($token);
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			throw new RuntimeException('Link not found or expired');
		}

		$expiresAt = $linkShare->getExpiresAt();
		if ($expiresAt !== null && $expiresAt < new DateTime()) {
			$this->mapper->delete($linkShare);
			throw new RuntimeException('Link not found or expired');
		}

		if ($linkShare->getUsageCount() >= $linkShare->getUsageLimit()) {
			$this->mapper->delete($linkShare);
			throw new RuntimeException('Link not found or expired');
		}

		if ($linkShare->getFailedAttempts() >= self::MAX_FAILED_ATTEMPTS) {
			$this->mapper->delete($linkShare);
			throw new RuntimeException('Link not found or expired');
		}

		return $linkShare;
	}//end getByToken()

	/**
	 * Confirm a successful client-side decryption (Phase 2).
	 *
	 * Atomically increments the usage count (only while below the limit),
	 * resets failed_attempts to 0, and deletes the link share when the
	 * usage limit is reached. The atomic update guards against the
	 * concurrent-tab race where two confirms could exceed the limit.
	 *
	 * @param string $token The access token
	 *
	 * @return LinkShare The updated (or, when exhausted, last-known) link share
	 *
	 * @throws RuntimeException When the token is invalid or the limit was already reached
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.4
	 */
	public function confirmAccess(string $token): LinkShare {
		$affected = $this->mapper->incrementUsageIfBelowLimit($token);
		if ($affected === 0) {
			// Either the token does not exist or the limit was already reached.
			throw new RuntimeException('Link not found or expired');
		}

		$linkShare = $this->mapper->findByToken($token);

		$this->auditTrail->recordAccessed(linkShareId: $linkShare->getId());

		if ($linkShare->getUsageCount() >= $linkShare->getUsageLimit()) {
			$this->mapper->delete($linkShare);
			$this->logger->info("Doriath: link share {$linkShare->getId()} auto-deleted (usage limit reached)");
		}

		return $linkShare;
	}//end confirmAccess()

	/**
	 * Record a failed password attempt for a token (Phase 1 brute-force tracking).
	 *
	 * Increments failed_attempts and permanently deletes the link share when
	 * the configured threshold is reached.
	 *
	 * @param string $token The access token
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.4
	 */
	public function recordFailedAttempt(string $token): void {
		try {
			$linkShare = $this->mapper->findByToken($token);
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			// Nothing to record against a non-existent token.
			return;
		}

		$linkShare->setFailedAttempts($linkShare->getFailedAttempts() + 1);

		if ($linkShare->getFailedAttempts() >= self::MAX_FAILED_ATTEMPTS) {
			$this->mapper->delete($linkShare);
			$this->logger->warning(
				"Doriath: link share {$linkShare->getId()} permanently deleted after "
				. self::MAX_FAILED_ATTEMPTS . ' failed attempts'
			);

			$this->auditTrail->recordAccessFailed(linkShareId: $linkShare->getId());
			$this->auditTrail->recordAutoDeleted(linkShareId: $linkShare->getId());
			return;
		}//end if

		$this->mapper->update($linkShare);

		$this->auditTrail->recordAccessFailed(linkShareId: $linkShare->getId());
	}//end recordFailedAttempt()

	/**
	 * List link shares for a secret, scoped to the requesting owner.
	 *
	 * Only returns link shares the requesting user created (IDOR-safe).
	 *
	 * @param string $secretId The secret ID
	 * @param string $userId The requesting Nextcloud user ID
	 *
	 * @return LinkShare[]
	 */
	public function listBySecret(string $secretId, string $userId): array {
		$shares = $this->mapper->findBySecretId($secretId);

		return array_values(
			array_filter(
				$shares,
				static fn (LinkShare $share): bool => $share->getCreatedBy() === $userId
			)
		);
	}//end listBySecret()

	/**
	 * Delete (revoke) a link share, validating ownership.
	 *
	 * @param string $id The link share ID
	 * @param string $userId The requesting Nextcloud user ID
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the link share does not exist
	 * @throws InvalidArgumentException When the requester is not the owner
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.4
	 */
	public function delete(string $id, string $userId): void {
		try {
			$linkShare = $this->mapper->findById($id);
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			throw new RuntimeException('Link share not found');
		}

		if ($linkShare->getCreatedBy() !== $userId) {
			throw new InvalidArgumentException('Access denied: link share belongs to another user');
		}

		$this->mapper->delete($linkShare);
		$this->logger->info("Doriath: link share {$id} revoked by {$userId}");

		$this->auditTrail->recordRevoked(userId: $userId, linkShareId: $id);
	}//end delete()

	/**
	 * Cascade-delete all link shares for a secret (called on secret deletion).
	 *
	 * @param string $secretId The secret ID
	 *
	 * @return void
	 */
	public function deleteBySecretId(string $secretId): void {
		$this->mapper->deleteBySecretId($secretId);
		$this->logger->info("Doriath: cascade-deleted link shares for secret {$secretId}");
	}//end deleteBySecretId()

	/**
	 * Cascade-delete all link shares for a user (called on compromise recovery).
	 *
	 * @param string $userId The Nextcloud user ID
	 *
	 * @return void
	 */
	public function deleteByUserId(string $userId): void {
		$this->mapper->deleteByUserId($userId);
		$this->logger->info("Doriath: cascade-deleted link shares for user {$userId}");
	}//end deleteByUserId()

	/**
	 * Generate a URL-safe access token with 128 bits of entropy.
	 *
	 * @return string A 32-character lowercase hexadecimal string
	 */
	private function generateToken(): string {
		return bin2hex(random_bytes(16));
	}//end generateToken()
}//end class
