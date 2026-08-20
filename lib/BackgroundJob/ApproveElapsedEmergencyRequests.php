<?php

/**
 * Doriath Emergency-Access Approval Background Job
 *
 * Periodically transitions `requested` emergency-access relationships to
 * `approved` once the grantor-configured wait period has elapsed with no
 * decline (add-emergency-access §2.2 / design D5). Registered via info.xml
 * <background-jobs> alongside the CA and audit-purge jobs; Nextcloud
 * auto-schedules TimedJobs listed there. A grantee's fetch also lazily promotes
 * an elapsed request, so this job is a safety net that keeps state fresh even
 * before a fetch attempt. No key material is touched — only the state column.
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

use OCA\Doriath\Service\EmergencyAccessService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Approve elapsed emergency-access requests on a timer.
 */
class ApproveElapsedEmergencyRequests extends TimedJob {
	/**
	 * Constructor for ApproveElapsedEmergencyRequests.
	 *
	 * @param ITimeFactory $time The time factory
	 * @param EmergencyAccessService $service The emergency-access service
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		private EmergencyAccessService $service,
		private LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		// Hourly — the wait periods are measured in days, so an hourly sweep is
		// well within tolerance while keeping approvals timely.
		$this->setInterval(seconds: 3600);
	}//end __construct()

	/**
	 * Promote every elapsed pending request to `approved`.
	 *
	 * @param mixed $argument The job argument
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is mandated by
	 *   OCP\BackgroundJob\TimedJob::run(); this job carries no cron payload.
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-approval-by-timeout-and-grantee-view-access
	 */
	protected function run($argument): void {
		try {
			$promoted = $this->service->approveElapsed();
			if ($promoted > 0) {
				$this->logger->info('Doriath: approved ' . $promoted . ' elapsed emergency-access request(s)');
			}
		} catch (Throwable $e) {
			$this->logger->error('Doriath: emergency-access approval job failed: ' . $e->getMessage());
		}
	}//end run()
}//end class
