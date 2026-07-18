<?php

/**
 * Doriath Share Service
 *
 * Business logic for the user-to-user secret-share lifecycle:
 * createShare (owner/delegate authorization + recipient-suite
 * precondition), revokeShare (cascade delete of recipient copy),
 * syncUpdate (multi-recipient encrypted-blob refresh with optimistic
 * locking + compromise-flag clearance), getSharesForSecret
 * (owner/delegate-only view), and createBatchShares (group expansion).
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
use OCA\Doriath\Db\SecretDelegationMapper;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\ShareTarget;
use OCA\Doriath\Db\ShareTargetMapper;
use OCA\Doriath\Event\Audit\AuditEvent;
use OCA\Doriath\Event\Audit\AuditEventTypes;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

/**
 * Business logic for user-to-user secret-share lifecycle.
 *
 * Authorization is two-tier: the Secret owner is always authorized; a
 * SecretDelegation (temporary or permanent) authorizes the delegate as
 * well — both share-creation and revocation paths fall through the same
 * `assertOwnerOrDelegate()` helper so the authorization surface stays in
 * one place.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The service deliberately
 *   threads through the Secret, EncryptionSuite, Delegation and ShareTarget
 *   mappers so authorization, recipient-suite precondition, owner copy
 *   creation, and recipient-row insertion live in one place — splitting it
 *   would scatter the share invariants across four classes.
 */
class ShareService
{
    /**
     * Constructor for ShareService.
     *
     * @param ShareTargetMapper      $mapper              The share-target mapper
     * @param SecretMapper           $secretMapper        The Secret mapper (owner + recipient lookups)
     * @param EncryptionSuiteMapper  $suiteMapper         The EncryptionSuite mapper (recipient-suite precondition)
     * @param SecretDelegationMapper $delegationMapper    The Delegation mapper (delegate authorization)
     * @param NotificationService    $notificationService The notification dispatcher
     * @param IDBConnection          $db                  The DB connection (for syncUpdate transaction)
     * @param LoggerInterface        $logger              The logger interface
     * @param IEventDispatcher|null  $eventDispatcher     The event dispatcher
     *
     * @return void
     */
    public function __construct(
        private ShareTargetMapper $mapper,
        private SecretMapper $secretMapper,
        private EncryptionSuiteMapper $suiteMapper,
        private SecretDelegationMapper $delegationMapper,
        private NotificationService $notificationService,
        private IDBConnection $db,
        private LoggerInterface $logger,
        private ?IEventDispatcher $eventDispatcher=null,
        private ?AttachmentService $attachmentService=null,
    ) {
    }//end __construct()

    /**
     * Dispatch a typed audit event, fail-soft.
     *
     * @param AuditEvent $event The audit event
     *
     * @return void
     *
     * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3
     */
    private function dispatchAudit(AuditEvent $event): void
    {
        $this->eventDispatcher?->dispatchTyped($event);
    }//end dispatchAudit()

    /**
     * Create a single share target record.
     *
     * Authorization: $userId must be the Secret owner OR an active
     * delegate. Precondition: the recipient must have an active
     * EncryptionSuite (the browser-encrypted blob is useless otherwise).
     * The recipient's encrypted Secret copy must already exist; the
     * browser persists it through the SecretController before this call.
     *
     * @param string      $sourceSecretId    The owner's source secret ID
     * @param string      $targetUserId      The recipient's Nextcloud user ID
     * @param string      $recipientSecretId The recipient's encrypted Secret copy ID
     * @param string|null $groupShareId      Optional group-share linkage
     * @param string      $userId            The Nextcloud user ID creating the share
     *
     * @return ShareTarget
     *
     * @throws InvalidArgumentException When validation fails
     *
     * @spec openspec/changes/implement-user-sharing/tasks.md#3.2
     */
    public function createShare(
        string $sourceSecretId,
        string $targetUserId,
        string $recipientSecretId,
        ?string $groupShareId,
        string $userId,
    ): ShareTarget {
        if ($sourceSecretId === '') {
            throw new InvalidArgumentException(message: 'sourceSecretId is required');
        }

        if ($targetUserId === '') {
            throw new InvalidArgumentException(message: 'targetUserId is required');
        }

        if ($recipientSecretId === '') {
            throw new InvalidArgumentException(message: 'recipientSecretId is required');
        }

        $source = $this->loadSecret(secretId: $sourceSecretId);

        if ($targetUserId === $source->getOwnerId()) {
            throw new InvalidArgumentException(message: 'Cannot share a secret with its owner');
        }

        if ($targetUserId === $userId) {
            throw new InvalidArgumentException(message: 'Cannot share a secret with yourself');
        }

        $this->assertOwnerOrDelegate(secret: $source, userId: $userId);
        $this->assertRecipientHasActiveSuite(targetUserId: $targetUserId);

        // Enforce one-share-per-(source,recipient) invariant.
        try {
            $this->mapper->findBySourceSecretAndTargetUser(
                sourceSecretId: $sourceSecretId,
                targetUserId: $targetUserId
            );
            throw new InvalidArgumentException(message: 'Secret is already shared with this user');
        } catch (DoesNotExistException) {
            // No existing row — proceed.
        }

        $entity = new ShareTarget();
        $entity->setId(Uuid::uuid4()->toString());
        $entity->setSourceSecretId($sourceSecretId);
        $entity->setTargetUserId($targetUserId);
        $entity->setSecretId($recipientSecretId);
        $entity->setGroupShareId($groupShareId);
        $entity->setCreatedBy($userId);
        $entity->setCreatedAt(new DateTime());

        $persisted = $this->mapper->insert($entity);

        // Fire-and-forget notification to the recipient. The user
        // preference + opt-out check happens inside NotificationService.
        $this->notificationService->notify(
            subject: 'secret_shared',
            recipientId: $targetUserId,
            params: [
                'secretId'   => $sourceSecretId,
                'secretName' => $source->getName(),
                'sharedBy'   => $userId,
            ],
            objectType: 'secret',
            objectId: $sourceSecretId,
        );

        $this->dispatchAudit(
            event: AuditEvent::forUser(
                actorId: $userId,
                eventType: AuditEventTypes::SHARE_GRANTED,
                objectType: 'share',
                objectId: $persisted->getId(),
                objectName: $source->getName(),
                metadata: [
                    'recipientType' => 'user',
                    'recipientId'   => $targetUserId,
                ],
            )
        );

        return $persisted;
    }//end createShare()

    /**
     * Create a batch of recipient share targets for a group expansion.
     *
     * Each member-row blob is `{targetUserId, recipientSecretId}` — the
     * caller (controller) has already created the recipient Secret rows
     * in the browser and POSTed them. The entire batch shares one
     * `$groupShareId` so revocation/leave handling can cascade.
     *
     * @param string                                                         $sourceSecretId The owner's source secret ID
     * @param array<int,array{targetUserId:string,recipientSecretId:string}> $shares         The per-recipient batch
     * @param string                                                         $groupShareId   The GroupShare ID for cascade
     * @param string                                                         $userId         The initiator
     *
     * @return ShareTarget[]
     *
     * @spec openspec/changes/implement-user-sharing/tasks.md#3.6
     */
    public function createBatchShares(
        string $sourceSecretId,
        array $shares,
        string $groupShareId,
        string $userId,
    ): array {
        $created = [];
        $this->db->beginTransaction();
        try {
            foreach ($shares as $row) {
                $targetUserId      = (string) ($row['targetUserId'] ?? '');
                $recipientSecretId = (string) ($row['recipientSecretId'] ?? '');
                if ($targetUserId === '' || $recipientSecretId === '') {
                    continue;
                }

                $created[] = $this->createShare(
                    sourceSecretId: $sourceSecretId,
                    targetUserId: $targetUserId,
                    recipientSecretId: $recipientSecretId,
                    groupShareId: $groupShareId,
                    userId: $userId,
                );
            }

            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }//end try

        return $created;
    }//end createBatchShares()

    /**
     * List all share targets for a given source secret.
     *
     * Only the owner or an active delegate sees the recipient list; for
     * recipients and non-participants the return is an empty array (so
     * UI can hide the section without raising a 403).
     *
     * @param string $sourceSecretId The source secret ID
     * @param string $userId         The requesting user ID
     *
     * @return ShareTarget[]
     *
     * @spec openspec/changes/implement-user-sharing/tasks.md#3.5
     */
    public function listSharesForSecret(string $sourceSecretId, string $userId=''): array
    {
        if ($userId === '') {
            // Back-compat: callers that do not provide a userId get the
            // raw list (scaffold semantics, used by the cascade path).
            return $this->mapper->findBySourceSecret($sourceSecretId);
        }

        try {
            $source = $this->loadSecret(secretId: $sourceSecretId);
        } catch (InvalidArgumentException) {
            return [];
        }

        if ($this->isOwnerOrDelegate(secret: $source, userId: $userId) === false) {
            return [];
        }

        return $this->mapper->findBySourceSecret($sourceSecretId);
    }//end listSharesForSecret()

    /**
     * Revoke a single share target — deletes the recipient's encrypted
     * Secret copy and the share-target row in one transaction.
     *
     * @param string $shareId The share-target row ID
     * @param string $userId  The Nextcloud user ID requesting the revoke
     *
     * @return void
     *
     * @throws InvalidArgumentException When the row does not exist or
     *                                  the caller is not authorized.
     *
     * @spec openspec/changes/implement-user-sharing/tasks.md#3.3
     */
    public function revokeShare(string $shareId, string $userId): void
    {
        try {
            $entity = $this->mapper->findById($shareId);
        } catch (DoesNotExistException) {
            throw new InvalidArgumentException(message: 'Share not found');
        }

        $source = $this->loadSecret(secretId: $entity->getSourceSecretId());
        $this->assertOwnerOrDelegate(secret: $source, userId: $userId);

        $this->db->beginTransaction();
        try {
            // Delete the recipient's encrypted Secret copy (best-effort —
            // a stale row is harmless because the Secret is already
            // unreachable through the share index, but a clean delete
            // keeps the table small).
            try {
                $recipientCopy = $this->secretMapper->findById($entity->getSecretId());
                $this->secretMapper->delete($recipientCopy);
            } catch (DoesNotExistException) {
                // Already gone — continue.
            }

            // Attachment grants of the revoked copy go with it; the
            // owner's blob and grants are untouched (encrypted-attachments
            // §3.3).
            $this->attachmentService?->deleteGrantsForSecretCopy($entity->getSecretId());

            $this->mapper->delete($entity);
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        $this->logger->info(
            'Revoked share '.$shareId.' for source '.$entity->getSourceSecretId(),
            ['app' => 'doriath']
        );

        $this->dispatchAudit(
            event: AuditEvent::forUser(
                actorId: $userId,
                eventType: AuditEventTypes::SHARE_REVOKED,
                objectType: 'share',
                objectId: $shareId,
                objectName: $source->getName(),
                metadata: [
                    'recipientType' => 'user',
                    'recipientId'   => $entity->getTargetUserId(),
                ],
            )
        );
    }//end revokeShare()

    /**
     * Push an updated encrypted blob to every recipient.
     *
     * The browser supplies one blob per recipient (the source secret's
     * new value re-encrypted under each recipient's public key). The
     * server validates the caller is the owner or an active delegate,
     * applies an optimistic-locking check via the source Secret's
     * `updatedAt`, writes every recipient copy in a single transaction,
     * and clears `possiblyCompromisedAt` from any copy where it was set.
     *
     * @param string                               $secretId          The source secret ID
     * @param array<int,array<string,string|null>> $updates           The per-recipient blobs; each row has
     *                                                                secretId, key, login,
     *                                                                additionalFields, updatedAtCheck
     * @param string                               $expectedUpdatedAt The owner-side expected ISO timestamp for optimistic locking
     * @param string                               $userId            The requesting user
     *
     * @return int Number of recipient copies updated.
     *
     * @throws InvalidArgumentException When validation or optimistic-lock check fails
     *
     * @spec openspec/changes/implement-user-sharing/tasks.md#3.4
     */
    public function syncUpdate(
        string $secretId,
        array $updates,
        string $expectedUpdatedAt,
        string $userId,
    ): int {
        $source = $this->loadSecret(secretId: $secretId);
        $this->assertOwnerOrDelegate(secret: $source, userId: $userId);

        // Optimistic lock — if the source has moved since the browser
        // last fetched it, refuse the sync so the caller can re-encrypt
        // against the current value.
        if ($expectedUpdatedAt !== '' && $source->getUpdatedAt() !== null) {
            $current = $source->getUpdatedAt()->format(DateTime::ATOM);
            if ($current !== $expectedUpdatedAt) {
                throw new InvalidArgumentException(
                    message: 'Source secret has changed since the sync was prepared'
                );
            }
        }

        $updated = 0;
        $this->db->beginTransaction();
        try {
            foreach ($updates as $update) {
                $recipientSecretId = (string) ($update['secretId'] ?? '');
                if ($recipientSecretId === '') {
                    continue;
                }

                try {
                    $copy = $this->secretMapper->findById($recipientSecretId);
                } catch (DoesNotExistException) {
                    continue;
                }

                if (isset($update['key']) === true) {
                    $copy->setKey((string) $update['key']);
                }

                if (array_key_exists('login', $update) === true) {
                    $login = null;
                    if ($update['login'] !== null) {
                        $login = (string) $update['login'];
                    }

                    $copy->setLogin($login);
                }

                if (array_key_exists('additionalFields', $update) === true) {
                    $additionalFields = null;
                    if ($update['additionalFields'] !== null) {
                        $additionalFields = (string) $update['additionalFields'];
                    }

                    $copy->setAdditionalFields($additionalFields);
                }

                $copy->setUpdatedAt(new DateTime());

                // If the copy was previously flagged as possibly compromised
                // (e.g. EncryptionSuite migration mid-air), the freshly
                // re-encrypted blob clears that warning for the recipient.
                if ($copy->getPossiblyCompromisedAt() !== null) {
                    $copy->setPossiblyCompromisedAt(null);
                }

                $this->secretMapper->update($copy);
                ++$updated;
            }//end foreach

            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }//end try

        return $updated;
    }//end syncUpdate()

    /**
     * Cascade-delete all share targets for a secret (called on secret delete).
     *
     * @param string $sourceSecretId The source secret ID
     *
     * @return void
     */
    public function deleteAllForSecret(string $sourceSecretId): void
    {
        $this->mapper->deleteBySourceSecret($sourceSecretId);
    }//end deleteAllForSecret()

    /**
     * Assert that $userId is the owner of $secret or an active delegate.
     *
     * @param Secret $secret The source secret
     * @param string $userId The candidate user
     *
     * @return void
     *
     * @throws InvalidArgumentException When the user is neither owner nor delegate
     */
    private function assertOwnerOrDelegate(Secret $secret, string $userId): void
    {
        if ($this->isOwnerOrDelegate(secret: $secret, userId: $userId) === false) {
            throw new InvalidArgumentException(
                message: 'Not authorized to manage shares of this secret'
            );
        }
    }//end assertOwnerOrDelegate()

    /**
     * Return true when $userId is the owner of $secret or an active delegate.
     *
     * @param Secret $secret The source secret
     * @param string $userId The candidate user
     *
     * @return bool
     */
    private function isOwnerOrDelegate(Secret $secret, string $userId): bool
    {
        if ($secret->getOwnerType() === 'user' && $secret->getOwnerId() === $userId) {
            return true;
        }

        try {
            $this->delegationMapper->findActiveBySecretAndUser(
                secretId: $secret->getId(),
                userId: $userId
            );
            return true;
        } catch (DoesNotExistException) {
            return false;
        }
    }//end isOwnerOrDelegate()

    /**
     * Verify the recipient has an active EncryptionSuite (without it, no
     * one can decrypt the share-target row's encrypted Secret copy).
     *
     * @param string $targetUserId The recipient Nextcloud user ID
     *
     * @return void
     *
     * @throws InvalidArgumentException When the recipient has no active suite
     */
    private function assertRecipientHasActiveSuite(string $targetUserId): void
    {
        try {
            $this->suiteMapper->findActiveByOwner(
                ownerType: 'user',
                ownerId: $targetUserId
            );
        } catch (DoesNotExistException) {
            throw new InvalidArgumentException(
                message: 'Recipient has no active encryption suite'
            );
        }
    }//end assertRecipientHasActiveSuite()

    /**
     * Load a Secret by ID, surfacing missing rows as an InvalidArgumentException.
     *
     * @param string $secretId The secret ID
     *
     * @return Secret
     *
     * @throws InvalidArgumentException
     */
    private function loadSecret(string $secretId): Secret
    {
        try {
            return $this->secretMapper->findById($secretId);
        } catch (DoesNotExistException) {
            throw new InvalidArgumentException(message: 'Secret not found');
        }
    }//end loadSecret()
}//end class
