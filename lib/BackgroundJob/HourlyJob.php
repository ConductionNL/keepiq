<?php

/**
 * Keepiq Hourly Job
 *
 * @category BackgroundJob
 * @package  OCA\Keepiq\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\BackgroundJob;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A job that runs every hour and keeps a failure inside the job: it is logged as an error, after `$failure`.
 *
 * @spec exclude Background-job plumbing; each subclass carries the requirement it runs for.
 */
abstract class HourlyJob extends TimedJob {
	/**
	 * Constructor for HourlyJob.
	 *
	 * @param ITimeFactory $time The time factory
	 * @param LoggerInterface $logger Logs a failed run
	 * @param string $failure What a failed run's log line starts with, before the error
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		ITimeFactory $time,
		private LoggerInterface $logger,
		private string $failure,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 3600);
	}//end __construct()

	/**
	 * The hourly work.
	 *
	 * @return void
	 *
	 * @spec exclude Background-job plumbing; each subclass carries the requirement it runs for.
	 */
	abstract protected function runHourly(): void;

	/**
	 * Run the hourly work; a failure is logged, not thrown.
	 *
	 * @param mixed $argument Unused job argument
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Signature fixed by TimedJob.
	 *
	 * @spec exclude Background-job plumbing; each subclass carries the requirement it runs for.
	 */
	protected function run($argument): void {
		try {
			$this->runHourly();
		} catch (Throwable $exception) {
			$this->logger->error($this->failure . $exception->getMessage(), ['exception' => $exception, 'app' => 'keepiq']);
		}
	}//end run()
}//end class
