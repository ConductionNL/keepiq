<?php

/**
 * Keepiq Partner Share Revoker
 *
 * Revokes partner (federated) shares so each partner deletes its copy:
 *
 * - `revoke()` ends one share the owner revokes, and tells the partner now.
 * - When a secret is deleted for good, its partner shares end before its row goes: the owner's purge tells each partner now,
 *   the trash retention job and an account deletion leave every SHARE_UNSHARED to the retry job.
 * - A declined share of a deleted secret is deleted without a notification, and a revoke whose SHARE_UNSHARED is pending keeps it.
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
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private FederatedShareMapper $shareMapper,
		private FederatedNotificationDelivery $delivery,
		private FederatedShareAuditTrail $audit,
	) {
	}//end __construct()

	/**
	 * Revoke a share and tell its recipient's instance now. Should that fail, the retry job tries again:
	 * up to 6 attempts in all while the secret exists, after which the owner sees the share failed, and for 30 days once it is deleted for good.
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
			$current = $this->deleteIfDeclined(row: $row, actorId: $userId);
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
			$current = $this->deleteIfDeclined(row: $row, actorId: $actorId);
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
	 * Delete a declined share of a deleted secret: its recipient trashed or deleted the copy and is sent nothing more,
	 * and a restore of a trashed copy finds the share ended either way.
	 *
	 * @param FederatedShare $row The share as it was read
	 * @param string|null $actorId Who deletes the secret, or null for the system
	 *
	 * @return FederatedShare|null Null once the share is gone; otherwise the share as it is now
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-deleting-the-secret-for-good-removes-bobs-copy
	 */
	private function deleteIfDeclined(FederatedShare $row, ?string $actorId): ?FederatedShare {
		if ($row->getStatus() !== FederatedShare::STATUS_DECLINED) {
			return $row;
		}

		if ($this->shareMapper->deleteIfStatus(share: $row, status: FederatedShare::STATUS_DECLINED) > 0) {
			$this->audit->recordOutbound(eventType: AuditEventTypes::FEDERATED_SHARE_REVOKED, row: $row, actorId: $actorId);
			return null;
		}

		// The recipient took it back meanwhile: decide on the share as it is now.
		try {
			return $this->shareMapper->findById(id: $row->getId());
		} catch (DoesNotExistException) {
			return null;
		}
	}//end deleteIfDeclined()

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
