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
use OCA\Keepiq\Service\FederatedNotificationDelivery;
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
	 * @param FederatedNotificationDelivery $delivery Delivers with backoff
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		ITimeFactory $time,
		private FederatedNotificationDelivery $delivery,
		private LoggerInterface $logger,
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
	 */
	protected function run($argument): void {
		try {
			$delivered = $this->delivery->retryDue(now: new DateTime());
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
