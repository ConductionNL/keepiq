<?php

/**
 * Doriath Refresh Compliance Metrics Job
 *
 * Daily recompute of the compliance metrics cache (compliance-reporting
 * §3.1) so the admin posture card renders from a warm aggregate.
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
use OCA\Doriath\Service\ComplianceReportService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Daily compliance metrics cache refresh.
 */
class RefreshComplianceMetricsJob extends TimedJob {
	/**
	 * Constructor for RefreshComplianceMetricsJob.
	 *
	 * @param ITimeFactory $time The time factory
	 * @param ComplianceReportService $service The compliance service
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		private ComplianceReportService $service,
		private LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 86400);
	}//end __construct()

	/**
	 * Run the refresh (fail-soft).
	 *
	 * @param mixed $argument Unused job argument
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is mandated by
	 *   OCP\BackgroundJob\TimedJob::run(); this job carries no cron payload.
	 *
	 * @spec openspec/changes/compliance-reporting/specs/compliance-reporting/spec.md
	 */
	protected function run($argument): void {
		try {
			$this->service->refreshMetricsCache();
		} catch (Throwable $exception) {
			$this->logger->warning(
				'Doriath: compliance metrics refresh failed: ' . $exception->getMessage(),
				['app' => Application::APP_ID]
			);
		}
	}//end run()
}//end class
