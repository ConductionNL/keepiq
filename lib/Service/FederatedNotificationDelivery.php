<?php

/**
 * Keepiq Federated Notification Delivery
 *
 * Delivers the sending side's OCM notifications and retries the ones that
 * fail (sharing-federated-recipients D5, task 4.2). A failed notification
 * stays on the share row with a growing wait: 1, 2, 4, 8 and 16 minutes,
 * then the share is marked failed for the owner to see. A delivered
 * `SHARE_UNSHARED` removes the row: the revocation is complete.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
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

namespace OCA\Keepiq\Service;

use DateInterval;
use DateTime;
use OCA\Keepiq\Db\FederatedShare;
use OCA\Keepiq\Db\FederatedShareMapper;
use OCA\Keepiq\Event\Audit\AuditEventTypes;

/**
 * OCM notifications with retries.
 *
 * @spec openspec/specs/federated-sharing/spec.md#requirement-owner-updates-reach-the-remote-copy-and-revocation-removes-it
 */
class FederatedNotificationDelivery {
	/**
	 * A share changed: the receiver pulls again.
	 *
	 * @var string
	 */
	public const SHARE_UPDATED = 'SHARE_UPDATED';

	/**
	 * A share ended: the receiver deletes its copy.
	 *
	 * @var string
	 */
	public const SHARE_UNSHARED = 'SHARE_UNSHARED';

	/**
	 * Attempts before a notification is given up and the share marked failed.
	 *
	 * @var int
	 */
	public const MAX_ATTEMPTS = 6;

	/**
	 * Seconds before the first retry; each later wait doubles.
	 *
	 * @var int
	 */
	private const FIRST_WAIT = 60;

	/**
	 * Constructor for FederatedNotificationDelivery.
	 *
	 * @param FederatedShareMapper $shareMapper Outbound share rows
	 * @param FederatedShareMessenger $messenger Sends the notification
	 * @param FederatedShareAuditTrail $audit Records a give-up
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private FederatedShareMapper $shareMapper,
		private FederatedShareMessenger $messenger,
		private FederatedShareAuditTrail $audit,
	) {
	}//end __construct()

	/**
	 * Send a notification now; on failure keep it for the retry job.
	 *
	 * @param FederatedShare $row The share
	 * @param string $type SHARE_UPDATED or SHARE_UNSHARED
	 *
	 * @return bool Whether it was delivered
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-owner-updates-reach-the-remote-copy-and-revocation-removes-it
	 */
	public function deliver(FederatedShare $row, string $type): bool {
		$row->setPendingNotification($type);
		$row->setNotifyAttempts(0);

		return $this->attempt(row: $row, now: new DateTime());
	}//end deliver()

	/**
	 * Retry every notification that is due.
	 *
	 * @param DateTime $now The current time
	 * @param int $limit The most shares to handle in one run
	 *
	 * @return int How many were delivered
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-owner-updates-reach-the-remote-copy-and-revocation-removes-it
	 */
	public function retryDue(DateTime $now, int $limit = 50): int {
		$delivered = 0;
		foreach ($this->shareMapper->findDueNotifications(now: $now, limit: $limit) as $row) {
			if ($this->attempt(row: $row, now: $now) === true) {
				$delivered++;
			}
		}

		return $delivered;
	}//end retryDue()

	/**
	 * One attempt at the row's pending notification.
	 *
	 * @param FederatedShare $row The share
	 * @param DateTime $now The current time
	 *
	 * @return bool Whether it was delivered
	 */
	private function attempt(FederatedShare $row, DateTime $now): bool {
		$type = (string)$row->getPendingNotification();
		if ($this->messenger->notify(row: $row, type: $type) === true) {
			if ($type === self::SHARE_UNSHARED) {
				$this->shareMapper->delete(entity: $row);
				return true;
			}

			$row->setPendingNotification(null);
			$row->setNotifyAttempts(0);
			$row->setNextNotifyAt(null);
			$this->shareMapper->update(entity: $row);
			return true;
		}

		$attempts = $row->getNotifyAttempts() + 1;
		$row->setNotifyAttempts($attempts);
		$row->setNextNotifyAt(null);
		if ($attempts >= self::MAX_ATTEMPTS) {
			// Given up: the owner sees the share as failed and can revoke or
			// share again. The pending type stays, to say what did not arrive.
			$row->setStatus(FederatedShare::STATUS_FAILED);
			$this->shareMapper->update(entity: $row);
			$this->audit->recordOutbound(
				eventType: AuditEventTypes::FEDERATED_SHARE_FAILED,
				row: $row,
				actorId: null,
				extra: ['notification' => $type],
			);
			return false;
		}

		$wait = self::FIRST_WAIT * (2 ** ($attempts - 1));
		$row->setNextNotifyAt((clone $now)->add(new DateInterval('PT' . $wait . 'S')));
		$this->shareMapper->update(entity: $row);

		return false;
	}//end attempt()
}//end class
