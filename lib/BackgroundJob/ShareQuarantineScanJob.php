<?php

/**
 * Keepiq ShareQuarantineScanJob
 *
 * Runs the share quarantine scan (GHSA-m5p9-6pw7-pf3g) until one pass
 * completes, so a scan that failed or stopped during the upgrade still
 * quarantines every planted row. Once it has completed, it resolves every
 * recipient copy again when a restriction resolve skipped a row the pending
 * scan could not judge.
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
use OCA\Keepiq\Service\ShareRestrictionResolver;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The share quarantine scan, every fifteen minutes until it has completed.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
 */
class ShareQuarantineScanJob extends TimedJob {

	/**
	 * Constructor for ShareQuarantineScanJob.
	 *
	 * @param ITimeFactory             $time         The time factory
	 * @param ShareQuarantineScanner   $scanner      The scan
	 * @param ShareRestrictionResolver $restrictions Resolves the copies the pending scan held back
	 * @param LoggerInterface          $logger       Logs a resolve that fails
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		ITimeFactory $time,
		private ShareQuarantineScanner $scanner,
		private ShareRestrictionResolver $restrictions,
		private LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 900);
	}//end __construct()

	/**
	 * Run a pass unless one has completed, then resolve the held-back copies.
	 * The scanner logs a failure at error level and leaves the pass pending
	 * for the next run.
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
		if ($this->scanner->isComplete() === false) {
			$this->scanner->run();
		}

		$this->resolveHeldBack();
	}//end run()

	/**
	 * Once the scan has completed, resolve every copy again when a restriction
	 * resolve skipped a row the pending scan could not judge. The record is
	 * cleared only once every copy was resolved, so a run that fails or stops
	 * part-way is retried by the next.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
	 */
	private function resolveHeldBack(): void {
		if ($this->scanner->isComplete() === false) {
			return;
		}

		$record = $this->scanner->heldBackResolve();
		if ($record === null) {
			return;
		}

		try {
			$written = $this->restrictions->resolveAll();
		} catch (Throwable $exception) {
			$this->logger->error(
				'GHSA-m5p9-6pw7-pf3g: resolving the copies the pending share quarantine scan held back failed and will run again: '
					.$exception->getMessage(),
				['app' => 'keepiq', 'exception' => $exception]
			);
			return;
		}

		$this->scanner->clearHeldBackResolve(record: $record);
		$this->logger->info(
			'GHSA-m5p9-6pw7-pf3g: resolved the copies the pending share quarantine scan held back; '.$written.' copy(ies) written',
			['app' => 'keepiq']
		);
	}//end resolveHeldBack()
}//end class
