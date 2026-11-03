<?php

/**
 * Keepiq ExpireShareSaveStagesJob
 *
 * Removes staged share-save chunks past their 30 minutes, so ciphertext a
 * client staged but never finished does not stay on the server.
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

use DateTime;
use OCA\Keepiq\Service\ShareSave\ShareSaveStages;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The share-save stage sweep, every ten minutes.
 *
 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-large-share-save-is-staged-in-chunks
 */
class ExpireShareSaveStagesJob extends TimedJob {

	/**
	 * Constructor for ExpireShareSaveStagesJob.
	 *
	 * @param ITimeFactory    $time   The time factory
	 * @param ShareSaveStages $stages The stages
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		ITimeFactory $time,
		private ShareSaveStages $stages,
		private LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 600);
	}//end __construct()

	/**
	 * Drop expired stages.
	 *
	 * @param mixed $argument Unused job argument
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Mandated by TimedJob::run().
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-large-share-save-is-staged-in-chunks
	 */
	protected function run($argument): void {
		try {
			$this->stages->dropExpired(now: new DateTime());
		} catch (Throwable $exception) {
			$this->logger->error(
				'Keepiq: share-save stage expiry failed: ' . $exception->getMessage(),
				['exception' => $exception, 'app' => 'keepiq']
			);
		}
	}//end run()
}//end class
