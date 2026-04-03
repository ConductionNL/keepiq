<?php

/**
 * Doriath Share Service
 *
 * Business logic for user-level secret sharing: create, revoke, batch create and sync.
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
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\SecretShare;
use OCA\Doriath\Db\SecretShareMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Business logic for user-level secret sharing.
 */
class ShareService
{
    /**
     * Constructor for ShareService.
     *
     * @param SecretShareMapper     $shareMapper           The secret share mapper
     * @param SecretMapper          $secretMapper          The secret mapper
     * @param EncryptionSuiteMapper $encryptionSuiteMapper The encryption suite mapper
     * @param LoggerInterface       $logger                The logger interface
     *
     * @return void
     */
    public function __construct(
        private SecretShareMapper $shareMapper,
        private SecretMapper $secretMapper,
        private EncryptionSuiteMapper $encryptionSuiteMapper,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Create a share of a secret for a target user.
     *
     * Validates that the caller owns the source secret, that the recipient has an
     * active encryption suite, then creates a Secret copy for the recipient and a
     * SecretShare record linking the copy to the source.
     *
     * @param string              $sourceSecretId The ID of the secret to share
     * @param string              $targetUserId   The Nextcloud user ID of the recipient
     * @param array<string,mixed> $encryptedData  Encrypted fields: key, login, additionalFields, name, url, typeId
     * @param string              $userId         The caller's Nextcloud user ID
     *
     * @return SecretShare
     *
     * @throws InvalidArgumentException  When the caller does not own the source secret
     * @throws DoesNotExistException     When the recipient has no active encryption suite
     */
    public function createShare(
        string $sourceSecretId,
        string $targetUserId,
        array $encryptedData,
        string $userId,
    ): SecretShare {
        $sourceSecret = $this->secretMapper->findById(id: $sourceSecretId);

        if ($sourceSecret->getOwnerId() !== $userId) {
            throw new InvalidArgumentException('You do not own this secret');
        }

        // Validate the recipient has an active suite.
        $recipientSuite = $this->encryptionSuiteMapper->findActiveByOwner(
            ownerType: 'user',
            ownerId: $targetUserId
        );

        // Create the per-recipient Secret copy.
        $copy = new Secret();
        $copy->setId(Uuid::uuid4()->toString());
        $copy->setName($encryptedData['name'] ?? $sourceSecret->getName());
        $copy->setUrl($encryptedData['url'] ?? $sourceSecret->getUrl());
        $copy->setTypeId($encryptedData['typeId'] ?? $sourceSecret->getTypeId());
        $copy->setKey($encryptedData['key'] ?? null);
        $copy->setLogin($encryptedData['login'] ?? null);
        $copy->setAdditionalFields($encryptedData['additionalFields'] ?? null);
        $copy->setEncryptionSuiteId($recipientSuite->getId());
        $copy->setOwnerType('user');
        $copy->setOwnerId($targetUserId);
        $copy->setCreatedAt(new DateTime());
        $copy->setUpdatedAt(new DateTime());

        $this->secretMapper->insert($copy);

        // Create the share record.
        $share = new SecretShare();
        $share->setId(Uuid::uuid4()->toString());
        $share->setSourceSecretId($sourceSecretId);
        $share->setTargetUserId($targetUserId);
        $share->setSecretId($copy->getId());
        $share->setCreatedAt(new DateTime());

        $this->shareMapper->insert($share);

        $this->logger->info(
            "Doriath: Secret {$sourceSecretId} shared with user {$targetUserId} by {$userId}"
        );

        return $share;
    }//end createShare()

    /**
     * Revoke a share by deleting the recipient's Secret copy and the SecretShare record.
     *
     * @param string $shareId The share ID to revoke
     * @param string $userId  The caller's Nextcloud user ID (must own the source secret)
     *
     * @return void
     *
     * @throws InvalidArgumentException When the caller does not own the source secret
     * @throws DoesNotExistException    When the share does not exist
     */
    public function revokeShare(string $shareId, string $userId): void
    {
        $share        = $this->shareMapper->findById(id: $shareId);
        $sourceSecret = $this->secretMapper->findById(id: $share->getSourceSecretId());

        if ($sourceSecret->getOwnerId() !== $userId) {
            throw new InvalidArgumentException('You do not own this secret');
        }

        // Delete the recipient's Secret copy.
        try {
            $copy = $this->secretMapper->findById(id: $share->getSecretId());
            $this->secretMapper->delete($copy);
        } catch (DoesNotExistException) {
            // Copy already gone — continue to clean up the share record.
            $this->logger->warning(
                "Doriath: Secret copy {$share->getSecretId()} not found during share revocation {$shareId}"
            );
        }

        $this->shareMapper->delete($share);

        $this->logger->info("Doriath: Share {$shareId} revoked by {$userId}");
    }//end revokeShare()

    /**
     * Return all shares for a given source secret.
     *
     * @param string $sourceSecretId The source secret ID
     * @param string $userId         The caller's Nextcloud user ID (must own the source secret)
     *
     * @return SecretShare[]
     *
     * @throws InvalidArgumentException When the caller does not own the source secret
     * @throws DoesNotExistException    When the secret does not exist
     */
    public function getSharesForSecret(string $sourceSecretId, string $userId): array
    {
        $sourceSecret = $this->secretMapper->findById(id: $sourceSecretId);

        if ($sourceSecret->getOwnerId() !== $userId) {
            throw new InvalidArgumentException('You do not own this secret');
        }

        return $this->shareMapper->findBySourceSecret(sourceSecretId: $sourceSecretId);
    }//end getSharesForSecret()

    /**
     * Sync updated encrypted fields for a batch of shared Secret copies.
     *
     * For each entry in $updates the matching Secret is located by ID, its encrypted
     * fields are replaced, and the possiblyCompromisedAt flag is cleared if set.
     *
     * @param string                         $secretId The source secret ID (used for logging only)
     * @param array<int,array<string,mixed>> $updates  Array of {secretId, key, login, additionalFields}
     * @param string                         $userId   The caller's Nextcloud user ID
     *
     * @return void
     */
    public function syncUpdate(string $secretId, array $updates, string $userId): void
    {
        foreach ($updates as $update) {
            try {
                $secret = $this->secretMapper->findById(id: $update['secretId']);

                $secret->setKey($update['key'] ?? $secret->getKey());
                $secret->setLogin($update['login'] ?? $secret->getLogin());
                $secret->setAdditionalFields(
                    $update['additionalFields'] ?? $secret->getAdditionalFields()
                );

                if ($secret->getPossiblyCompromisedAt() !== null) {
                    $secret->setPossiblyCompromisedAt(null);
                }

                $secret->setUpdatedAt(new DateTime());

                $this->secretMapper->update($secret);
            } catch (DoesNotExistException) {
                $this->logger->warning(
                    "Doriath: syncUpdate — Secret {$update['secretId']} not found, skipping"
                );
            }//end try
        }//end foreach

        $this->logger->info(
            "Doriath: syncUpdate for source secret {$secretId} processed "
            .count($updates)." entries by {$userId}"
        );
    }//end syncUpdate()

    /**
     * Create multiple shares in a single batch operation.
     *
     * Each entry in $shares must contain: targetUserId and encrypted fields (key,
     * login, additionalFields, name, url, typeId).
     *
     * @param string                         $sourceSecretId The source secret ID
     * @param array<int,array<string,mixed>> $shares         Array of share definitions
     * @param string|null                    $groupShareId   Optional group share ID to attach to each share
     * @param string                         $userId         The caller's Nextcloud user ID
     *
     * @return SecretShare[]
     *
     * @throws InvalidArgumentException When the caller does not own the source secret
     * @throws DoesNotExistException    When the source secret does not exist
     */
    public function createBatchShares(
        string $sourceSecretId,
        array $shares,
        ?string $groupShareId,
        string $userId,
    ): array {
        $sourceSecret = $this->secretMapper->findById(id: $sourceSecretId);

        if ($sourceSecret->getOwnerId() !== $userId) {
            throw new InvalidArgumentException('You do not own this secret');
        }

        $created = [];

        foreach ($shares as $shareData) {
            $targetUserId = $shareData['targetUserId'];

            try {
                $recipientSuite = $this->encryptionSuiteMapper->findActiveByOwner(
                    ownerType: 'user',
                    ownerId: $targetUserId
                );
            } catch (DoesNotExistException) {
                $this->logger->warning(
                    "Doriath: createBatchShares — user {$targetUserId} has no active suite, skipping"
                );
                continue;
            }

            $copy = new Secret();
            $copy->setId(Uuid::uuid4()->toString());
            $copy->setName($shareData['name'] ?? $sourceSecret->getName());
            $copy->setUrl($shareData['url'] ?? $sourceSecret->getUrl());
            $copy->setTypeId($shareData['typeId'] ?? $sourceSecret->getTypeId());
            $copy->setKey($shareData['key'] ?? null);
            $copy->setLogin($shareData['login'] ?? null);
            $copy->setAdditionalFields($shareData['additionalFields'] ?? null);
            $copy->setEncryptionSuiteId($recipientSuite->getId());
            $copy->setOwnerType('user');
            $copy->setOwnerId($targetUserId);
            $copy->setCreatedAt(new DateTime());
            $copy->setUpdatedAt(new DateTime());

            $this->secretMapper->insert($copy);

            $share = new SecretShare();
            $share->setId(Uuid::uuid4()->toString());
            $share->setSourceSecretId($sourceSecretId);
            $share->setTargetUserId($targetUserId);
            $share->setSecretId($copy->getId());
            $share->setGroupShareId($groupShareId);
            $share->setCreatedAt(new DateTime());

            $this->shareMapper->insert($share);

            $created[] = $share;
        }//end foreach

        $this->logger->info(
            "Doriath: createBatchShares — ".count($created)." shares created for secret {$sourceSecretId} by {$userId}"
        );

        return $created;
    }//end createBatchShares()
}//end class
