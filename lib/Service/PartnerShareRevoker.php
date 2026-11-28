<?php

/**
 * Keepiq Partner Share Revoker
 *
 * Revokes partner (federated) shares so each partner deletes its copy:
 *
 * - `revoke()` ends one share the owner revokes, and tells the partner now.
 * - When a secret is deleted for good, its partner shares end before its row goes: the owner's purge tells each partner now,
 *   the trash retention job, an account deletion and the delete of a received copy with its share leave every SHARE_UNSHARED to the retry job.
 * - A declined share of a deleted secret is deleted without a notification, and so is one whose partner was removed:
 *   that instance is no longer trusted with what an unshare says. A revoke whose SHARE_UNSHARED is pending keeps it.
 * - `dropUnsharesToRemovedPartners()` lets the retry job drop a pending SHARE_UNSHARED whose partner was removed since it was queued.
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

use DateTime;
use OCA\Keepiq\Db\FederatedShare;
use OCA\Keepiq\Db\FederatedShareMapper;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Ends partner shares: one at a time for its owner, or all of a secret deleted for good.
 *
 * @spec openspec/specs/federated-sharing/spec.md#requirement-owner-updates-reach-the-remote-copy-and-revocation-removes-it
 */
class PartnerShareRevoker {
	/**
	 * Constructor for PartnerShareRevoker.
	 *
	 * @param FederatedShareMapper $shareMapper Outbound share rows
	 * @param FederatedNotificationDelivery $delivery Notifications with retries
	 * @param FederatedShareAuditTrail $audit Identifier-only audit
	 * @param FederationPartnerService $partners Whether a suspended share's partner is still one
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private FederatedShareMapper $shareMapper,
		private FederatedNotificationDelivery $delivery,
		private FederatedShareAuditTrail $audit,
		private FederationPartnerService $partners,
	) {
	}//end __construct()

	/**
	 * Revoke a share and tell its recipient's instance now. Should that fail, the retry job tries again:
	 * up to 6 attempts in all while the secret exists, after which the owner sees the share failed, and for 30 days once it is deleted for good,
	 * unless the partner refuses it (a 4xx other than 429) from the 6th attempt on, which drops the share.
	 * A partner removed meanwhile is not told: the retry job drops the share unsent.
	 *
	 * @param FederatedShare $row The share
	 * @param string $actorId Who revokes it
	 *
	 * @return void
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-revocation-removes-bobs-copy
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-deleting-the-secret-for-good-removes-bobs-copy
	 */
	public function revoke(FederatedShare $row, string $actorId): void {
		$row->setStatus(FederatedShare::STATUS_REVOKED);
		$row->stamp(now: new DateTime());
		$this->shareMapper->update(entity: $row);

		$this->audit->recordOutbound(eventType: AuditEventTypes::FEDERATED_SHARE_REVOKED, row: $row, actorId: $actorId);
		$this->delivery->deliver(row: $row, type: FederatedNotificationDelivery::SHARE_UNSHARED);
	}//end revoke()

	/**
	 * A secret is deleted for good: end each of its partner shares before its row goes.
	 * The owner's delete tells every partner now; the trash retention job leaves that to the retry job.
	 *
	 * @param string         $secretId The secret being deleted
	 * @param SecretDeletion $deletion Who deletes it, and why when it is a purge
	 *
	 * @return void
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-deleting-the-secret-for-good-removes-bobs-copy
	 */
	public function endForDeletedSecret(string $secretId, SecretDeletion $deletion): void {
		if ($deletion->byRetention() === true) {
			$this->queueForRetentionPurge(secretId: $secretId);
			return;
		}

		$this->revokeForDeletedSecret(secretId: $secretId, userId: $deletion->userId);
	}//end endForDeletedSecret()

	/**
	 * Its owner deletes a secret for good: end each of its partner shares, telling every partner now. Runs before the secret's row goes.
	 *
	 * @param string $secretId The secret being deleted
	 * @param string $userId The owner who deletes it
	 *
	 * @return void
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-deleting-the-secret-for-good-removes-bobs-copy
	 */
	public function revokeForDeletedSecret(string $secretId, string $userId): void {
		foreach ($this->shareMapper->findBySourceSecret(sourceSecretId: $secretId) as $row) {
			$current = $this->endUntold(row: $row, actorId: $userId);
			if ($this->needsRevoke(row: $current) === true) {
				$this->revoke(row: $current, actorId: $userId);
			}
		}
	}//end revokeForDeletedSecret()

	/**
	 * The trash retention job deletes a secret for good: end each of its partner shares,
	 * and leave every SHARE_UNSHARED to the retry job so one run does not wait on each partner. Runs before the secret's row goes.
	 *
	 * @param string $secretId The secret being purged
	 *
	 * @return void
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-deleting-the-secret-for-good-removes-bobs-copy
	 */
	public function queueForRetentionPurge(string $secretId): void {
		$this->queueAll(rows: $this->shareMapper->findBySourceSecret(sourceSecretId: $secretId), actorId: null);
	}//end queueForRetentionPurge()

	/**
	 * A user's copy of a secret shared with them is deleted for good with its share: the share is revoked, its source is trashed or purged,
	 * a team-folder share ends or a member leaves a group. End each partner share the holder made of the copy,
	 * and leave every SHARE_UNSHARED to the retry job, as the copy can go inside a transaction. Runs before the copy's row goes.
	 *
	 * @param string $copyId The copy being deleted
	 *
	 * @return void
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-a-received-copy-deleted-for-good-ends-its-partner-shares
	 */
	public function queueForDeletedCopy(string $copyId): void {
		$this->queueAll(rows: $this->shareMapper->findBySourceSecret(sourceSecretId: $copyId), actorId: null);
	}//end queueForDeletedCopy()

	/**
	 * The retry job, before it sends: drop every due SHARE_UNSHARED whose partner was removed since it was queued, and send it nothing,
	 * as that instance is no longer trusted with what an unshare says. Each is recorded as failed with reason `partner_removed`.
	 * It reads the same due rows the retry job's run sends next.
	 *
	 * @param DateTime $now The current time
	 * @param int $limit The most due shares to look at, as many as one retry run sends
	 *
	 * @return int How many were dropped
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-a-removed-partner-is-not-told
	 */
	public function dropUnsharesToRemovedPartners(DateTime $now, int $limit = 50): int {
		$dropped = 0;
		$type = FederatedNotificationDelivery::SHARE_UNSHARED;
		foreach ($this->shareMapper->findDueNotifications(now: $now, limit: $limit, ending: $type) as $row) {
			if ($row->getPendingNotification() !== $type || $this->partners->find(id: $row->getPartnerId()) !== null) {
				continue;
			}

			if ($this->shareMapper->deleteIfPending(share: $row, pending: $type) > 0) {
				$extra = ['notification' => $type, 'reason' => 'partner_removed'];
				$this->audit->recordOutbound(eventType: AuditEventTypes::FEDERATED_SHARE_FAILED, row: $row, actorId: null, extra: $extra);
				$dropped++;
			}
		}

		return $dropped;
	}//end dropUnsharesToRemovedPartners()

	/**
	 * A user's account is deleted: end the partner shares of every secret the user still owns, and leave every SHARE_UNSHARED to the retry job.
	 * Runs after the delegated secrets went to their delegates, whose shares stay, and before the user's secrets go.
	 * A share whose own owner is the user but whose secret someone else owns now, left behind by an earlier transfer, is that owner's and stays too.
	 *
	 * @param string $userId The user whose account is deleted
	 *
	 * @return void
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-deleting-the-secret-for-good-removes-bobs-copy
	 */
	public function queueForDeletedAccount(string $userId): void {
		$this->queueAll(rows: $this->shareMapper->findBySourceOwnerUser(ownerId: $userId), actorId: $userId);
	}//end queueForDeletedAccount()

	/**
	 * End the partner shares of a deleted secret, each SHARE_UNSHARED left to the retry job.
	 *
	 * @param FederatedShare[] $rows The shares
	 * @param string|null $actorId Who ends them, or null for the system
	 *
	 * @return void
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-deleting-the-secret-for-good-removes-bobs-copy
	 */
	private function queueAll(array $rows, ?string $actorId): void {
		foreach ($rows as $row) {
			$current = $this->endUntold(row: $row, actorId: $actorId);
			if ($this->needsRevoke(row: $current) === false) {
				continue;
			}

			$current->setStatus(FederatedShare::STATUS_REVOKED);
			$current->stamp(now: new DateTime());
			$this->delivery->enqueue(row: $current, type: FederatedNotificationDelivery::SHARE_UNSHARED);
			$this->audit->recordOutbound(eventType: AuditEventTypes::FEDERATED_SHARE_REVOKED, row: $current, actorId: $actorId);
		}
	}//end queueAll()

	/**
	 * Delete a share of a deleted secret that is not to be told, recorded as revoked: a declined one, whose recipient trashed or deleted
	 * the copy and is sent nothing more (a restore of a trashed copy finds the share ended either way), and one whose partner was
	 * removed, as that instance is no longer trusted with the share id, the shared-secret hash and the owner an unshare carries.
	 *
	 * @param FederatedShare $row The share as it was read
	 * @param string|null $actorId Who deletes the secret, or null for the system
	 *
	 * @return FederatedShare|null Null once the share is gone; otherwise the share as it is now
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-deleting-the-secret-for-good-removes-bobs-copy
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-a-removed-partner-is-not-told
	 */
	private function endUntold(FederatedShare $row, ?string $actorId): ?FederatedShare {
		if ($this->isUntold(row: $row) === false) {
			return $row;
		}

		if ($this->shareMapper->deleteIfStatus(share: $row, status: $row->getStatus()) > 0) {
			$this->audit->recordOutbound(eventType: AuditEventTypes::FEDERATED_SHARE_REVOKED, row: $row, actorId: $actorId);
			return null;
		}

		// The recipient took it back meanwhile: decide on the share as it is now.
		try {
			return $this->shareMapper->findById(id: $row->getId());
		} catch (DoesNotExistException) {
			return null;
		}
	}//end endUntold()

	/**
	 * Whether a share of a deleted secret ends without a notification: it is declined, or its partner is no longer one, whatever its
	 * status (a revoke already on its way is left to the retry job, which drops it for a removed partner).
	 * A share suspended for another reason (its recipient's certificate no longer verifies, or the lookup answers for another cloud id)
	 * is still told while its partner is one: that instance is still trusted, and holds a copy only the unshare removes.
	 *
	 * @param FederatedShare $row The share
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-a-removed-partner-is-not-told
	 */
	private function isUntold(FederatedShare $row): bool {
		if ($row->getStatus() === FederatedShare::STATUS_DECLINED) {
			return true;
		}

		return $row->getStatus() !== FederatedShare::STATUS_REVOKED && $this->partners->find(id: $row->getPartnerId()) === null;
	}//end isUntold()

	/**
	 * Whether a share of a deleted secret still needs a revoke: every share the partner may still hold a copy for does,
	 * except a revoke whose SHARE_UNSHARED is pending already.
	 *
	 * @param FederatedShare|null $row The share, or null when it is gone
	 *
	 * @return bool
	 *
	 * @psalm-assert-if-true FederatedShare $row
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-deleting-the-secret-for-good-removes-bobs-copy
	 */
	private function needsRevoke(?FederatedShare $row): bool {
		if ($row === null) {
			return false;
		}

		return $row->getStatus() !== FederatedShare::STATUS_REVOKED
			|| $row->getPendingNotification() !== FederatedNotificationDelivery::SHARE_UNSHARED;
	}//end needsRevoke()
}//end class
