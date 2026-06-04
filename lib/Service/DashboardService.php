<?php

/**
 * Doriath Dashboard Service
 *
 * Aggregates the per-user vault summary shown on the Doriath dashboard from the
 * existing encryption-suite, migration, and link-share data, plus the admin-only
 * certificate-authority health. Keeps the DashboardController thin (ADR-008).
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
use OCA\Doriath\Db\LinkShareMapper;
use OCA\Doriath\Db\SuiteMigrationMapper;
use Throwable;

/**
 * Aggregation service backing the dashboard summary endpoint.
 */
class DashboardService
{
    /**
     * Constructor for the DashboardService.
     *
     * @param EncryptionSuiteMapper       $suiteMapper     The encryption suite mapper
     * @param SuiteMigrationMapper        $migrationMapper The suite migration mapper
     * @param LinkShareMapper             $linkShareMapper The link share mapper
     * @param CertificateAuthorityService $caService       The certificate authority service
     *
     * @return void
     */
    public function __construct(
        private EncryptionSuiteMapper $suiteMapper,
        private SuiteMigrationMapper $migrationMapper,
        private LinkShareMapper $linkShareMapper,
        private CertificateAuthorityService $caService,
    ) {
    }//end __construct()

    /**
     * Build the vault summary for a single user.
     *
     * Counts are derived from the user's encryption suites (active total and the
     * compromised subset), the link shares they have created, and the in-progress
     * migration state of their active suite. The admin-only fields (ca_status,
     * pending_apps_count) are populated only when $isAdmin is true; for normal
     * users they are returned as null so the frontend can hide those panels.
     *
     * @param string $userId  The current user's identifier.
     * @param bool   $isAdmin Whether the current user is an administrator.
     *
     * @return array<string,mixed> The dashboard summary payload.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     *
     * @spec openspec/changes/implement-dashboard-settings/tasks.md#task-1.1
     */
    public function fetchSummary(string $userId, bool $isAdmin): array
    {
        $suites           = $this->suiteMapper->findByOwner(ownerType: 'user', ownerId: $userId);
        $activeSecrets    = 0;
        $compromisedCount = 0;
        foreach ($suites as $suite) {
            $status = $suite->getStatus();
            if ($status === 'active') {
                $activeSecrets++;
            }

            if ($status === 'compromised') {
                $compromisedCount++;
            }
        }

        $summary = [
            'total_secrets'      => $activeSecrets,
            'shared_secrets'     => $this->countSharedByUser(userId: $userId),
            'folder_count'       => 0,
            'compromised_count'  => $compromisedCount,
            'migration_status'   => $this->resolveMigrationStatus(userId: $userId),
            'pending_apps_count' => null,
            'ca_status'          => null,
        ];

        if ($isAdmin === true) {
            // Application approval queue depends on the not-yet-merged
            // implement-secret-requests change; until its Application mapper
            // lands, the admin pending-apps counter is reported as 0 rather
            // than guessed. The frontend hides the card when the count is 0.
            $summary['pending_apps_count'] = 0;
            $summary['ca_status']          = $this->resolveCaStatus();
        }

        return $summary;
    }//end fetchSummary()

    /**
     * Count the link shares created by the given user.
     *
     * @param string $userId The user identifier.
     *
     * @return int The number of link shares the user owns.
     */
    private function countSharedByUser(string $userId): int
    {
        try {
            return count($this->linkShareMapper->findByCreatedBy(userId: $userId));
        } catch (Throwable) {
            return 0;
        }
    }//end countSharedByUser()

    /**
     * Resolve the in-progress migration status for the user's active suite.
     *
     * Returns null when the user has no active suite or no in-progress migration,
     * otherwise an array describing the migration so the dashboard can render a
     * banner. A failed/errored completion is surfaced as completed_with_errors.
     *
     * @param string $userId The user identifier.
     *
     * @return array<string,mixed>|null The migration status, or null when none.
     */
    private function resolveMigrationStatus(string $userId): ?array
    {
        foreach ($this->safeFindSuites(userId: $userId) as $suite) {
            try {
                $migrations = $this->migrationMapper->findBySuiteId(suiteId: $suite->getId());
            } catch (Throwable) {
                continue;
            }

            foreach ($migrations as $migration) {
                $status = $migration->getStatus();
                if ($status === 'in_progress') {
                    return [
                        'state'        => 'in_progress',
                        'migration_id' => $migration->getId(),
                    ];
                }

                if ($status === 'completed_with_errors') {
                    return [
                        'state'        => 'completed_with_errors',
                        'migration_id' => $migration->getId(),
                    ];
                }
            }
        }//end foreach

        return null;
    }//end resolveMigrationStatus()

    /**
     * Fetch the user's suites without throwing.
     *
     * @param string $userId The user identifier.
     *
     * @return array<\OCA\Doriath\Db\EncryptionSuite> The user's suites.
     */
    private function safeFindSuites(string $userId): array
    {
        try {
            return $this->suiteMapper->findByOwner(ownerType: 'user', ownerId: $userId);
        } catch (Throwable) {
            return [];
        }
    }//end safeFindSuites()

    /**
     * Resolve the certificate-authority health for the admin summary.
     *
     * Delegates to the CertificateAuthorityService and never lets a CA failure
     * break the whole dashboard — a degraded CA is reported as not_configured.
     *
     * @return array<string,mixed> The CA status payload.
     */
    private function resolveCaStatus(): array
    {
        try {
            return $this->caService->getStatus();
        } catch (Throwable) {
            return [
                'status'       => 'not_configured',
                'root'         => null,
                'intermediate' => null,
            ];
        }
    }//end resolveCaStatus()
}//end class
