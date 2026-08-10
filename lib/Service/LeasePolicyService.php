<?php

/**
 * Doriath Lease Policy Service
 *
 * Lease POLICY resolution and administration, split out of LeaseService
 * (machine-secret-leases §2). Knows the instance defaults held in the app
 * config, the per-application override row, and the precedence between
 * them. It never touches a lease row — the lifetime governance of an
 * individual lease lives in LeaseService.
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

use InvalidArgumentException;
use OCA\Doriath\AppInfo\Application;
use OCA\Doriath\Db\ApplicationLeasePolicyMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;

/**
 * Resolves and stores the effective machine-lease policy of an application.
 */
class LeasePolicyService
{
    /**
     * Constructor for LeasePolicyService.
     *
     * @param ApplicationLeasePolicyMapper $policyMapper The per-app policy mapper
     * @param IAppConfig                   $appConfig    The app config (instance defaults)
     *
     * @return void
     *
     * @spec exclude Constructor wiring only.
     */
    public function __construct(
        private ApplicationLeasePolicyMapper $policyMapper,
        private IAppConfig $appConfig,
    ) {
    }//end __construct()

    /**
     * The effective lease policy of an application: per-app override
     * fields fall through to the instance defaults.
     *
     * @param string $applicationId The application id
     *
     * @return array{defaultTtl:int, maxTtl:int, renewable:bool, blockOnRevoke:bool}
     *
     * @spec openspec/changes/machine-secret-leases/specs/machine-secret-leases/spec.md
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
     *
     * @spec openspec/changes/machine-secret-leases/specs/machine-secret-leases/spec.md
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
}//end class
