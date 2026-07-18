<?php

/**
 * Doriath Lease Service
 *
 * Access-grant lifetime governance for the machine secret-store API
 * (machine-secret-leases §2): grant-or-reuse on fetch, policy-capped
 * TTLs, renewal bounded by `granted_at + max`, revocation with a
 * rotation trigger, and hourly expiry. Scope is grant LIFETIME only —
 * no dynamic credential minting; the ciphertext envelope is untouched.
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
use OCA\Doriath\Db\ApplicationLeasePolicyMapper;
use OCA\Doriath\Db\MachineLease;
use OCA\Doriath\Db\MachineLeaseMapper;
use OCA\Doriath\Event\Audit\AuditEvent;
use OCA\Doriath\Event\Audit\AuditEventTypes;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use Ramsey\Uuid\Uuid;

/**
 * Business logic for the machine-lease lifecycle.
 */
class LeaseService
{
    /**
     * Constructor for LeaseService.
     *
     * @param MachineLeaseMapper           $leaseMapper           The lease mapper
     * @param ApplicationLeasePolicyMapper $policyMapper          The per-app policy mapper
     * @param IAppConfig                   $appConfig             The app config (instance defaults)
     * @param IEventDispatcher|null        $eventDispatcher       The audit dispatcher
     * @param RotationPolicyService|null   $rotationPolicyService The rotation service (revoke/expire trigger)
     *
     * @return void
     */
    public function __construct(
        private MachineLeaseMapper $leaseMapper,
        private ApplicationLeasePolicyMapper $policyMapper,
        private IAppConfig $appConfig,
        private ?IEventDispatcher $eventDispatcher=null,
        private ?RotationPolicyService $rotationPolicyService=null,
    ) {
    }//end __construct()

    /**
     * The effective lease policy of an application: per-app override
     * fields fall through to the instance defaults.
     *
     * @param string $applicationId The application id
     *
     * @return array{defaultTtl:int, maxTtl:int, renewable:bool, blockOnRevoke:bool}
     */
    public function effectivePolicy(string $applicationId): array
    {
        $appId      = Application::APP_ID;
        $defaultTtl = $this->appConfig->getValueInt($appId, 'lease_default_ttl_seconds', 900);
        $maxTtl     = $this->appConfig->getValueInt($appId, 'lease_max_ttl_seconds', 86400);
        $renewable  = $this->appConfig->getValueBool($appId, 'lease_renewable', true);

        try {
            $override = $this->policyMapper->findByApplication(applicationId: $applicationId);
            if ($override->getDefaultTtlSeconds() !== null) {
                $defaultTtl = $override->getDefaultTtlSeconds();
            }

            if ($override->getMaxTtlSeconds() !== null) {
                $maxTtl = $override->getMaxTtlSeconds();
            }

            if ($override->getRenewable() !== null) {
                $renewable = $override->getRenewable();
            }
        } catch (DoesNotExistException) {
            // No override — instance defaults apply.
        }

        return [
            'defaultTtl'    => max(60, $defaultTtl),
            'maxTtl'        => max(60, $maxTtl),
            'renewable'     => $renewable,
            'blockOnRevoke' => $this->appConfig->getValueBool($appId, 'lease_revocation_blocks_refetch', false),
        ];
    }//end effectivePolicy()

    /**
     * Grant a lease on fetch, or reuse the live one WITHOUT extending it
     * (a repeat poll must not creep the expiry; machine-secret-leases
     * §2.1).
     *
     * @param string   $applicationId The calling application
     * @param string   $secretId      The fetched secret
     * @param int|null $requestedTtl  Requested TTL in seconds (null = policy default)
     *
     * @return MachineLease
     *
     * @spec openspec/changes/machine-secret-leases/specs/machine-secret-leases/spec.md#requirement-lease-grant-on-fetch
     */
    public function grantOrReuse(string $applicationId, string $secretId, ?int $requestedTtl=null): MachineLease
    {
        $now  = new DateTime();
        $live = $this->leaseMapper->findLive(applicationId: $applicationId, secretId: $secretId, now: $now);
        if ($live !== null) {
            return $live;
        }

        $policy = $this->effectivePolicy(applicationId: $applicationId);
        $ttl    = $policy['defaultTtl'];
        if ($requestedTtl !== null && $requestedTtl > 0) {
            $ttl = min($requestedTtl, $policy['maxTtl']);
        }

        $lease = new MachineLease();
        $lease->setId(Uuid::uuid4()->toString());
        $lease->setApplicationId($applicationId);
        $lease->setSecretId($secretId);
        $lease->setScope('read');
        $lease->setGrantedAt($now);
        $lease->setExpiresAt((clone $now)->add(new DateInterval('PT'.$ttl.'S')));
        $lease->setStatus('active');
        $lease = $this->leaseMapper->insert($lease);

        $this->dispatchAudit(
            actorId: $applicationId,
            eventType: AuditEventTypes::LEASE_GRANTED,
            lease: $lease,
            extra: ['ttl' => $ttl],
        );

        return $lease;
    }//end grantOrReuse()

    /**
     * Renew a lease: extend to `min(now + default TTL, granted_at + max
     * TTL)`. Refused past max, when non-renewable, or when the lease is
     * not active. Cross-application access throws the SAME not-found as
     * a nonexistent lease.
     *
     * @param string $leaseId       The lease UUID
     * @param string $applicationId The calling application (must own the lease)
     *
     * @return MachineLease
     *
     * @throws DoesNotExistException When the lease is missing or foreign
     * @throws InvalidArgumentException When renewal is refused
     *
     * @spec openspec/changes/machine-secret-leases/specs/machine-secret-leases/spec.md#requirement-lease-renewal
     */
    public function renew(string $leaseId, string $applicationId): MachineLease
    {
        $lease = $this->loadOwned(leaseId: $leaseId, applicationId: $applicationId);
        if ($lease->getStatus() !== 'active') {
            throw new InvalidArgumentException('Lease is not active');
        }

        $policy = $this->effectivePolicy(applicationId: $applicationId);
        if ($policy['renewable'] === false) {
            throw new InvalidArgumentException('Leases are not renewable for this application');
        }

        $now     = new DateTime();
        $granted = $lease->getGrantedAt() ?? $now;
        $hardCap = (clone $granted)->add(new DateInterval('PT'.$policy['maxTtl'].'S'));
        $target  = (clone $now)->add(new DateInterval('PT'.$policy['defaultTtl'].'S'));
        if ($target > $hardCap) {
            $target = $hardCap;
        }

        $current = $lease->getExpiresAt();
        if ($current !== null && $target <= $current) {
            throw new InvalidArgumentException('Lease has reached its maximum lifetime');
        }

        $lease->setExpiresAt($target);
        $lease->setRenewedCount($lease->getRenewedCount() + 1);
        $lease->setLastRenewedAt($now);
        $lease = $this->leaseMapper->update($lease);

        $this->dispatchAudit(
            actorId: $applicationId,
            eventType: AuditEventTypes::LEASE_RENEWED,
            lease: $lease,
            extra: ['renewedCount' => $lease->getRenewedCount()],
        );

        return $lease;
    }//end renew()

    /**
     * Revoke a lease (admin / owner / holding application) and raise a
     * rotation flag on the leased secret — a revocation implies the
     * holder should no longer be trusted with the current value.
     *
     * @param MachineLease $lease The lease row (caller pre-authorized)
     * @param string       $actor The revoking actor (user id or 'self')
     *
     * @return MachineLease
     *
     * @spec openspec/changes/machine-secret-leases/specs/machine-secret-leases/spec.md#requirement-lease-revocation
     */
    public function revoke(MachineLease $lease, string $actor): MachineLease
    {
        if ($lease->getStatus() === 'revoked') {
            return $lease;
        }

        $lease->setStatus('revoked');
        $lease->setRevokedAt(new DateTime());
        $lease->setRevokedBy($actor);
        $lease = $this->leaseMapper->update($lease);

        $this->dispatchAudit(
            actorId: $actor,
            eventType: AuditEventTypes::LEASE_REVOKED,
            lease: $lease,
        );

        // Rotation trigger (idempotent one-open-flag-per-secret).
        $this->rotationPolicyService?->flag(secretId: $lease->getSecretId(), reason: 'lease_revoked');

        return $lease;
    }//end revoke()

    /**
     * Load a lease owned by an application; a foreign lease throws the
     * SAME exception as a missing one (no existence oracle).
     *
     * @param string $leaseId       The lease UUID
     * @param string $applicationId The claiming application
     *
     * @return MachineLease
     *
     * @throws DoesNotExistException When missing or foreign
     */
    public function loadOwned(string $leaseId, string $applicationId): MachineLease
    {
        $lease = $this->leaseMapper->findById($leaseId);
        if ($lease->getApplicationId() !== $applicationId) {
            throw new DoesNotExistException('Lease not found');
        }

        return $lease;
    }//end loadOwned()

    /**
     * Whether a fetch must be refused because the application's latest
     * lease on the secret is revoked and block-on-revoke is enabled
     * (machine-secret-leases §3.2; default off keeps connectors
     * available via a fresh grant).
     *
     * @param string $applicationId The calling application
     * @param string $secretId      The fetched secret
     *
     * @return bool
     */
    public function fetchBlocked(string $applicationId, string $secretId): bool
    {
        $policy = $this->effectivePolicy(applicationId: $applicationId);
        if ($policy['blockOnRevoke'] === false) {
            return false;
        }

        $latest = $this->leaseMapper->findLatest(applicationId: $applicationId, secretId: $secretId);

        return $latest !== null && $latest->getStatus() === 'revoked';
    }//end fetchBlocked()

    /**
     * Transition past-expiry active leases to `expired` (hourly job) and
     * raise a rotation flag per affected secret.
     *
     * @return int Leases expired
     *
     * @spec openspec/changes/machine-secret-leases/specs/machine-secret-leases/spec.md#requirement-lease-expiry
     */
    public function expireDue(): int
    {
        $count = 0;
        foreach ($this->leaseMapper->findExpiredActive(now: new DateTime()) as $lease) {
            $lease->setStatus('expired');
            $this->leaseMapper->update($lease);

            $this->dispatchAudit(
                actorId: 'system',
                eventType: AuditEventTypes::LEASE_EXPIRED,
                lease: $lease,
            );
            $this->rotationPolicyService?->flag(secretId: $lease->getSecretId(), reason: 'lease_expired');
            ++$count;
        }

        return $count;
    }//end expireDue()

    /**
     * All leases of an application, newest first.
     *
     * @param string $applicationId The application id
     *
     * @return MachineLease[]
     */
    public function listForApplication(string $applicationId): array
    {
        return $this->leaseMapper->findByApplication(applicationId: $applicationId);
    }//end listForApplication()

    /**
     * Store a per-application policy override (admin surface).
     *
     * @param string    $applicationId The application id
     * @param int|null  $defaultTtl    Default TTL seconds (null = inherit)
     * @param int|null  $maxTtl        Max TTL seconds (null = inherit)
     * @param bool|null $renewable     Renewability (null = inherit)
     *
     * @return void
     *
     * @throws InvalidArgumentException On non-positive TTLs
     */
    public function setPolicyOverride(string $applicationId, ?int $defaultTtl, ?int $maxTtl, ?bool $renewable): void
    {
        if (($defaultTtl !== null && $defaultTtl < 60) || ($maxTtl !== null && $maxTtl < 60)) {
            throw new InvalidArgumentException('Lease TTLs must be at least 60 seconds');
        }

        $this->policyMapper->upsert(
            applicationId: $applicationId,
            defaultTtlSeconds: $defaultTtl,
            maxTtlSeconds: $maxTtl,
            renewable: $renewable,
        );
    }//end setPolicyOverride()

    /**
     * Dispatch a lease audit event (identifiers + lifetimes only; the
     * whitelist strips anything else).
     *
     * @param string              $actorId   The actor (application id, user id, or 'system')
     * @param string              $eventType The lease event type
     * @param MachineLease        $lease     The lease row
     * @param array<string,mixed> $extra     Additional whitelisted metadata
     *
     * @return void
     */
    private function dispatchAudit(string $actorId, string $eventType, MachineLease $lease, array $extra=[]): void
    {
        $this->eventDispatcher?->dispatchTyped(
            AuditEvent::forApplication(
                actorId: $actorId,
                eventType: $eventType,
                objectType: 'lease',
                objectId: $lease->getId(),
                objectName: '',
                metadata: array_merge(
                    [
                        'leaseId'   => $lease->getId(),
                        'secretId'  => $lease->getSecretId(),
                        'expiresAt' => $lease->getExpiresAt()?->format('c'),
                    ],
                    $extra
                ),
            )
        );
    }//end dispatchAudit()
}//end class
