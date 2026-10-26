<?php

/**
 * Keepiq ShareQuarantineScanJob
 *
 * Runs the share quarantine scan (GHSA-m5p9-6pw7-pf3g) until one pass
 * completes, so a scan that failed or stopped during the upgrade still
 * quarantines every planted row.
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

use OCA\Keepiq\Service\ShareQuarantineScanner;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * The share quarantine scan, every fifteen minutes until it has completed.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
 */
class ShareQuarantineScanJob extends TimedJob {

	/**
	 * Constructor for ShareQuarantineScanJob.
	 *
	 * @param ITimeFactory           $time    The time factory
	 * @param ShareQuarantineScanner $scanner The scan
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		ITimeFactory $time,
		private ShareQuarantineScanner $scanner,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 900);
	}//end __construct()

	/**
	 * Run a pass unless one has completed. The scanner logs a failure at error
	 * level and leaves the pass pending for the next run.
	 *
	 * @param mixed $argument Unused job argument
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Mandated by TimedJob::run().
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
	 */
	protected function run($argument): void {
		if ($this->scanner->isComplete() === true) {
			return;
		}

		$this->scanner->run();
	}//end run()
}//end class
