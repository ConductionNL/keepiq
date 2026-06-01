<?php

/**
 * Doriath Dashboard Service
 *
 * Aggregation service backing the in-app vault summary dashboard.
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

use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\SuiteMigrationMapper;
use Throwable;

/**
 * Aggregates vault summary data for the dashboard from the abstractions
 * that exist today (encryption suites, suite migrations, the CA).
 *
 * Counts that depend on the not-yet-shipped secrets / sharing / requests
 * leaf changes (SecretMapper, SecretShareMapper, FolderMapper,
 * ApplicationMapper) degrade to a safe zero/null. When those leaves land
 * they wire into the clearly-marked slots in {@see self::fetchSummary()}.
 */
class DashboardService
{

    /**
     * The owner type used for user-owned encryption suites.
     *
     * @var string
     */
    private const OWNER_TYPE_USER = 'user';

    /**
     * Constructor for the DashboardService.
     *
     * @param EncryptionSuiteMapper       $suiteMapper     The encryption-suite mapper
     * @param SuiteMigrationMapper        $migrationMapper The suite-migration mapper
     * @param CertificateAuthorityService $caService       The certificate-authority service
     *
     * @return void
     */
    public function __construct(
        private EncryptionSuiteMapper $suiteMapper,
        private SuiteMigrationMapper $migrationMapper,
        private CertificateAuthorityService $caService,
    ) {
    }//end __construct()

    /**
     * Build the dashboard summary for the given user.
     *
     * Admin-only fields (`pending_apps_count`, `ca_status`) are null for
     * non-admins. The KPI counts that depend on the secrets/sharing leaf
     * changes are reported as zero until those abstractions ship.
     *
     * @param string $userId  The current user's identifier.
     * @param bool   $isAdmin Whether the user is a vault administrator.
     *
     * @return array<string,mixed> The summary payload.
     *
     * @spec openspec/changes/implement-dashboard-settings/specs/dashboard/spec.md
     */
    public function fetchSummary(string $userId, bool $isAdmin): array
    {
        $summary = [
            // KPI counts. total_secrets / shared_secrets / folder_count /
            // compromised_count derive from the secrets + sharing leaf
            // changes (SecretMapper, SecretShareMapper, FolderMapper).
            // Those mappers do not exist yet; the counts stay zero until
            // implement-secrets / implement-user-sharing land. Wire the
            // real mapper queries into these slots at that point.
            'total_secrets'      => 0,
            'shared_secrets'     => 0,
            'folder_count'       => 0,
            'compromised_count'  => 0,
            'migration_status'   => $this->resolveMigrationStatus(userId: $userId),
            // Admin-only fields default to null for regular users.
            'pending_apps_count' => null,
            'ca_status'          => null,
        ];

        if ($isAdmin === true) {
            // Pending_apps_count derives from the ApplicationMapper that
            // ships with the secret-requests leaf change. Null until it
            // lands; the dashboard hides the card while it is null.
            $summary['pending_apps_count'] = null;
            $summary['ca_status']          = $this->resolveCaStatus();
        }

        return $summary;
    }//end fetchSummary()

    /**
     * Resolve the in-progress migration (if any) for the user's suites.
     *
     * Returns null when the user owns no suites or none of them have an
     * in-progress migration.
     *
     * @param string $userId The current user's identifier.
     *
     * @return array<string,mixed>|null The migration status, or null.
     */
    private function resolveMigrationStatus(string $userId): ?array
    {
        try {
            $suites   = $this->suiteMapper->findByOwner(self::OWNER_TYPE_USER, $userId);
            $suiteIds = array_map(
                static fn ($suite) => $suite->getId(),
                $suites
            );

            $migration = $this->migrationMapper->findInProgressBySuiteIds($suiteIds);
            if ($migration === null) {
                return null;
            }

            return $migration->jsonSerialize();
        } catch (Throwable) {
            return null;
        }
    }//end resolveMigrationStatus()

    /**
     * Resolve the certificate-authority health status for admins.
     *
     * Delegates to the CertificateAuthorityService; any failure degrades
     * to null so the dashboard simply omits the CA card rather than
     * erroring the whole summary.
     *
     * @return array<string,mixed>|null The CA status, or null.
     */
    private function resolveCaStatus(): ?array
    {
        try {
            return $this->caService->getStatus();
        } catch (Throwable) {
            return null;
        }
    }//end resolveCaStatus()
}//end class
