<?php

/**
 * Doriath Share Service
 *
 * Server-side orchestration of user-to-user secret sharing: creating
 * encrypted-copy shares, revoking shares, sync-on-update propagation,
 * share-visibility rules and share requests. All plaintext encryption is
 * performed client-side (ADR-003); this service only persists opaque blobs.
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
use OCA\Doriath\Db\SecretShare;
use OCA\Doriath\Db\SecretShareMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Business logic for user-to-user secret sharing.
 */
class ShareService
{
    /**
     * Constructor for ShareService.
     *
     * @param SecretShareMapper       $shareMapper         The secret share mapper
     * @param SecretOwnershipResolver $ownership           The ownership resolver
     * @param SecretCopyGateway       $copyGateway         The secret copy gateway
     * @param NotificationService     $notificationService The notification service
     * @param IDBConnection           $db                  The database connection
     *
     * @return void
     */
    public function __construct(
        private SecretShareMapper $shareMapper,
        private SecretOwnershipResolver $ownership,
        private SecretCopyGateway $copyGateway,
        private NotificationService $notificationService,
        private IDBConnection $db,
    ) {
    }//end __construct()

    /**
     * Create a share: persist the recipient's encrypted copy and the share link.
     *
     * @param string              $sourceSecretId The original secret ID
     * @param string              $targetUserId   The recipient user ID
     * @param array<string,mixed> $encryptedData  Client-encrypted blobs + metadata
     * @param string              $userId         The acting user (owner or delegate)
     * @param string|null         $groupShareId   Owning group share ID, if any
     *
     * @return SecretShare
     *
     * @throws InvalidArgumentException When sharing with self
     * @throws RuntimeException         When unauthorized or recipient has no suite
     */
    public function createShare(
        string $sourceSecretId,
        string $targetUserId,
        array $encryptedData,
        string $userId,
        ?string $groupShareId=null,
    ): SecretShare {
        if ($targetUserId === $userId) {
            throw new InvalidArgumentException('A user cannot share a secret with themselves');
        }

        if ($this->ownership->canManageShares($sourceSecretId, $userId) === false) {
            throw new RuntimeException('Not authorized to share this secret');
        }

        $suite = $this->ownership->getActiveSuiteForUser($targetUserId);
        if ($suite === null) {
            throw new RuntimeException('Recipient has no active encryption suite');
        }

        $existing = $this->shareMapper->findBySourceSecretAndTargetUser($sourceSecretId, $targetUserId);
        if ($existing !== null) {
            throw new InvalidArgumentException('Secret is already shared with this user');
        }

        $copyId = $this->copyGateway->createCopy($targetUserId, $suite->getId(), $encryptedData);

        $share = new SecretShare();
        $share->setId(Uuid::uuid4()->toString());
        $share->setSourceSecretId($sourceSecretId);
        $share->setTargetUserId($targetUserId);
        $share->setSecretId($copyId);
        $share->setGroupShareId($groupShareId);
        $share->setCreatedAt(new DateTime());
        $share = $this->shareMapper->insert($share);

        $this->notificationService->notify(
            'secret_shared',
            $targetUserId,
            [
                'actorId'    => $userId,
                'secretName' => (string) ($encryptedData['name'] ?? ''),
            ],
            $sourceSecretId
        );

        return $share;
    }//end createShare()

    /**
     * Create several shares for a group expansion within one transaction.
     *
     * @param string                         $sourceSecretId The original secret ID
     * @param array<int,array<string,mixed>> $shares         List of {targetUserId, encryptedData}
     * @param string                         $groupShareId   The owning group share ID
     * @param string                         $userId         The acting user
     *
     * @return SecretShare[]
     */
    public function createBatchShares(
        string $sourceSecretId,
        array $shares,
        string $groupShareId,
        string $userId,
    ): array {
        if ($this->ownership->canManageShares($sourceSecretId, $userId) === false) {
            throw new RuntimeException('Not authorized to share this secret');
        }

        $created = [];
        $this->db->beginTransaction();
        try {
            foreach ($shares as $share) {
                $targetUserId = (string) ($share['targetUserId'] ?? '');
                if ($targetUserId === '' || $targetUserId === $userId) {
                    continue;
                }

                if ($this->ownership->getActiveSuiteForUser($targetUserId) === null) {
                    continue;
                }

                if ($this->shareMapper->findBySourceSecretAndTargetUser($sourceSecretId, $targetUserId) !== null) {
                    continue;
                }

                $created[] = $this->createShare(
                    sourceSecretId: $sourceSecretId,
                    targetUserId: $targetUserId,
                    encryptedData: (array) ($share['encryptedData'] ?? []),
                    userId: $userId,
                    groupShareId: $groupShareId
                );
            }//end foreach

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }//end try

        return $created;
    }//end createBatchShares()

    /**
     * Revoke a share: delete the recipient's copy and the share record.
     *
     * @param string $shareId The share ID
     * @param string $userId  The acting user (owner or delegate)
     *
     * @return void
     *
     * @throws RuntimeException When unauthorized
     */
    public function revokeShare(string $shareId, string $userId): void
    {
        try {
            $share = $this->shareMapper->findById($shareId);
        } catch (DoesNotExistException $e) {
            throw new RuntimeException('Share not found');
        }

        if ($this->ownership->canManageShares($share->getSourceSecretId(), $userId) === false) {
            throw new RuntimeException('Not authorized to revoke this share');
        }

        $this->copyGateway->deleteCopy((string) $share->getSecretId());
        $this->shareMapper->delete($share);
    }//end revokeShare()

    /**
     * Return the recipient list for a secret — owner/delegate only.
     *
     * Regular recipients receive an empty list (share visibility rule).
     *
     * @param string $sourceSecretId The original secret ID
     * @param string $userId         The acting user
     *
     * @return SecretShare[]
     */
    public function getSharesForSecret(string $sourceSecretId, string $userId): array
    {
        if ($this->ownership->canManageShares($sourceSecretId, $userId) === false) {
            return [];
        }

        return $this->shareMapper->findBySourceSecret($sourceSecretId);
    }//end getSharesForSecret()

    /**
     * Sync updated encrypted blobs to all copies of a shared secret.
     *
     * The caller must be the owner, a delegate, or a recipient of the secret.
     * Uses an optimistic check on the supplied expectedUpdatedAt to detect
     * concurrent updates. Clears possibly_compromised_at on every copy.
     *
     * @param string                         $sourceSecretId    The original secret ID
     * @param array<int,array<string,mixed>> $updates           Per-copy {secret_id, encrypted_*}
     * @param string                         $userId            The acting user
     * @param string|null                    $expectedUpdatedAt Optimistic-lock timestamp
     *
     * @return int The number of copies updated
     *
     * @throws RuntimeException When unauthorized
     */
    public function syncUpdate(
        string $sourceSecretId,
        array $updates,
        string $userId,
        ?string $expectedUpdatedAt=null,
    ): int {
        if ($this->canSyncSecret(sourceSecretId: $sourceSecretId, userId: $userId) === false) {
            throw new RuntimeException('Not authorized to sync this secret');
        }

        // Optimistic lock: reject the sync if the source secret has changed
        // since the caller read it (concurrent update). The browser retries by
        // re-reading and re-encrypting.
        if ($expectedUpdatedAt !== null) {
            $current = $this->copyGateway->getUpdatedAt(secretId: $sourceSecretId);
            if ($current !== null && $current !== $expectedUpdatedAt) {
                throw new RuntimeException('Concurrent update detected; retry the sync');
            }
        }

        $count = 0;
        $this->db->beginTransaction();
        try {
            foreach ($updates as $update) {
                $secretId = (string) ($update['secret_id'] ?? '');
                if ($secretId === '') {
                    continue;
                }

                $this->copyGateway->updateCopyBlobs(secretId: $secretId, encrypted: $update);
                $count++;
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }//end try

        return $count;
    }//end syncUpdate()

    /**
     * Whether a user may sync a shared secret (owner, delegate or recipient).
     *
     * @param string $sourceSecretId The original secret ID
     * @param string $userId         The acting user
     *
     * @return bool
     */
    private function canSyncSecret(string $sourceSecretId, string $userId): bool
    {
        if ($this->ownership->canManageShares(secretId: $sourceSecretId, userId: $userId) === true) {
            return true;
        }

        // A recipient may sync their own and sibling copies.
        foreach ($this->shareMapper->findByTargetUser(targetUserId: $userId) as $recipientShare) {
            if ($recipientShare->getSourceSecretId() === $sourceSecretId) {
                return true;
            }
        }

        return false;
    }//end canSyncSecret()

    /**
     * Cascade-delete every share artefact for a secret (used on secret delete).
     *
     * @param string $sourceSecretId The original secret ID
     *
     * @return void
     */
    public function deleteAllSharesForSecret(string $sourceSecretId): void
    {
        $shares = $this->shareMapper->deleteBySourceSecret($sourceSecretId);
        foreach ($shares as $share) {
            $this->copyGateway->deleteCopy((string) $share->getSecretId());
        }
    }//end deleteAllSharesForSecret()

    /**
     * Cascade-delete every share targeting a user (EncryptionSuite revocation).
     *
     * @param string $targetUserId The recipient user ID whose suite was revoked
     *
     * @return void
     */
    public function deleteAllSharesForTargetUser(string $targetUserId): void
    {
        $shares = $this->shareMapper->deleteByTargetUser($targetUserId);
        foreach ($shares as $share) {
            $this->copyGateway->deleteCopy((string) $share->getSecretId());
        }
    }//end deleteAllSharesForTargetUser()

    /**
     * Submit a share request from a recipient to the secret owner.
     *
     * @param string $sourceSecretId The original secret ID
     * @param string $targetUserId   The proposed new recipient
     * @param string $requesterId    The requesting recipient
     *
     * @return void
     *
     * @throws RuntimeException When the requester holds no share of the secret
     */
    public function submitShareRequest(string $sourceSecretId, string $targetUserId, string $requesterId): void
    {
        if ($this->shareMapper->findBySourceSecretAndTargetUser($sourceSecretId, $requesterId) === null) {
            throw new RuntimeException('Only a recipient of the secret may request a share');
        }

        $ownerId = $this->ownership->getOwnerId($sourceSecretId);
        if ($ownerId === null) {
            throw new RuntimeException('Secret owner could not be resolved');
        }

        $this->notificationService->notify(
            'share_request',
            $ownerId,
            [
                'actorId'        => $requesterId,
                'targetUserId'   => $targetUserId,
                'sourceSecretId' => $sourceSecretId,
            ],
            $sourceSecretId
        );
    }//end submitShareRequest()

    /**
     * Notify a requester that their share request was denied.
     *
     * @param string $sourceSecretId The original secret ID
     * @param string $requesterId    The recipient who requested the share
     * @param string $ownerId        The owner who denied the request
     *
     * @return void
     */
    public function denyShareRequest(string $sourceSecretId, string $requesterId, string $ownerId): void
    {
        if ($this->ownership->isOwner($sourceSecretId, $ownerId) === false) {
            throw new RuntimeException('Only the owner may resolve a share request');
        }

        $this->notificationService->notify(
            'share_request_result',
            $requesterId,
            [
                'approved'       => false,
                'sourceSecretId' => $sourceSecretId,
            ],
            $sourceSecretId
        );
    }//end denyShareRequest()

    /**
     * Notify a requester that their share request was approved.
     *
     * @param string $sourceSecretId The original secret ID
     * @param string $requesterId    The recipient who requested the share
     *
     * @return void
     */
    public function notifyShareRequestApproved(string $sourceSecretId, string $requesterId): void
    {
        $this->notificationService->notify(
            'share_request_result',
            $requesterId,
            [
                'approved'       => true,
                'sourceSecretId' => $sourceSecretId,
            ],
            $sourceSecretId
        );
    }//end notifyShareRequestApproved()
}//end class
