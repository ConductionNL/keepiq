<?php

/**
 * Doriath Purge Audit Log Background Job
 *
 * Nightly retention enforcement for the append-only audit trail
 * (add-secret-audit-trail §4.3): deletes audit entries older than the
 * admin-configured retention window (audit_retention_days, default 365, hard
 * floor 30) in bounded batches. This purge and account-deletion anonymization
 * are the only two paths permitted to mutate the log. Registered via
 * info.xml <background-jobs> alongside the CA renewal jobs (the app's existing
 * pattern) — Nextcloud auto-schedules TimedJobs listed there, so no
 * IJobList enqueue or non-existent IRegistrationContext::registerJob() call is
 * needed.
 *
 * @category BackgroundJob
 * @package  OCA\Doriath\BackgroundJob
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

namespace OCA\Doriath\BackgroundJob;

use OCA\Doriath\AppInfo\Application;
use OCA\Doriath\Service\AuditService;
use OCA\Doriath\Service\SettingsService;
use OCP\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Nightly purge of audit entries older than the retention window.
 */
class PurgeAuditLogJob extends TimedJob
{
    /**
     * Constructor for PurgeAuditLogJob.
     *
     * @param ITimeFactory    $time         The time factory
     * @param AuditService    $auditService The audit service
     * @param IAppConfig      $appConfig    The app config
     * @param LoggerInterface $logger       The logger
     *
     * @return void
     */
    public function __construct(
        ITimeFactory $time,
        private AuditService $auditService,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        $this->setInterval(seconds: 86400);
    }//end __construct()

    /**
     * Run the purge: delete entries older than the retention window.
     *
     * @param mixed $argument The job argument
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is mandated by
     *   OCP\BackgroundJob\TimedJob::run(); this job carries no cron payload.
     *
     * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-4.3
     */
    protected function run($argument): void
    {
        $retentionDays = $this->appConfig->getValueInt(
            Application::APP_ID,
            'audit_retention_days',
            SettingsService::AUDIT_RETENTION_DEFAULT
        );

        // Defensive floor: never purge with a window below the hard minimum,
        // even if the stored value was tampered with directly.
        if ($retentionDays < SettingsService::AUDIT_RETENTION_MIN) {
            $retentionDays = SettingsService::AUDIT_RETENTION_MIN;
        }

        try {
            $deleted = $this->auditService->purge($retentionDays);
            if ($deleted > 0) {
                $this->logger->info(
                    "Doriath: purged {$deleted} audit entries older than {$retentionDays} days"
                );
            }
        } catch (Throwable $e) {
            $this->logger->error(
                'Doriath: audit-log purge failed: '.$e->getMessage(),
                ['exception' => $e]
            );
        }
    }//end run()
}//end class
