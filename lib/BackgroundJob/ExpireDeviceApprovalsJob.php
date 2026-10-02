<?php

/**
 * Keepiq ExpireDeviceApprovalsJob
 *
 * Marks device approval requests past their 15 minute window expired and
 * drops any sealed key never picked up (crypto-new-device-approval D5).
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
use OCA\Keepiq\Service\DeviceApprovalService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The device approval expiry sweep, every five minutes.
 */
class ExpireDeviceApprovalsJob extends TimedJob {

	/**
	 * Constructor for ExpireDeviceApprovalsJob.
	 *
	 * @param ITimeFactory          $time      The time factory
	 * @param DeviceApprovalService $approvals The approval service
	 * @param LoggerInterface       $logger    The logger
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		ITimeFactory $time,
		private DeviceApprovalService $approvals,
		private LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 300);
	}//end __construct()

	/**
	 * Expire lapsed requests.
	 *
	 * @param mixed $argument Unused job argument
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Mandated by TimedJob::run().
	 *
	 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-deny-expiry-audit-and-administrator-switch
	 */
	protected function run($argument): void {
		try {
			$this->approvals->expireLapsed(now: new DateTime());
		} catch (Throwable $exception) {
			$this->logger->error(
				'Keepiq: device approval expiry failed: ' . $exception->getMessage(),
				['exception' => $exception, 'app' => 'keepiq']
			);
		}
	}//end run()
}//end class
