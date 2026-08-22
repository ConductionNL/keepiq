<?php

/**
 * Keepiq Deliver SIEM Events Job
 *
 * Minute-cadence drain of the SIEM forwarding queue (siem-audit-export
 * §4.1): due pending rows per enabled sink in bounded batches, with
 * backoff and dead-lettering handled by the service.
 *
 * @category BackgroundJob
 * @package  OCA\Keepiq\BackgroundJob
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

namespace OCA\Keepiq\BackgroundJob;

use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Service\SiemService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Minute-cadence SIEM queue drain.
 */
class DeliverSiemEventsJob extends TimedJob {
	/**
	 * Constructor for DeliverSiemEventsJob.
	 *
	 * @param ITimeFactory $time The time factory
	 * @param SiemService $siemService The SIEM service
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		private SiemService $siemService,
		private LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 60);
	}//end __construct()

	/**
	 * Run the drain (fail-soft).
	 *
	 * @param mixed $argument Unused job argument
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is mandated by
	 *   OCP\BackgroundJob\TimedJob::run(); this job carries no cron payload.
	 *
	 * @spec openspec/changes/siem-audit-export/specs/siem-audit-export/spec.md
	 */
	protected function run($argument): void {
		try {
			$this->siemService->deliverDue();
		} catch (Throwable $exception) {
			$this->logger->warning(
				'Keepiq: SIEM delivery drain failed: ' . $exception->getMessage(),
				['app' => Application::APP_ID]
			);
		}
	}//end run()
}//end class
