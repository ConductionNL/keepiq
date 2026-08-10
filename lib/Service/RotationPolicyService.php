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
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Event\Audit\AuditEventFactory;
use OCA\Doriath\Event\Audit\AuditEventTypes;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use Ramsey\Uuid\Uuid;

/**
 * Business logic for the expiry-policy half of the rotation/expiry
 * lifecycle. The rotation-FLAG half lives in {@see RotationFlagService};
 * this class stays the API-facing entry point and forwards to it.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The resolution rules
 *   span policy/secret/config sources in one place.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)   One method per API op.
 */
class RotationPolicyService
{
    /**
     * Constructor for RotationPolicyService.
     *
     * @param ExpiryPolicyMapper    $policyMapper    The policy mapper
     * @param RotationFlagService   $flagService     The rotation-flag lifecycle
     * @param IAppConfig            $appConfig       The app config (admin defaults)
     * @param IEventDispatcher|null $eventDispatcher The audit event dispatcher
     * @param IConfig|null          $config          The NC config (user max-age override)
     * @param AuditEventFactory     $auditEvents     The audit-event factory
     *
     * @return void
     */
    public function __construct(
        private ExpiryPolicyMapper $policyMapper,
        private RotationFlagService $flagService,
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
     * @spec openspec/changes/rotation-expiry-policies/specs/rotation-expiry-policies/spec.md
     */
    public function resolveEffectiveExpiry(Secret $secret): ?DateTime
    {
        $candidates = [];

        if ($secret->getExpiresAt() !== null) {
            $candidates[] = $secret->getExpiresAt();
        }

        $base = $secret->getKeyUpdatedAt() ?? $secret->getCreatedAt();
        if ($base !== null) {
            $candidates = array_merge(
                $candidates,
                $this->policyExpiryCandidates(secret: $secret, base: $base),
                $this->configuredExpiryCandidates(secret: $secret, base: $base)
            );
        }

        if ($candidates === []) {
            return null;
        }

        return min($candidates);
    }//end resolveEffectiveExpiry()

    /**
     * The expiry dates implied by every stored policy that scopes this secret.
     *
     * @param Secret   $secret The secret
     * @param DateTime $base   The age baseline (last key rotation, else creation)
     *
     * @return array<int,DateTime>
     */
    private function policyExpiryCandidates(Secret $secret, DateTime $base): array
    {
        $candidates = [];
        foreach ($this->policyMapper->findApplicable(ownerId: $secret->getOwnerId()) as $policy) {
            if ($this->policyApplies(policy: $policy, secret: $secret) === false) {
                continue;
            }

            $maxAge = $policy->getMaxAgeDays();
            if ($maxAge !== null && $maxAge > 0) {
                $candidates[] = (clone $base)->add(new DateInterval('P'.$maxAge.'D'));
            }
        }

        return $candidates;
    }//end policyExpiryCandidates()

    /**
     * The expiry dates implied by the per-user override and the instance-wide
     * admin default. Both use 0 to mean "off"; precedence is moot under min().
     *
     * @param Secret   $secret The secret
     * @param DateTime $base   The age baseline (last key rotation, else creation)
     *
     * @return array<int,DateTime>
     */
    private function configuredExpiryCandidates(Secret $secret, DateTime $base): array
    {
        $candidates = [];

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

        return $candidates;
    }//end configuredExpiryCandidates()

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
        $this->assertPolicyInput(scope: $scope, scopeId: $scopeId, maxAgeDays: $maxAgeDays);

        $encodedReminders = $this->encodeReminderDays(reminderDays: $reminderDays);
        $policy           = $this->findScopedPolicy(userId: $userId, scope: $scope, scopeId: $scopeId);

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
     * Reject a policy the service cannot store.
     *
     * @param string   $scope      The policy scope (`type`|`folder`)
     * @param string   $scopeId    The scoped type or folder UUID
     * @param int|null $maxAgeDays The maximum key age in days, or null for none
     *
     * @return void
     *
     * @throws InvalidArgumentException On an invalid scope, scopeId or age.
     */
    private function assertPolicyInput(string $scope, string $scopeId, ?int $maxAgeDays): void
    {
        if (in_array($scope, ['type', 'folder'], true) === false) {
            throw new InvalidArgumentException('scope must be type or folder');
        }

        if ($scopeId === '') {
            throw new InvalidArgumentException('scopeId is required');
        }

        if ($maxAgeDays !== null && $maxAgeDays < 1) {
            throw new InvalidArgumentException('maxAgeDays must be positive or null');
        }
    }//end assertPolicyInput()

    /**
     * Normalise the reminder thresholds to a stored JSON list of positive
     * ints, or null when none survive.
     *
     * @param array<int,mixed> $reminderDays The requested thresholds
     *
     * @return string|null
     */
    private function encodeReminderDays(array $reminderDays): ?string
    {
        $thresholds = array_values(
            array_filter(array_map('intval', $reminderDays), static fn ($days) => $days > 0)
        );
        if ($thresholds === []) {
            return null;
        }

        return (string) json_encode($thresholds);
    }//end encodeReminderDays()

    /**
     * The caller's existing policy for a scope, or null when they have none.
     *
     * @param string $userId  The owning user
     * @param string $scope   The policy scope (`type`|`folder`)
     * @param string $scopeId The scoped type or folder UUID
     *
     * @return ExpiryPolicy|null
     */
    private function findScopedPolicy(string $userId, string $scope, string $scopeId): ?ExpiryPolicy
    {
        foreach ($this->policyMapper->findByOwner(ownerId: $userId) as $existing) {
            if ($existing->getScope() === $scope && $existing->getScopeId() === $scopeId) {
                return $existing;
            }
        }

        return null;
    }//end findScopedPolicy()

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
     * @spec openspec/changes/rotation-expiry-policies/specs/rotation-expiry-policies/spec.md
     */
    public function flag(string $secretId, string $reason, ?string $flaggedBy=null): RotationFlag
    {
        return $this->flagService->flag(secretId: $secretId, reason: $reason, flaggedBy: $flaggedBy);
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
        return $this->flagService->flagBatch(userId: $userId, secretIds: $secretIds);
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
        return $this->flagService->openFlags(userId: $userId);
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
        return $this->flagService->markRotated(flagId: $flagId, userId: $userId);
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
        $this->flagService->dismiss(flagId: $flagId, userId: $userId);
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
        return $this->flagService->flagCompromisedSecrets(ownerId: $ownerId);
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
        $this->flagService->deleteForSecret(secretId: $secretId);
    }//end deleteForSecret()

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
