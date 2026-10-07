<?php

/**
 * Keepiq ExpireRecoveryRequestsJob
 *
 * Expires account recovery requests past their 72 hours and drops any
 * sealed result never collected (crypto-organisation-account-recovery D3).
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
use OCA\Keepiq\Service\RecoveryRequestService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The account recovery expiry sweep, every hour.
 */
class ExpireRecoveryRequestsJob extends TimedJob {

	/**
	 * Constructor for ExpireRecoveryRequestsJob.
	 *
	 * @param ITimeFactory          $time      The time factory
	 * @param RecoveryRequestService $requests  The request service
	 * @param LoggerInterface       $logger    The logger
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		ITimeFactory $time,
		private RecoveryRequestService $requests,
		private LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 3600);
	}//end __construct()

	/**
	 * Expire lapsed recovery requests.
	 *
	 * @param mixed $argument Unused job argument
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Mandated by TimedJob::run().
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-a-recovery-request-carries-a-one-time-key-and-a-verification-phrase
	 */
	protected function run($argument): void {
		try {
			$this->requests->expireLapsed(now: new DateTime());
		} catch (Throwable $exception) {
			$this->logger->error(
				'Keepiq: account recovery expiry failed: ' . $exception->getMessage(),
				['exception' => $exception, 'app' => 'keepiq']
			);
		}
	}//end run()
}//end class
