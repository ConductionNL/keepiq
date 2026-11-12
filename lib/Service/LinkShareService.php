<?php

/**
 * Keepiq Link Share Service
 *
 * Business logic for password-protected link shares: creation, public
 * access (two-phase protocol), usage-limit enforcement, brute-force
 * protection, manual revocation, and cascade deletion.
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

use DateTime;
use InvalidArgumentException;
use OCA\Keepiq\Db\LinkShare;
use OCA\Keepiq\Db\LinkShareMapper;
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
	 * @param ShareAuthorizationService $shareAuth Who may re-share a secret (keepiq#214)
	 * @param LinkShareAuditTrail|null $auditTrail The link-share audit trail
	 *
	 * @return void
	 */
	public function __construct(
		private LinkShareMapper $mapper,
		private LoggerInterface $logger,
		private WriteLockService $writeLockService,
		private ShareAuthorizationService $shareAuth,
		?LinkShareAuditTrail $auditTrail = null,
	) {
		$this->auditTrail = ($auditTrail ?? new LinkShareAuditTrail());
	}//end __construct()

	/**
	 * Create a link share for a secret the given user may re-share.
	 *
	 * A public link widens the audience beyond what the owner chose, so only
	 * the secret's owner, or a recipient whose share permits re-sharing (an
	 * active delegate, who holds share management rights), may create one
	 * (keepiq#214). Anyone else gets the same DoesNotExistException as a missing
	 * secret (from ShareAuthorizationService::assertMayReshare()), so the
	 * check does not reveal which secrets exist. The controller
	 * resolves the user's active encryption suite ID.
	 * Ownership of the resulting link share is recorded in created_by, which
	 * is the sole authority used by delete()/listBySecret() — this keeps the
	 * feature self-contained and IDOR-safe.
	 *
	 * @param NewLinkShare $link The secret, snapshot, salt, suite, limit, expiry and creator
	 *
	 * @return LinkShare
	 *
	 * @throws InvalidArgumentException When validation fails
	 * @throws DoesNotExistException When the user may not re-share the secret
	 * @throws RuntimeException A ForbiddenException when the secret is a read-only copy from another organisation,
	 *                          or a ShareScanPendingException (409) when the share row naming the secret, or the
	 *                          caller's copy of it, waits for the share quarantine scan
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.4
	 * @spec openspec/specs/link-sharing/spec.md#requirement-who-may-create-a-link-share
	 */
	public function create(NewLinkShare $link): LinkShare {
		// A link share bakes an encrypted snapshot against a specific suite, and
		// completing a migration cascade-revokes every link share anyway. One
		// created mid-migration would be destroyed moments later, so refuse it
		// rather than take the user's time.
		$this->writeLockService->assertNotWriteLocked(ownerId: $link->userId);

		$link->assertValid();

		$this->shareAuth->assertMayReshare(secretId: $link->secretId, userId: $link->userId);

		$linkShare = $link->toRow(token: $this->generateToken());
		$this->mapper->insert($linkShare);

		$this->logger->info("Keepiq: link share created for secret {$link->secretId} by {$link->userId}");

		$this->auditCreated(link: $link, linkShare: $linkShare);

		return $linkShare;
	}//end create()

	/**
	 * Audit a created link share with its expiry.
	 *
	 * @param NewLinkShare $link The request it was created from
	 * @param LinkShare $linkShare The stored link share
	 *
	 * @return void
	 */
	private function auditCreated(NewLinkShare $link, LinkShare $linkShare): void {
		$expiresAtIso = null;
		if ($link->expiresAt !== null) {
			$expiresAtIso = $link->expiresAt->format(DateTime::ATOM);
		}

		$this->auditTrail->recordCreated(
			userId: $link->userId,
			linkShareId: $linkShare->getId(),
			// Always true here: the guard in NewLinkShare::assertValid throws when
			// the salt is empty, so a link share cannot reach this point without one.
			hasPassword: true,
			expiresAtIso: $expiresAtIso,
		);
	}//end auditCreated()

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
	 *
	 * @spec openspec/specs/link-sharing/spec.md#requirement-access-via-link
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
	 * Serve the encrypted snapshot for a token (Phase 1), counting the serve
	 * before the snapshot leaves the server.
	 *
	 * Every serve consumes one use and one attempt, atomically and only
	 * while uses and attempts remain, so the usage limit and the attempt cap
	 * hold whether or not the client ever confirms. The share is deleted as
	 * soon as the serve that reaches either one has been read.
	 *
	 * @param string $token The access token
	 *
	 * @return array<string, mixed> The public payload
	 *
	 * @throws RuntimeException When the token is invalid, expired, used up or out of attempts
	 *
	 * @spec openspec/specs/link-sharing/spec.md#requirement-access-via-link
	 * @spec openspec/specs/link-sharing/spec.md#requirement-auto-deletion
	 */
	public function serve(string $token): array {
		$this->getByToken(token: $token);

		if ($this->mapper->countServeIfAllowed($token, self::MAX_FAILED_ATTEMPTS) === 0) {
			throw new RuntimeException('Link not found or expired');
		}

		$linkShare = $this->mapper->findByToken($token);
		$payload = $linkShare->jsonSerializePublic();
		$this->auditTrail->recordAccessed(linkShareId: $linkShare->getId());

		if ($linkShare->getUsageCount() >= $linkShare->getUsageLimit()) {
			$this->mapper->delete($linkShare);
			$this->logger->info("Keepiq: link share {$linkShare->getId()} auto-deleted (usage limit reached)");
			return $payload;
		}

		if ($linkShare->getFailedAttempts() >= self::MAX_FAILED_ATTEMPTS) {
			$this->mapper->delete($linkShare);
			$this->auditTrail->recordAutoDeleted(linkShareId: $linkShare->getId());
			$this->logger->warning(
				"Keepiq: link share {$linkShare->getId()} permanently deleted after "
				. self::MAX_FAILED_ATTEMPTS . ' unconfirmed attempts'
			);
		}

		return $payload;
	}//end serve()

	/**
	 * Confirm a successful client-side decryption (Phase 2).
	 *
	 * The use was already counted when the snapshot was served; a confirm
	 * only resets the attempts, so a recipient who decrypted is not pushed
	 * towards the attempt cap by later uses.
	 *
	 * @param string $token The access token
	 *
	 * @return LinkShare The link share
	 *
	 * @throws RuntimeException When the token is invalid or the share is gone
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.4
	 */
	public function confirmAccess(string $token): LinkShare {
		try {
			$linkShare = $this->mapper->findByToken($token);
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			throw new RuntimeException('Link not found or expired');
		}

		$this->mapper->resetFailedAttempts($token);
		$linkShare->setFailedAttempts(0);

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
				"Keepiq: link share {$linkShare->getId()} permanently deleted after "
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
	 *
	 * @spec openspec/specs/link-sharing/spec.md#requirement-multiple-concurrent-link-shares
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
		$this->logger->info("Keepiq: link share {$id} revoked by {$userId}");

		$this->auditTrail->recordRevoked(userId: $userId, linkShareId: $id);
	}//end delete()

	/**
	 * Cascade-delete all link shares for a secret (called on secret deletion).
	 *
	 * @param string $secretId The secret ID
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-delete-secret
	 */
	public function deleteBySecretId(string $secretId): void {
		$this->mapper->deleteBySecretId($secretId);
		$this->logger->info("Keepiq: cascade-deleted link shares for secret {$secretId}");
	}//end deleteBySecretId()

	/**
	 * Cascade-delete all link shares a user made (called on compromise
	 * recovery), also the ones an ownership transfer handed to someone else.
	 *
	 * @param string $userId The Nextcloud user ID
	 *
	 * @return void
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	public function deleteMadeBy(string $userId): void {
		$this->mapper->deleteMadeBy($userId);
		$this->logger->info("Keepiq: cascade-deleted link shares made by user {$userId}");
	}//end deleteMadeBy()

	/**
	 * Generate a URL-safe access token with 128 bits of entropy.
	 *
	 * @return string A 32-character lowercase hexadecimal string
	 */
	private function generateToken(): string {
		return bin2hex(random_bytes(16));
	}//end generateToken()
}//end class
