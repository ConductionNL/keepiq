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
use OCP\AppFramework\Db\DoesNotExistException;

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
		$now = new DateTime();
		$row->setPendingNotification($type);
		$row->setNotifyAttempts(0);
		$row->setNextNotifyAt($this->leaseFrom(queuedAt: $now));
		if ($this->queue(row: $row, type: $type) === false) {
			return false;
		}

		return $this->attempt(row: $row, now: $now);
	}//end deliver()

	/**
	 * When the retry job may first pick up a notification queued at
	 * `$queuedAt`: the first send has until then, so the two never overlap.
	 *
	 * @param DateTime $queuedAt When the notification was stored
	 *
	 * @return DateTime
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/federated-sharing/spec.md#requirement-partner-copies-change-only-with-the-owners-share-save
	 */
	public function leaseFrom(DateTime $queuedAt): DateTime {
		return (clone $queuedAt)->add(new DateInterval('PT' . self::FIRST_WAIT . 'S'));
	}//end leaseFrom()

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
		foreach ($this->shareMapper->findDueNotifications(now: $now, limit: $limit, ending: self::SHARE_UNSHARED) as $read) {
			$row = $this->stillDue(row: $read, now: $now);
			if ($row !== null && $this->attempt(row: $row, now: $now) === true) {
				$delivered++;
			}
		}

		return $delivered;
	}//end retryDue()

	/**
	 * The share as the database holds it now, or null when its notification
	 * is no longer due: the earlier sends of a batch take time, and a revoke,
	 * suspend or save may land before this one's turn.
	 *
	 * @param FederatedShare $row The share as the batch read it
	 * @param DateTime $now The current time
	 *
	 * @return FederatedShare|null
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/federated-sharing/spec.md#requirement-an-unreachable-partner-stops-the-save-unless-the-user-saves-without-them
	 */
	private function stillDue(FederatedShare $row, DateTime $now): ?FederatedShare {
		try {
			$current = $this->shareMapper->findById(id: $row->getId());
		} catch (DoesNotExistException) {
			return null;
		}

		$pending = $current->getPendingNotification();
		$due = $current->getNextNotifyAt();
		if ($pending === null || $due === null || $due > $now) {
			return null;
		}

		// Only its end is announced to a share that is no longer active.
		if ($pending !== self::SHARE_UNSHARED && $current->getStatus() !== FederatedShare::STATUS_ACTIVE) {
			return null;
		}

		return $current;
	}//end stillDue()

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
		$from = ['status' => $row->getStatus(), 'updatedAt' => $row->getUpdatedAt()];
		if ($this->messenger->notify(row: $row, type: $type) === true) {
			if ($type === self::SHARE_UNSHARED) {
				$this->shareMapper->deleteIfPending(share: $row, pending: $type);
				return true;
			}

			$row->setPendingNotification(null);
			$row->setNotifyAttempts(0);
			$row->setNextNotifyAt(null);
			$this->write(row: $row, type: $type, from: $from);
			return true;
		}

		$attempts = $row->getNotifyAttempts() + 1;
		$row->setNotifyAttempts($attempts);
		$row->setNextNotifyAt(null);
		if ($attempts >= self::MAX_ATTEMPTS) {
			// Given up: the owner sees the share as failed and can revoke or
			// share again. The pending type stays, to say what did not arrive.
			$row->setStatus(FederatedShare::STATUS_FAILED);
			if ($this->write(row: $row, type: $type, from: $from) === false) {
				return false;
			}

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
		$this->write(row: $row, type: $type, from: $from);

		return false;
	}//end attempt()

	/**
	 * Store a notification on the row before it is sent, so the retry job
	 * finds it should the send never finish. A revoke's unshare replaces
	 * whatever was pending; an update is queued only on a live share.
	 *
	 * @param FederatedShare $row The share with the notification set
	 * @param string $type The notification
	 *
	 * @return bool Whether it was queued
	 */
	private function queue(FederatedShare $row, string $type): bool {
		if ($type === self::SHARE_UNSHARED) {
			$this->shareMapper->update(entity: $row);
			return true;
		}

		return $this->shareMapper->updateIfActive(share: $row) > 0;
	}//end queue()

	/**
	 * Store the outcome of an attempt only while the row is as the attempt
	 * found it: a revoke, suspend or newer save may have landed while the
	 * partner was slow, and its notification must stay.
	 *
	 * @param FederatedShare $row The share with the outcome set
	 * @param string $type The notification that was attempted
	 * @param array{status:string,updatedAt:DateTime|null} $from The row when the attempt started
	 *
	 * @return bool Whether the outcome was stored
	 */
	private function write(FederatedShare $row, string $type, array $from): bool {
		return $this->shareMapper->updateIfUnchanged(
			share: $row,
			pending: $type,
			status: $from['status'],
			updatedAt: $from['updatedAt']
		) > 0;
	}//end write()
}//end class
