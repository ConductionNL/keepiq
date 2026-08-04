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
use OCA\Doriath\Event\Audit\AuditEventFactory;
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
     * @param AttachmentService|null $attachmentService   The attachment service (revoke cascade)
     * @param SecretTypeService|null $typeService         The type service (recipient-copy type resolution)
     * @param TeamFolderService|null $teamFolderService   The team-folder service (write-grade resolution)
     * @param AuditEventFactory      $auditEvents         The audit-event factory
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
        private ?SecretTypeService $typeService=null,
        private ?TeamFolderService $teamFolderService=null,
        private AuditEventFactory $auditEvents=new AuditEventFactory(),
    ) {
    }//end __construct()

    /**
     * Register a batch of DIRECT user-to-user shares from client-encrypted
     * blobs (bulk-actions §6.1/§7.1): for each row the server creates the
     * recipient's Secret copy from the pre-encrypted fields and links the
     * ShareTarget — idempotent (an existing share reports `exists`), owner
     * -scoped per item, and skip-not-fail for ineligible rows so a mixed
     * selection never aborts the run. Mirrors the team-folder fan-out
     * registration; the server never sees plaintext (ADR-003).
     *
     * @param string                         $userId The sharing owner
     * @param array<int,array<string,mixed>> $shares Rows {sourceSecretId, targetUserId, encryptedKey, encryptedLogin?, encryptedAdditionalFields?}
     *
     * @return array<int,array{sourceSecretId:string,targetUserId:string,status:string,recipientSecretId?:string}>
     *
     * @spec openspec/changes/bulk-actions/specs/bulk-actions/spec.md#requirement-bulk-share
     */
    public function registerDirectShares(string $userId, array $shares): array
    {
        $report        = [];
        $newRecipients = [];
        foreach ($shares as $row) {
            $entry    = $this->registerDirectShare(userId: $userId, row: $row);
            $report[] = $entry;
            if ($entry['status'] === 'created') {
                $newRecipients[$entry['targetUserId']] = true;
            }
        }

        // One notification per recipient per run — not per secret.
        foreach (array_keys($newRecipients) as $recipientId) {
            $this->notificationService->notify(
                subject: 'secret_shared',
                recipientId: (string) $recipientId,
                params: ['shared_by' => $userId],
            );
        }

        return $report;
    }//end registerDirectShares()

    /**
     * Validate one bulk-share row and, when it is well-formed, register it.
     *
     * Skip-not-fail: every rejection is reported as a status so a mixed
     * selection never aborts the run.
     *
     * @param string              $userId The sharing owner
     * @param array<string,mixed> $row    One {sourceSecretId, targetUserId, encryptedKey, ...} row
     *
     * @return array{sourceSecretId:string,targetUserId:string,status:string,recipientSecretId?:string}
     */
    private function registerDirectShare(string $userId, array $row): array
    {
        $sourceSecretId = (string) ($row['sourceSecretId'] ?? '');
        $targetUserId   = (string) ($row['targetUserId'] ?? '');
        $encryptedKey   = (string) ($row['encryptedKey'] ?? '');
        if ($sourceSecretId === '' || $targetUserId === '' || $encryptedKey === '') {
            return [
                'sourceSecretId' => $sourceSecretId,
                'targetUserId'   => $targetUserId,
                'status'         => 'invalid',
            ];
        }

        if ($targetUserId === $userId) {
            return [
                'sourceSecretId' => $sourceSecretId,
                'targetUserId'   => $targetUserId,
                'status'         => 'self',
            ];
        }

        return $this->createDirectShare(
            userId: $userId,
            sourceSecretId: $sourceSecretId,
            targetUserId: $targetUserId,
            encryptedKey: $encryptedKey,
            row: $row
        );
    }//end registerDirectShare()

    /**
     * Create the recipient copy and the ShareTarget row for one validated
     * bulk-share row, enforcing the owner guard and idempotency.
     *
     * @param string              $userId         The sharing owner
     * @param string              $sourceSecretId The owner's source secret
     * @param string              $targetUserId   The recipient
     * @param string              $encryptedKey   Recipient-encrypted key blob
     * @param array<string,mixed> $row            The original row (optional blobs)
     *
     * @return array{sourceSecretId:string,targetUserId:string,status:string,recipientSecretId?:string}
     */
    private function createDirectShare(
        string $userId,
        string $sourceSecretId,
        string $targetUserId,
        string $encryptedKey,
        array $row,
    ): array {
        // Per-item owner guard — a foreign secret is skipped, never a
        // whole-batch failure and never an oracle.
        try {
            $source = $this->secretMapper->findById($sourceSecretId);
        } catch (DoesNotExistException) {
            $source = null;
        }

        if ($source === null || $source->getOwnerType() !== 'user' || $source->getOwnerId() !== $userId) {
            return [
                'sourceSecretId' => $sourceSecretId,
                'targetUserId'   => $targetUserId,
                'status'         => 'not_owned',
            ];
        }

        // Idempotency: an existing share (any provenance) is `exists`.
        try {
            $this->mapper->findBySourceSecretAndTargetUser(
                sourceSecretId: $sourceSecretId,
                targetUserId: $targetUserId
            );
            return [
                'sourceSecretId' => $sourceSecretId,
                'targetUserId'   => $targetUserId,
                'status'         => 'exists',
            ];
        } catch (DoesNotExistException) {
            // No existing share — create below.
        }

        $copy = $this->createDirectRecipientCopy(
            source: $source,
            targetUserId: $targetUserId,
            encryptedKey: $encryptedKey,
            encryptedLogin: $this->optionalString(value: ($row['encryptedLogin'] ?? null)),
            encryptedExtras: $this->optionalString(value: ($row['encryptedAdditionalFields'] ?? null)),
        );
        if ($copy === null) {
            return [
                'sourceSecretId' => $sourceSecretId,
                'targetUserId'   => $targetUserId,
                'status'         => 'no_suite',
            ];
        }

        $entity = new ShareTarget();
        $entity->setId(Uuid::uuid4()->toString());
        $entity->setSourceSecretId($sourceSecretId);
        $entity->setTargetUserId($targetUserId);
        $entity->setSecretId($copy->getId());
        $entity->setCreatedBy($userId);
        $entity->setCreatedAt(new DateTime());
        $this->mapper->insert($entity);

        $this->dispatchAudit(
            event: $this->auditEvents->forUser(
                actorId: $userId,
                eventType: AuditEventTypes::SHARE_GRANTED,
                objectType: 'secret',
                objectId: $sourceSecretId,
                objectName: $source->getName(),
                metadata: [
                    'recipientType' => 'user',
                    'recipientId'   => $targetUserId,
                ],
            )
        );

        return [
            'sourceSecretId'    => $sourceSecretId,
            'targetUserId'      => $targetUserId,
            'status'            => 'created',
            'recipientSecretId' => $copy->getId(),
        ];
    }//end createDirectShare()

    /**
     * The active-suite PEM certificate of a share recipient — public key
     * material only (needed client-side to encrypt the copy; ADR-003).
     *
     * @param string $targetUserId The prospective recipient
     *
     * @return string|null The PEM certificate (null = no active suite)
     */
    public function recipientCertificate(string $targetUserId): ?string
    {
        try {
            return $this->suiteMapper
                ->findActiveByOwner(ownerType: 'user', ownerId: $targetUserId)
                ->getCertificate();
        } catch (DoesNotExistException) {
            return null;
        }
    }//end recipientCertificate()

    /**
     * Create a recipient's Secret copy from client-encrypted blobs, or
     * null when the recipient has no active suite (skip, not fail).
     *
     * @param Secret      $source          The owner's source secret
     * @param string      $targetUserId    The recipient
     * @param string      $encryptedKey    Recipient-encrypted key blob
     * @param string|null $encryptedLogin  Recipient-encrypted login blob
     * @param string|null $encryptedExtras Recipient-encrypted additionalFields blob
     *
     * @return Secret|null
     */
    private function createDirectRecipientCopy(
        Secret $source,
        string $targetUserId,
        string $encryptedKey,
        ?string $encryptedLogin,
        ?string $encryptedExtras,
    ): ?Secret {
        try {
            $suite = $this->suiteMapper->findActiveByOwner(ownerType: 'user', ownerId: $targetUserId);
        } catch (DoesNotExistException) {
            return null;
        }

        $typeId = null;
        if ($this->typeService !== null) {
            try {
                $typeId = $this->typeService->resolveTypeForSecret($source->getTypeId(), $targetUserId);
            } catch (InvalidArgumentException) {
                $typeId = $this->typeService->resolveTypeForSecret(null, $targetUserId);
            }
        }

        $now  = new DateTime();
        $copy = new Secret();
        $copy->setId(Uuid::uuid4()->toString());
        $copy->setName($source->getName());
        $copy->setUrl($source->getUrl());
        if ($typeId !== null) {
            $copy->setTypeId($typeId);
        }

        $copy->setFolderId(null);
        $copy->setKey($encryptedKey);
        $copy->setLogin($encryptedLogin);
        $copy->setAdditionalFields($encryptedExtras);
        $copy->setEncryptionSuiteId($suite->getId());
        $copy->setOwnerType('user');
        $copy->setOwnerId($targetUserId);
        $copy->setCreatedAt($now);
        $copy->setUpdatedAt($now);
        $copy->setKeyUpdatedAt($now);
        $this->secretMapper->insert($copy);

        return $copy;
    }//end createDirectRecipientCopy()

    /**
     * Normalise an optional blob value to a non-empty string or null.
     *
     * @param mixed $value The raw value
     *
     * @return string|null
     */
    private function optionalString(mixed $value): ?string
    {
        if (is_string($value) === true && $value !== '') {
            return $value;
        }

        return null;
    }//end optionalString()

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
            event: $this->auditEvents->forUser(
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
     * Each member-row blob is expected to carry `{targetUserId,
     * recipientSecretId}` — the caller (controller) has already created the
     * recipient Secret rows in the browser and POSTed them. The entire batch
     * shares one `$groupShareId` so revocation/leave handling can cascade.
     *
     * The rows arrive verbatim from an untrusted `#[NoAdminRequired]` request
     * body, so the shape is deliberately typed as loose `mixed` here — the
     * per-row guard below is the real validation and must not be removed on
     * the strength of a docblock promise.
     *
     * @param string                         $sourceSecretId The owner's source secret ID
     * @param array<int,array<string,mixed>> $shares         The per-recipient batch
     * @param string                         $groupShareId   The GroupShare ID for cascade
     * @param string                         $userId         The initiator
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
            // A write-grade team member needs the recipient list (+
            // certificates) to run the re-encrypt fan-out
            // (folder-permission-grades §2.3); read grades see nothing.
            $grade = $this->teamFolderService?->resolveGrade(secret: $source, userId: $userId);
            if ($grade !== 'write') {
                return [];
            }
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
        }//end try

        $this->logger->info(
            'Revoked share '.$shareId.' for source '.$entity->getSourceSecretId(),
            ['app' => 'doriath']
        );

        $this->dispatchAudit(
            event: $this->auditEvents->forUser(
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
        $source   = $this->loadSecret(secretId: $secretId);
        $isWriter = $this->resolveSyncWriter(source: $source, userId: $userId);
        $this->assertSyncSourceUnchanged(source: $source, expectedUpdatedAt: $expectedUpdatedAt);

        $updated = $this->applySyncUpdates(
            source: $source,
            updates: $updates,
            allowedIds: $this->collectSyncTargets(source: $source)
        );

        // A non-owner write is attributed to the writer (§3.3) —
        // identifiers only, never key material.
        if ($isWriter === true && $updated > 0) {
            $this->dispatchAudit(
                event: $this->auditEvents->forUser(
                    actorId: $userId,
                    eventType: AuditEventTypes::SECRET_UPDATED,
                    objectType: 'secret',
                    objectId: $source->getId(),
                    objectName: $source->getName(),
                    metadata: ['changedFields' => 'team-write sync'],
                )
            );
        }

        return $updated;
    }//end syncUpdate()

    /**
     * Authorization seam for a sync (folder-permission-grades §2.3): the
     * owner and active delegates as before, OR a member holding a `write`
     * grade on an ancestor team folder. Everyone else keeps the existing
     * rejection.
     *
     * @param Secret $source The source secret being synced
     * @param string $userId The requesting user
     *
     * @return bool True when the caller writes as a team-folder member rather than the owner.
     *
     * @throws InvalidArgumentException When the caller may not write the secret
     */
    private function resolveSyncWriter(Secret $source, string $userId): bool
    {
        if ($this->isOwnerOrDelegate(secret: $source, userId: $userId) === true) {
            return false;
        }

        $grade = $this->teamFolderService?->resolveGrade(secret: $source, userId: $userId);
        if ($grade !== 'write') {
            $this->assertOwnerOrDelegate(secret: $source, userId: $userId);
        }

        return true;
    }//end resolveSyncWriter()

    /**
     * Optimistic lock — if the source has moved since the browser last
     * fetched it, refuse the sync so the caller can re-encrypt against the
     * current value.
     *
     * @param Secret $source            The source secret being synced
     * @param string $expectedUpdatedAt The owner-side expected ISO timestamp
     *
     * @return void
     *
     * @throws InvalidArgumentException When the source has changed since the sync was prepared
     */
    private function assertSyncSourceUnchanged(Secret $source, string $expectedUpdatedAt): void
    {
        if ($expectedUpdatedAt === '' || $source->getUpdatedAt() === null) {
            return;
        }

        if ($source->getUpdatedAt()->format(DateTime::ATOM) !== $expectedUpdatedAt) {
            throw new InvalidArgumentException(
                message: 'Source secret has changed since the sync was prepared'
            );
        }
    }//end assertSyncSourceUnchanged()

    /**
     * Membership guard: a sync may only touch the source row itself and its
     * ACTUAL recipient copies. Without this, any authorized caller could
     * pass arbitrary secret ids and corrupt foreign ciphertext (pre-existing
     * defect fixed with folder-permission-grades §2.3).
     *
     * @param Secret $source The source secret being synced
     *
     * @return array<string,bool> The secret ids this sync may write, keyed by id.
     */
    private function collectSyncTargets(Secret $source): array
    {
        $allowedIds = [$source->getId() => true];
        foreach ($this->mapper->findBySourceSecret($source->getId()) as $shareRow) {
            $allowedIds[$shareRow->getSecretId()] = true;
        }

        return $allowedIds;
    }//end collectSyncTargets()

    /**
     * Write every permitted recipient blob in a single transaction.
     *
     * @param Secret                               $source     The source secret being synced
     * @param array<int,array<string,string|null>> $updates    The per-recipient blobs
     * @param array<string,bool>                   $allowedIds The secret ids this sync may write
     *
     * @return int Number of recipient copies updated.
     *
     * @throws Throwable When the transaction fails; the write is rolled back first.
     */
    private function applySyncUpdates(Secret $source, array $updates, array $allowedIds): int
    {
        $updated = 0;
        $this->db->beginTransaction();
        try {
            foreach ($updates as $update) {
                $recipientSecretId = (string) ($update['secretId'] ?? '');
                if ($recipientSecretId === '' || isset($allowedIds[$recipientSecretId]) === false) {
                    continue;
                }

                try {
                    $copy = $this->secretMapper->findById($recipientSecretId);
                } catch (DoesNotExistException) {
                    continue;
                }

                $this->applyRecipientBlob(copy: $copy, update: $update);

                // A key rewrite of the SOURCE row is a real rotation —
                // advance keyUpdatedAt so rotation proofs stay honest
                // (folder-permission-grades §2.3).
                if ($recipientSecretId === $source->getId() && isset($update['key']) === true) {
                    $copy->setKeyUpdatedAt(new DateTime());
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
    }//end applySyncUpdates()

    /**
     * Copy one re-encrypted blob onto a recipient's Secret row.
     *
     * @param Secret                    $copy   The recipient copy to mutate
     * @param array<string,string|null> $update The blob row for this recipient
     *
     * @return void
     */
    private function applyRecipientBlob(Secret $copy, array $update): void
    {
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
    }//end applyRecipientBlob()

    /**
     * The write context of a secret for the current user
     * (folder-permission-grades §4): resolves a recipient copy back to
     * its source and reports the caller's effective grade plus the
     * owner-row material a write-grade member needs to run the
     * re-encrypt fan-out (the owner's certificate is public key
     * material).
     *
     * @param string $secretId The secret (source or recipient copy) UUID
     * @param string $userId   The requesting user
     *
     * @return array{sourceSecretId:string, effectiveGrade:string, ownerCertificate:string|null, sourceUpdatedAt:string|null}
     *
     * @throws InvalidArgumentException When the secret does not exist
     *
     * @spec openspec/changes/folder-permission-grades/specs/folder-permission-grades/spec.md#requirement-write-grade-editing
     */
    public function writeContext(string $secretId, string $userId): array
    {
        $secret = $this->loadSecret(secretId: $secretId);

        // Pivot a recipient copy back to its source.
        $source = $secret;
        try {
            $shareRow = $this->mapper->findByRecipientSecret(recipientSecretId: $secretId);
            $source   = $this->loadSecret(secretId: $shareRow->getSourceSecretId());
        } catch (DoesNotExistException) {
            // Not a copy — the secret is its own source.
        }

        $grade   = 'none';
        $isOwner = ($this->isOwnerOrDelegate(secret: $source, userId: $userId) === true);
        if ($isOwner === true) {
            $grade = 'owner';
        }

        if ($isOwner === false) {
            $resolved = $this->teamFolderService?->resolveGrade(secret: $source, userId: $userId);
            if ($resolved !== null) {
                $grade = $resolved;
            }
        }

        $ownerCertificate = null;
        if ($grade === 'write' || $grade === 'owner') {
            try {
                $ownerCertificate = $this->suiteMapper
                    ->findActiveByOwner(ownerType: $source->getOwnerType(), ownerId: $source->getOwnerId())
                    ->getCertificate();
            } catch (DoesNotExistException) {
                // Owner without an active suite — fan-out skips the source row.
            }
        }

        return [
            'sourceSecretId'   => $source->getId(),
            'effectiveGrade'   => $grade,
            'ownerCertificate' => $ownerCertificate,
            'sourceUpdatedAt'  => $source->getUpdatedAt()?->format(DateTime::ATOM),
        ];
    }//end writeContext()

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
