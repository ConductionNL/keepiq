<?php

/**
 * Doriath Rotation Flag Service
 *
 * The rotation-flag lifecycle (rotation-expiry-policies §3): raising the
 * one-open-flag-per-secret row, batch flagging owned secrets, the
 * compromise sweep, and closing a flag either by a PROVEN key_updated_at
 * advance (mark-rotated) or by an owner dismissal. Kept apart from
 * RotationPolicyService, which owns expiry POLICIES and effective-expiry
 * resolution; the two share no state beyond the secret id.
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
use OCA\Doriath\Db\RotationFlag;
use OCA\Doriath\Db\RotationFlagMapper;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Event\Audit\AuditEventFactory;
use OCA\Doriath\Event\Audit\AuditEventTypes;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use Ramsey\Uuid\Uuid;

/**
 * Business logic for the rotation-flag lifecycle.
 */
class RotationFlagService
{
    /**
     * Constructor for RotationFlagService.
     *
     * @param RotationFlagMapper    $flagMapper      The flag mapper
     * @param SecretMapper          $secretMapper    The secret mapper
     * @param IEventDispatcher|null $eventDispatcher The audit event dispatcher
     * @param AuditEventFactory     $auditEvents     The audit-event factory
     *
     * @return void
     *
     * @spec exclude Constructor wiring only; no behaviour.
     */
    public function __construct(
        private RotationFlagMapper $flagMapper,
        private SecretMapper $secretMapper,
        private ?IEventDispatcher $eventDispatcher=null,
        private AuditEventFactory $auditEvents=new AuditEventFactory(),
    ) {
    }//end __construct()

    /**
     * Raise (or return the existing open) rotation flag for a secret —
     * idempotent one-open-flag-per-secret.
     *
     * @param string      $secretId  The secret UUID
     * @param string      $reason    The reason (`user_flagged`|`policy_expiry`|`suite_compromise`)
     * @param string|null $flaggedBy The raising user (null = system)
     *
     * @return RotationFlag
     *
     * @spec openspec/changes/rotation-expiry-policies/specs/rotation-expiry-policies/spec.md
     */
    public function flag(string $secretId, string $reason, ?string $flaggedBy=null): RotationFlag
    {
        try {
            $existing = $this->flagMapper->findBySecret(secretId: $secretId);
            if ($existing->getStatus() === 'open') {
                return $existing;
            }

            // A resolved flag is re-opened (fresh reason + proof point) —
            // audited exactly like a first raise.
            $existing->setReason($reason);
            $existing->setStatus('open');
            $existing->setFlaggedAt(new DateTime());
            $existing->setFlaggedBy($flaggedBy);
            $existing->setResolvedAt(null);
            $existing->setKeyUpdatedAtAtFlag($this->headKeyUpdatedAt(secretId: $secretId));
            $existing = $this->flagMapper->update($existing);

            $this->dispatchAudit(
                actorId: ($flaggedBy ?? 'system'),
                eventType: AuditEventTypes::SECRET_ROTATION_FLAGGED,
                objectId: $secretId,
                metadata: ['reason' => $reason],
            );

            return $existing;
        } catch (DoesNotExistException) {
            // No flag yet — create below.
        }//end try

        $flagRow = new RotationFlag();
        $flagRow->setId(Uuid::uuid4()->toString());
        $flagRow->setSecretId($secretId);
        $flagRow->setReason($reason);
        $flagRow->setStatus('open');
        $flagRow->setFlaggedAt(new DateTime());
        $flagRow->setFlaggedBy($flaggedBy);
        $flagRow->setKeyUpdatedAtAtFlag($this->headKeyUpdatedAt(secretId: $secretId));
        $flagRow = $this->flagMapper->insert($flagRow);

        $this->dispatchAudit(
            actorId: ($flaggedBy ?? 'system'),
            eventType: AuditEventTypes::SECRET_ROTATION_FLAGGED,
            objectId: $secretId,
            metadata: ['reason' => $reason],
        );

        return $flagRow;
    }//end flag()

    /**
     * Batch-flag secrets the caller owns (client breach findings send IDs
     * ONLY — no verdicts, scores, or digests are persisted).
     *
     * @param string   $userId    The caller
     * @param string[] $secretIds The secret UUIDs
     *
     * @return int Flags now open
     *
     * @spec openspec/changes/rotation-expiry-policies/specs/rotation-expiry-policies/spec.md
     */
    public function flagBatch(string $userId, array $secretIds): int
    {
        $flagged = 0;
        foreach ($secretIds as $secretId) {
            try {
                $secret = $this->secretMapper->findById((string) $secretId);
            } catch (DoesNotExistException) {
                continue;
            }

            if ($secret->getOwnerType() !== 'user' || $secret->getOwnerId() !== $userId) {
                continue;
            }

            $this->flag(secretId: (string) $secretId, reason: 'user_flagged', flaggedBy: $userId);
            ++$flagged;
        }

        return $flagged;
    }//end flagBatch()

    /**
     * Open flags for the caller's secrets.
     *
     * @param string $userId The caller
     *
     * @return RotationFlag[]
     *
     * @spec openspec/specs/rotation-expiry-policies/spec.md
     */
    public function openFlags(string $userId): array
    {
        return $this->flagMapper->findOpenForOwner(ownerId: $userId);
    }//end openFlags()

    /**
     * Mark a flag rotated — ONLY when the head's `key_updated_at` has
     * advanced past the value frozen at flag time (a proven rotation).
     * Otherwise the caller is told to rotate first (re-request path).
     *
     * @param string $flagId The flag UUID
     * @param string $userId The caller (must own the secret)
     *
     * @return array{resolved:bool,requiresRotation:bool}
     *
     * @spec openspec/changes/rotation-expiry-policies/specs/rotation-expiry-policies/spec.md
     */
    public function markRotated(string $flagId, string $userId): array
    {
        $flagRow = $this->loadOwnedFlag(flagId: $flagId, userId: $userId);

        $head    = $this->secretMapper->findById($flagRow->getSecretId());
        $frozen  = $flagRow->getKeyUpdatedAtAtFlag();
        $current = $head->getKeyUpdatedAt();

        $advanced = $current !== null && ($frozen === null || $current > $frozen);
        if ($advanced === false) {
            return [
                'resolved'         => false,
                'requiresRotation' => true,
            ];
        }

        $flagRow->setStatus('rotated');
        $flagRow->setResolvedAt(new DateTime());
        $this->flagMapper->update($flagRow);

        $this->dispatchAudit(
            actorId: $userId,
            eventType: AuditEventTypes::SECRET_ROTATED,
            objectId: $flagRow->getSecretId(),
            metadata: ['reason' => $flagRow->getReason()],
        );

        return [
            'resolved'         => true,
            'requiresRotation' => false,
        ];
    }//end markRotated()

    /**
     * Dismiss a flag without rotation (owner judgment call; audited).
     *
     * @param string $flagId The flag UUID
     * @param string $userId The caller (must own the secret)
     *
     * @return void
     *
     * @spec openspec/changes/rotation-expiry-policies/specs/rotation-expiry-policies/spec.md
     */
    public function dismiss(string $flagId, string $userId): void
    {
        $flagRow = $this->loadOwnedFlag(flagId: $flagId, userId: $userId);

        $flagRow->setStatus('dismissed');
        $flagRow->setResolvedAt(new DateTime());
        $this->flagMapper->update($flagRow);

        $this->dispatchAudit(
            actorId: $userId,
            eventType: AuditEventTypes::SECRET_ROTATION_DISMISSED,
            objectId: $flagRow->getSecretId(),
            metadata: ['reason' => $flagRow->getReason()],
        );
    }//end dismiss()

    /**
     * Auto-raise `suite_compromise` flags for every possibly-compromised
     * secret of a user (called from the compromise sweep). Idempotent.
     *
     * @param string $ownerId The affected owner
     *
     * @return int Flags raised or already open
     *
     * @spec openspec/changes/rotation-expiry-policies/specs/rotation-expiry-policies/spec.md
     */
    public function flagCompromisedSecrets(string $ownerId): int
    {
        $count = 0;
        foreach ($this->secretMapper->findByOwner('user', $ownerId, null, null, 'asc', 100000, 0) as $secret) {
            if ($secret->getPossiblyCompromisedAt() === null) {
                continue;
            }

            $this->flag(secretId: $secret->getId(), reason: 'suite_compromise');
            ++$count;
        }

        return $count;
    }//end flagCompromisedSecrets()

    /**
     * Delete a secret's flag row (delete cascade; idempotent).
     *
     * @param string $secretId The secret UUID
     *
     * @return void
     *
     * @spec openspec/specs/rotation-expiry-policies/spec.md
     */
    public function deleteForSecret(string $secretId): void
    {
        $this->flagMapper->deleteBySecret(secretId: $secretId);
    }//end deleteForSecret()

    /**
     * The head's current key_updated_at (null-tolerant).
     *
     * @param string $secretId The secret UUID
     *
     * @return DateTime|null
     */
    private function headKeyUpdatedAt(string $secretId): ?DateTime
    {
        try {
            return $this->secretMapper->findById($secretId)->getKeyUpdatedAt();
        } catch (DoesNotExistException) {
            return null;
        }
    }//end headKeyUpdatedAt()

    /**
     * Load a flag and assert the caller owns its secret.
     *
     * @param string $flagId The flag UUID
     * @param string $userId The caller
     *
     * @return RotationFlag
     *
     * @throws InvalidArgumentException On not found / foreign owner
     */
    private function loadOwnedFlag(string $flagId, string $userId): RotationFlag
    {
        try {
            $flagRow = $this->flagMapper->findById($flagId);
        } catch (DoesNotExistException) {
            throw new InvalidArgumentException('Flag not found');
        }

        try {
            $secret = $this->secretMapper->findById($flagRow->getSecretId());
        } catch (DoesNotExistException) {
            throw new InvalidArgumentException('Flag not found');
        }

        if ($secret->getOwnerType() !== 'user' || $secret->getOwnerId() !== $userId) {
            throw new InvalidArgumentException('Flag not found');
        }

        return $flagRow;
    }//end loadOwnedFlag()

    /**
     * Dispatch an audit event (identifiers only).
     *
     * @param string              $actorId   The actor
     * @param string              $eventType The event type
     * @param string              $objectId  The object id
     * @param array<string,mixed> $metadata  The whitelisted metadata
     *
     * @return void
     */
    private function dispatchAudit(string $actorId, string $eventType, string $objectId, array $metadata): void
    {
        $this->eventDispatcher?->dispatchTyped(
            $this->auditEvents->forUser(
                actorId: $actorId,
                eventType: $eventType,
                objectType: 'secret',
                objectId: $objectId,
                objectName: '',
                metadata: $metadata,
            )
        );
    }//end dispatchAudit()
}//end class
