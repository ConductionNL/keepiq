<?php

/**
 * Doriath Rotation Policy Service
 *
 * Business logic for credential rotation policies + expiry
 * (rotation-expiry-policies §2/§3): effective-expiry resolution from
 * server-visible fields only (never decryption), type/folder policy
 * CRUD, per-secret expiry, and the rotation-flag lifecycle (flag /
 * mark-rotated with a proven key_updated_at advance / dismiss).
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

use DateInterval;
use DateTime;
use InvalidArgumentException;
use OCA\Doriath\AppInfo\Application;
use OCA\Doriath\Db\ExpiryPolicy;
use OCA\Doriath\Db\ExpiryPolicyMapper;
use OCA\Doriath\Db\RotationFlag;
use OCA\Doriath\Db\RotationFlagMapper;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Event\Audit\AuditEvent;
use OCA\Doriath\Event\Audit\AuditEventFactory;
use OCA\Doriath\Event\Audit\AuditEventTypes;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use Ramsey\Uuid\Uuid;

/**
 * Business logic for the rotation/expiry lifecycle.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The resolution +
 *   flag invariants live in one place across policy/flag/secret mappers.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)   One method per API op.
 */
class RotationPolicyService
{
    /**
     * Constructor for RotationPolicyService.
     *
     * @param ExpiryPolicyMapper    $policyMapper    The policy mapper
     * @param RotationFlagMapper    $flagMapper      The flag mapper
     * @param SecretMapper          $secretMapper    The secret mapper
     * @param IAppConfig            $appConfig       The app config (admin defaults)
     * @param IEventDispatcher|null $eventDispatcher The audit event dispatcher
     * @param IConfig|null          $config          The NC config (user max-age override)
     * @param AuditEventFactory     $auditEvents     The audit-event factory
     *
     * @return void
     */
    public function __construct(
        private ExpiryPolicyMapper $policyMapper,
        private RotationFlagMapper $flagMapper,
        private SecretMapper $secretMapper,
        private IAppConfig $appConfig,
        private ?IEventDispatcher $eventDispatcher=null,
        private ?IConfig $config=null,
        private AuditEventFactory $auditEvents=new AuditEventFactory(),
    ) {
    }//end __construct()

    /**
     * Resolve a secret's effective expiry: the EARLIEST of the per-secret
     * `expires_at` and each applicable policy's `key_updated_at +
     * max_age_days` (falling back to `created_at` when key age is null),
     * including the admin default max age. Server-visible fields only.
     *
     * @param Secret $secret The secret row
     *
     * @return DateTime|null The effective expiry (null = never expires)
     *
     * @spec openspec/changes/rotation-expiry-policies/specs/rotation-expiry-policies/spec.md#requirement-effective-expiry-resolution
     */
    public function resolveEffectiveExpiry(Secret $secret): ?DateTime
    {
        $candidates = [];

        if ($secret->getExpiresAt() !== null) {
            $candidates[] = $secret->getExpiresAt();
        }

        $base = $secret->getKeyUpdatedAt() ?? $secret->getCreatedAt();
        if ($base !== null) {
            foreach ($this->policyMapper->findApplicable(ownerId: $secret->getOwnerId()) as $policy) {
                if ($this->policyApplies(policy: $policy, secret: $secret) === false) {
                    continue;
                }

                $maxAge = $policy->getMaxAgeDays();
                if ($maxAge !== null && $maxAge > 0) {
                    $candidates[] = (clone $base)->add(new DateInterval('P'.$maxAge.'D'));
                }
            }

            // Per-user override (0 = off), then instance-wide admin
            // default (0 = off) — precedence is moot under min().
            $userMax = (int) ($this->config?->getUserValue(
                $secret->getOwnerId(),
                Application::APP_ID,
                'expiry_max_age_days',
                '0'
            ) ?? 0);
            if ($userMax > 0) {
                $candidates[] = (clone $base)->add(new DateInterval('P'.$userMax.'D'));
            }

            $adminMax = $this->appConfig->getValueInt(Application::APP_ID, 'expiry_default_max_age_days', 0);
            if ($adminMax > 0) {
                $candidates[] = (clone $base)->add(new DateInterval('P'.$adminMax.'D'));
            }
        }//end if

        if ($candidates === []) {
            return null;
        }

        return min($candidates);
    }//end resolveEffectiveExpiry()

    /**
     * Whether a policy's scope matches a secret.
     *
     * @param ExpiryPolicy $policy The policy
     * @param Secret       $secret The secret
     *
     * @return bool
     */
    private function policyApplies(ExpiryPolicy $policy, Secret $secret): bool
    {
        if ($policy->getScope() === 'type') {
            return $policy->getScopeId() === (string) $secret->getTypeId();
        }

        if ($policy->getScope() === 'folder') {
            return $policy->getScopeId() === (string) $secret->getFolderId();
        }

        return false;
    }//end policyApplies()

    /**
     * List the caller's policies plus the instance-wide rows.
     *
     * @param string $userId The caller
     *
     * @return ExpiryPolicy[]
     */
    public function listPolicies(string $userId): array
    {
        return $this->policyMapper->findApplicable(ownerId: $userId);
    }//end listPolicies()

    /**
     * Create or update a type/folder policy for the caller.
     *
     * @param string   $userId       The caller
     * @param string   $scope        The scope (`type`|`folder`)
     * @param string   $scopeId      The scoped type/folder id
     * @param int|null $maxAgeDays   Max credential age in days (null = reminder-only)
     * @param int[]    $reminderDays Reminder thresholds in days
     *
     * @return ExpiryPolicy
     *
     * @throws InvalidArgumentException On invalid input
     *
     * @spec openspec/changes/rotation-expiry-policies/specs/rotation-expiry-policies/spec.md#requirement-expiry-policies
     */
    public function upsertPolicy(
        string $userId,
        string $scope,
        string $scopeId,
        ?int $maxAgeDays,
        array $reminderDays=[],
    ): ExpiryPolicy {
        if (in_array($scope, ['type', 'folder'], true) === false) {
            throw new InvalidArgumentException('scope must be type or folder');
        }

        if ($scopeId === '') {
            throw new InvalidArgumentException('scopeId is required');
        }

        if ($maxAgeDays !== null && $maxAgeDays < 1) {
            throw new InvalidArgumentException('maxAgeDays must be positive or null');
        }

        $reminderDays = array_values(array_filter(array_map('intval', $reminderDays), static fn ($days) => $days > 0));

        $encodedReminders = null;
        if ($reminderDays !== []) {
            $encodedReminders = (string) json_encode($reminderDays);
        }

        $policy = null;
        foreach ($this->policyMapper->findByOwner(ownerId: $userId) as $existing) {
            if ($existing->getScope() === $scope && $existing->getScopeId() === $scopeId) {
                $policy = $existing;
                break;
            }
        }

        $isNew = ($policy === null);
        if ($isNew === true) {
            $policy = new ExpiryPolicy();
            $policy->setId(Uuid::uuid4()->toString());
            $policy->setOwnerId($userId);
            $policy->setScope($scope);
            $policy->setScopeId($scopeId);
            $policy->setCreatedBy($userId);
            $policy->setCreatedAt(new DateTime());
        }

        $policy->setMaxAgeDays($maxAgeDays);
        $policy->setReminderDays($encodedReminders);
        $policy->setUpdatedAt(new DateTime());

        $policy = match ($isNew) {
            true => $this->policyMapper->insert($policy),
            false => $this->policyMapper->update($policy),
        };

        $this->dispatchAudit(
            actorId: $userId,
            eventType: AuditEventTypes::POLICY_EXPIRY_CHANGED,
            objectId: $policy->getId(),
            metadata: [
                'scope'   => $scope,
                'scopeId' => $scopeId,
            ],
        );

        return $policy;
    }//end upsertPolicy()

    /**
     * Delete one of the caller's policies.
     *
     * @param string $policyId The policy UUID
     * @param string $userId   The caller
     *
     * @return void
     *
     * @throws InvalidArgumentException On not found / foreign owner
     */
    public function deletePolicy(string $policyId, string $userId): void
    {
        try {
            $policy = $this->policyMapper->findById($policyId);
        } catch (DoesNotExistException) {
            throw new InvalidArgumentException('Policy not found');
        }

        if ($policy->getOwnerId() !== $userId) {
            throw new InvalidArgumentException('Policy not found');
        }

        $this->policyMapper->delete($policy);
    }//end deletePolicy()

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
     * @spec openspec/changes/rotation-expiry-policies/specs/rotation-expiry-policies/spec.md#requirement-rotation-flags
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
     * @spec openspec/changes/rotation-expiry-policies/specs/rotation-expiry-policies/spec.md#requirement-rotation-flags
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
     * @spec openspec/changes/rotation-expiry-policies/specs/rotation-expiry-policies/spec.md#requirement-mark-rotated
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
     * @spec openspec/changes/rotation-expiry-policies/specs/rotation-expiry-policies/spec.md#requirement-mark-rotated
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
     * @spec openspec/changes/rotation-expiry-policies/specs/rotation-expiry-policies/spec.md#requirement-rotation-flags
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
