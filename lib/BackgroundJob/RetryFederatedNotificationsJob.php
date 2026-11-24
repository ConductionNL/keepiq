<?php

/**
 * Keepiq Retry Federated Notifications Job
 *
 * Retries the OCM notifications of federated shares that did not arrive
 * (sharing-federated-recipients D5, task 4.2), with the backoff of
 * FederatedNotificationDelivery. Every minute; a run touches only rows
 * whose wait is over.
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
use OCA\Keepiq\Kernel\Contracts\FederatedNotificationDeliveryInterface;
use OCA\Keepiq\Service\PartnerShareRevoker;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Retries failed federated share notifications.
 *
 * @spec openspec/specs/federated-sharing/spec.md#requirement-owner-updates-reach-the-remote-copy-and-revocation-removes-it
 */
class RetryFederatedNotificationsJob extends TimedJob {
	/**
	 * Constructor for RetryFederatedNotificationsJob.
	 *
	 * @param ITimeFactory $time The time factory
	 * @param FederatedNotificationDeliveryInterface $delivery Delivers with backoff
	 * @param LoggerInterface $logger The logger
	 * @param PartnerShareRevoker $partnerShares Drops the unshares to partners that were removed
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		ITimeFactory $time,
		private FederatedNotificationDeliveryInterface $delivery,
		private LoggerInterface $logger,
		private PartnerShareRevoker $partnerShares,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 60);
	}//end __construct()

	/**
	 * Retry what is due.
	 *
	 * @param mixed $argument Unused job argument
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Signature fixed by TimedJob.
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-owner-updates-reach-the-remote-copy-and-revocation-removes-it
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-a-removed-partner-is-not-told
	 */
	protected function run($argument): void {
		try {
			$now = new DateTime();
			// First: an instance that is no longer a partner is sent no unshare.
			$this->partnerShares->dropUnsharesToRemovedPartners(now: $now);
			$delivered = $this->delivery->retryDue(now: $now);
			if ($delivered > 0) {
				$this->logger->info('Keepiq: delivered ' . $delivered . ' federated share notification(s) on retry', ['app' => 'keepiq']);
			}
		} catch (Throwable $exception) {
			$this->logger->error(
				'Keepiq: federated notification retry failed: ' . $exception->getMessage(),
				['exception' => $exception, 'app' => 'keepiq']
			);
		}
	}//end run()
}//end class
