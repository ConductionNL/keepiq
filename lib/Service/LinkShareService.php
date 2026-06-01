<?php

/**
 * Doriath Link Share Service
 *
 * Business logic for password-protected link shares: creation with an
 * Argon2id-encrypted snapshot, the two-phase public access protocol
 * (fetch blob, confirm decryption), usage-limit enforcement,
 * brute-force protection and cascade deletion.
 *
 * The link password and the Argon2id-derived AES key never reach this
 * service — the browser performs all KDF and AES work. The server only
 * stores the encrypted blob plus the salt and enforces the counters.
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

/**
 * Business logic for link shares.
 */
class LinkShareService
{
    /**
     * The minimum allowed usage limit.
     *
     * @var int
     */
    private const USAGE_LIMIT_MIN = 1;

    /**
     * The maximum allowed usage limit.
     *
     * @var int
     */
    private const USAGE_LIMIT_MAX = 10;

    /**
     * The number of consecutive failed attempts that triggers deletion.
     *
     * @var int
     */
    private const MAX_FAILED_ATTEMPTS = 5;

    /**
     * Constructor for LinkShareService.
     *
     * @param LinkShareMapper        $mapper       The link share mapper
     * @param EncryptionSuiteService $suiteService The encryption suite service
     * @param LoggerInterface        $logger       The logger interface
     *
     * @return void
     */
    public function __construct(
        private LinkShareMapper $mapper,
        private EncryptionSuiteService $suiteService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Create a link share for a secret owned by the given user.
     *
     * @param string        $secretId          The secret being shared
     * @param string        $encryptedSnapshot The base64 AES-256-GCM blob
     * @param string        $salt              The base64 Argon2id salt
     * @param int           $usageLimit        The usage limit (1-10)
     * @param DateTime|null $expiresAt         Optional expiry
     * @param string        $userId            The owning Nextcloud user ID
     *
     * @return LinkShare
     *
     * @throws InvalidArgumentException When usage_limit is out of range or inputs are empty
     */
    public function create(
        string $secretId,
        string $encryptedSnapshot,
        string $salt,
        int $usageLimit,
        ?DateTime $expiresAt,
        string $userId,
    ): LinkShare {
        if ($secretId === '') {
            throw new InvalidArgumentException('A secret ID is required to create a link share');
        }

        if ($encryptedSnapshot === '' || $salt === '') {
            throw new InvalidArgumentException('An encrypted snapshot and salt are required');
        }

        if ($usageLimit < self::USAGE_LIMIT_MIN || $usageLimit > self::USAGE_LIMIT_MAX) {
            throw new InvalidArgumentException(
                'Usage limit must be between '.self::USAGE_LIMIT_MIN.' and '.self::USAGE_LIMIT_MAX
            );
        }

        // Record the encryption suite that was active for the owner at
        // creation time so the snapshot's provenance is auditable.
        $suiteId = $this->suiteService->getActiveSuite(ownerType: 'user', ownerId: $userId)->getId();

        $linkShare = new LinkShare();
        $linkShare->setId(Uuid::uuid4()->toString());
        $linkShare->setSecretId($secretId);
        $linkShare->setToken(bin2hex(random_bytes(16)));
        $linkShare->setEncryptedSecretSnapshot($encryptedSnapshot);
        $linkShare->setArgon2idSalt($salt);
        $linkShare->setEncryptionSuiteId($suiteId);
        $linkShare->setUsageLimit($usageLimit);
        $linkShare->setUsageCount(0);
        $linkShare->setFailedAttempts(0);
        $linkShare->setBlobFetched(false);
        $linkShare->setCreatedBy($userId);
        $linkShare->setCreatedAt(new DateTime());
        $linkShare->setExpiresAt($expiresAt);

        $created = $this->mapper->insert($linkShare);
        $this->logger->info('Doriath: link share created for secret '.$secretId.' by '.$userId);

        return $created;
    }//end create()

    /**
     * Fetch a link share by token for public access (Phase 1).
     *
     * Validates that the token exists, has not expired, is below its usage
     * limit and below the brute-force threshold. When the blob has already
     * been fetched once without a following successful confirmation, this
     * call increments `failed_attempts` first; reaching the threshold
     * permanently deletes the link share. All failure modes raise
     * DoesNotExistException so the controller can return a uniform 404.
     *
     * @param string $token The access token
     *
     * @return LinkShare
     *
     * @throws DoesNotExistException When the token is invalid, expired, exhausted or deleted
     */
    public function getByToken(string $token): LinkShare
    {
        try {
            $linkShare = $this->mapper->findByToken($token);
        } catch (DoesNotExistException | MultipleObjectsReturnedException) {
            throw new DoesNotExistException('Link not found or expired');
        }

        // Expired links are deleted and reported as not found.
        $expiresAt = $linkShare->getExpiresAt();
        if ($expiresAt !== null && $expiresAt < new DateTime()) {
            $this->mapper->delete($linkShare);
            throw new DoesNotExistException('Link not found or expired');
        }

        if ($linkShare->getUsageCount() >= $linkShare->getUsageLimit()) {
            throw new DoesNotExistException('Link not found or expired');
        }

        if ($linkShare->getFailedAttempts() >= self::MAX_FAILED_ATTEMPTS) {
            throw new DoesNotExistException('Link not found or expired');
        }

        // A second (or later) blob fetch without an intervening successful
        // confirmation counts as a failed attempt. The first fetch does not.
        if ($linkShare->getBlobFetched() === true) {
            $linkShare->setFailedAttempts($linkShare->getFailedAttempts() + 1);
            if ($linkShare->getFailedAttempts() >= self::MAX_FAILED_ATTEMPTS) {
                $this->mapper->delete($linkShare);
                $this->logger->info('Doriath: link share deleted after brute-force threshold reached');
                throw new DoesNotExistException('Link not found or expired');
            }
        }

        $linkShare->setBlobFetched(true);
        $this->mapper->update($linkShare);

        return $linkShare;
    }//end getByToken()

    /**
     * Confirm a successful decryption (Phase 2).
     *
     * Atomically increments usage_count only when below the limit, resets
     * failed_attempts and the blob-fetched flag, and deletes the link
     * share when the usage limit is reached.
     *
     * @param string $token The access token
     *
     * @return LinkShare
     *
     * @throws DoesNotExistException When the token is invalid or already exhausted
     */
    public function confirmAccess(string $token): LinkShare
    {
        try {
            $linkShare = $this->mapper->findByToken($token);
        } catch (DoesNotExistException | MultipleObjectsReturnedException) {
            throw new DoesNotExistException('Link not found or expired');
        }

        $affected = $this->mapper->incrementUsageCountIfBelowLimit($linkShare->getId());
        if ($affected === 0) {
            // The limit was already reached concurrently; treat as not found.
            throw new DoesNotExistException('Link not found or expired');
        }

        $refreshed = $this->mapper->findById($linkShare->getId());

        if ($refreshed->getUsageCount() >= $refreshed->getUsageLimit()) {
            $this->mapper->delete($refreshed);
            $this->logger->info('Doriath: link share deleted after reaching usage limit');
        }

        return $refreshed;
    }//end confirmAccess()

    /**
     * Record a failed access attempt for a token.
     *
     * Increments failed_attempts and permanently deletes the link share
     * once the brute-force threshold is reached.
     *
     * @param string $token The access token
     *
     * @return void
     */
    public function recordFailedAttempt(string $token): void
    {
        try {
            $linkShare = $this->mapper->findByToken($token);
        } catch (DoesNotExistException | MultipleObjectsReturnedException) {
            return;
        }

        $linkShare->setFailedAttempts($linkShare->getFailedAttempts() + 1);

        if ($linkShare->getFailedAttempts() >= self::MAX_FAILED_ATTEMPTS) {
            $this->mapper->delete($linkShare);
            $this->logger->info('Doriath: link share deleted after brute-force threshold reached');
            return;
        }

        $this->mapper->update($linkShare);
    }//end recordFailedAttempt()

    /**
     * List the link shares for a secret, restricted to the owner.
     *
     * Returns only link shares the requesting user created (no blobs are
     * returned — that is handled by the entity's jsonSerialize()).
     *
     * @param string $secretId The secret ID
     * @param string $userId   The requesting Nextcloud user ID
     *
     * @return LinkShare[]
     */
    public function listBySecret(string $secretId, string $userId): array
    {
        $shares = $this->mapper->findBySecretId($secretId);

        return array_values(
            array_filter(
                $shares,
                static fn (LinkShare $share): bool => $share->getCreatedBy() === $userId
            )
        );
    }//end listBySecret()

    /**
     * Delete (revoke) a link share owned by the requesting user.
     *
     * @param string $id     The link share ID
     * @param string $userId The requesting Nextcloud user ID
     *
     * @return void
     *
     * @throws DoesNotExistException When the link share does not exist
     * @throws InvalidArgumentException When the requesting user is not the owner
     */
    public function delete(string $id, string $userId): void
    {
        try {
            $linkShare = $this->mapper->findById($id);
        } catch (DoesNotExistException | MultipleObjectsReturnedException) {
            throw new DoesNotExistException('Link share not found');
        }

        if ($linkShare->getCreatedBy() !== $userId) {
            throw new InvalidArgumentException('Access denied: link share belongs to another user');
        }

        $this->mapper->delete($linkShare);
    }//end delete()

    /**
     * Delete all link shares for a secret (cascade on secret deletion).
     *
     * @param string $secretId The secret ID
     *
     * @return void
     */
    public function deleteBySecretId(string $secretId): void
    {
        $this->mapper->deleteBySecretId($secretId);
    }//end deleteBySecretId()

    /**
     * Delete all link shares created by a user (cascade on compromise recovery).
     *
     * @param string $userId The Nextcloud user ID
     *
     * @return void
     */
    public function deleteByUserId(string $userId): void
    {
        $this->mapper->deleteByUserId($userId);
    }//end deleteByUserId()
}//end class
