<?php

/**
 * Keepiq Expire Machine Leases Job
 *
 * Hourly transition of past-expiry active machine leases to `expired`
 * (machine-secret-leases §5.1), emitting `lease.expired` and a rotation
 * trigger per affected secret. Only past-expiry `active` rows are aged
 * out; revoked/expired rows are untouched.
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
use OCA\Keepiq\Service\LeaseService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hourly expiry of overdue machine leases.
 */
class ExpireMachineLeasesJob extends TimedJob {
	/**
	 * Constructor for ExpireMachineLeasesJob.
	 *
	 * @param ITimeFactory $time The time factory
	 * @param LeaseService $leaseService The lease service
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		private LeaseService $leaseService,
		private LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 3600);
	}//end __construct()

	/**
	 * Run the lease expiry sweep (fail-soft).
	 *
	 * @param mixed $argument Unused job argument
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is mandated by
	 *   OCP\BackgroundJob\TimedJob::run(); this job carries no cron payload.
	 *
	 * @spec openspec/changes/machine-secret-leases/specs/machine-secret-leases/spec.md#requirement-lease-expiry
	 */
	protected function run($argument): void {
		try {
			$expired = $this->leaseService->expireDue();
			if ($expired > 0) {
				$this->logger->info(
					'Keepiq: expired ' . $expired . ' machine leases',
					['app' => Application::APP_ID]
				);
			}
		} catch (Throwable $exception) {
			$this->logger->warning(
				'Keepiq: lease expiry sweep failed: ' . $exception->getMessage(),
				['app' => Application::APP_ID]
			);
		}
	}//end run()
}//end class
