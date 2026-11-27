<?php

/**
 * Keepiq Root Renewed Listener
 *
 * Queues the renewal notices to partners once a renewed root is committed (partner-root-renewal-announcement D2).
 * RetryRootRenewalNoticesJob sends them.
 *
 * @category Listener
 * @package  OCA\Keepiq\Listener
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

namespace OCA\Keepiq\Listener;

use OCA\Keepiq\Event\RootRenewedEvent;
use OCA\Keepiq\Kernel\AppIdentity;
use OCA\Keepiq\Service\RootRenewalNoticeDelivery;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Queues a renewal notice for every partner with inbound sharing on.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-a-renewed-root-is-announced-to-partners
 */
class RootRenewedListener implements IEventListener {
	/**
	 * Constructor for RootRenewedListener.
	 *
	 * @param RootRenewalNoticeDelivery $notices Queues the notices
	 * @param ITimeFactory $time The clock
	 * @param LoggerInterface $logger Logs a failure to queue
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private RootRenewalNoticeDelivery $notices,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Queue the notices. A failure is logged: the renewal stands, and partners find it in their next lookup.
	 *
	 * @param Event $event The event
	 *
	 * @return void
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#scenario-the-partner-renews-its-root
	 */
	public function handle(Event $event): void {
		if (($event instanceof RootRenewedEvent) === false) {
			return;
		}

		try {
			$this->notices->announce(now: $this->time->getDateTime());
		} catch (Throwable $exception) {
			$this->logger->warning(
				'Keepiq: queueing the root renewal notices failed: ' . $exception->getMessage(),
				['exception' => $exception, 'app' => AppIdentity::APP_ID]
			);
		}
	}//end handle()
}//end class
