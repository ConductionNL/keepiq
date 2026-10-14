<?php

/**
 * Keepiq Federated Decline Receiver
 *
 * The owner's side of `SHARE_DECLINED` (sharing-federated-recipients task
 * 4.4): the recipient deleted the read-only copy they had accepted. A
 * decline counts only when it presents the share's shared secret, whose
 * SHA-256 is what this side keeps, and the verified signer of the request is
 * the recipient's own partner. Then the share is marked declined, nothing
 * more is served or sent for it, and a change still waiting for its retry is
 * dropped. A share whose revocation still waits for its unshare is removed
 * instead, as a delivered unshare would. Every refusal is the same "share
 * not found".
 *
 * It also takes the recipient's `SHARE_ACCEPTED` for a declined share: the
 * recipient restored the copy from their trash (decision of 4 Oct 2026).
 * Under the same checks the share is live again and gets the owner's changes;
 * a share that was revoked, suspended or failed meanwhile, or that the owner
 * already sent again, stays as it is and the answer is "share not found".
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
use OCP\OCM\IOCMDiscoveryService;
use OCP\Share\Exceptions\ShareNotFound;
use Throwable;

/**
 * Marks an outbound share declined when its recipient removed their copy.
 *
 * @spec openspec/specs/federated-sharing/spec.md#scenario-bob-deletes-his-copy
 */
class FederatedDeclineReceiver {
	/**
	 * Constructor for FederatedDeclineReceiver.
	 *
	 * @param FederatedShareMapper $shareMapper Outbound share rows
	 * @param FederationPartnerService $partners The partner allowlist
	 * @param IOCMDiscoveryService $ocmDiscovery The verified signer of the request
	 * @param FederatedShareAuditTrail $audit Identifier-only audit
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private FederatedShareMapper $shareMapper,
		private FederationPartnerService $partners,
		private IOCMDiscoveryService $ocmDiscovery,
		private FederatedShareAuditTrail $audit,
	) {
	}//end __construct()

	/**
	 * Apply a `SHARE_DECLINED` from the recipient's instance.
	 *
	 * @param string $providerId The share id here
	 * @param array<array-key,mixed> $notification The payload, `{sharedSecret}` (the secret itself)
	 *
	 * @return array<array-key,string>
	 *
	 * @throws ShareNotFound For every refusal
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-bob-deletes-his-copy
	 */
	public function handle(string $providerId, array $notification): array {
		$row = $this->verifiedRow(providerId: $providerId, presented: $notification['sharedSecret'] ?? null);
		$this->decline(row: $row, again: true);

		return [];
	}//end handle()

	/**
	 * Mark the share declined. A revocation still waiting for its unshare is
	 * finished instead: the decline shows the copy is gone, which is what the
	 * unshare was for. A share declined already stays as it is. When the row changed after it was read (the owner
	 * suspended, revoked or saved while the signature was checked), it is
	 * read once more and decided again.
	 *
	 * @param FederatedShare $row The share as it was read
	 * @param bool $again Whether a changed row may be read once more
	 *
	 * @return void
	 */
	private function decline(FederatedShare $row, bool $again): void {
		// A revocation on its way, also one whose retries gave up, finishes
		// as a delivered unshare does: the row goes.
		if ($row->getPendingNotification() === FederatedNotificationDelivery::SHARE_UNSHARED) {
			$this->shareMapper->deleteIfPending(share: $row, pending: FederatedNotificationDelivery::SHARE_UNSHARED);
			return;
		}

		// A second decline changes nothing.
		if ($row->getStatus() === FederatedShare::STATUS_REVOKED || $row->getStatus() === FederatedShare::STATUS_DECLINED) {
			return;
		}

		$read = $row->getStatus();
		$row->setStatus(FederatedShare::STATUS_DECLINED);
		$row->setPendingNotification(null);
		$row->setNotifyAttempts(0);
		$row->setNextNotifyAt(null);
		$row->stamp(now: new DateTime());
		if ($this->write(row: $row, read: $read) === true) {
			$this->audit->recordOutbound(eventType: AuditEventTypes::FEDERATED_SHARE_RECIPIENT_DECLINED, row: $row, actorId: null);
			return;
		}

		$current = $this->reread(row: $row);
		if ($again === true && $current !== null) {
			$this->decline(row: $current, again: false);
		}
	}//end decline()

	/**
	 * Apply a `SHARE_ACCEPTED` from the recipient's instance: a declined
	 * share whose copy the recipient restored becomes live again. One that
	 * is already live stays so.
	 *
	 * @param string $providerId The share id here
	 * @param array<array-key,mixed> $notification The payload, `{sharedSecret}` (the secret itself)
	 *
	 * @return array<array-key,string>
	 *
	 * @throws ShareNotFound For every refusal, and for a share that ended
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-bob-restores-his-copy
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-the-owner-revoked-the-share-meanwhile
	 */
	public function resume(string $providerId, array $notification): array {
		$row = $this->verifiedRow(providerId: $providerId, presented: $notification['sharedSecret'] ?? null);
		$this->accept(row: $row, again: true);

		return [];
	}//end resume()

	/**
	 * Make a declined share live again. When the row changed after it was
	 * read, it is read once more and decided again: a concurrent accept that
	 * already made it live is answered as live; a revoke, or a row that is
	 * gone, ends it.
	 *
	 * @param FederatedShare|null $row The share as it was read, or null when it is gone
	 * @param bool $again Whether a changed row may be read once more
	 *
	 * @return void
	 *
	 * @throws ShareNotFound For a share that ended
	 */
	private function accept(?FederatedShare $row, bool $again): void {
		if ($row !== null && $row->getStatus() === FederatedShare::STATUS_ACTIVE) {
			return;
		}

		if ($row === null || $row->getStatus() !== FederatedShare::STATUS_DECLINED || $this->sentAgain(row: $row) === true) {
			throw new ShareNotFound();
		}

		$row->setStatus(FederatedShare::STATUS_ACTIVE);
		$row->setPendingNotification(null);
		$row->setNotifyAttempts(0);
		$row->setNextNotifyAt(null);
		$row->stamp(now: new DateTime());
		if ($this->write(row: $row, read: FederatedShare::STATUS_DECLINED) === true) {
			$this->audit->recordOutbound(eventType: AuditEventTypes::FEDERATED_SHARE_RECIPIENT_RESUMED, row: $row, actorId: null);
			return;
		}

		if ($again === false) {
			throw new ShareNotFound();
		}

		$this->accept(row: $this->reread(row: $row), again: false);
	}//end accept()

	/**
	 * The share as the database holds it now, or null when it is gone.
	 *
	 * @param FederatedShare $row The share as it was read
	 *
	 * @return FederatedShare|null
	 */
	private function reread(FederatedShare $row): ?FederatedShare {
		try {
			return $this->shareMapper->findById(id: $row->getId());
		} catch (DoesNotExistException) {
			return null;
		}
	}//end reread()

	/**
	 * Store the recipient's change only while the share still has the status
	 * it was read with and no revocation is pending on it.
	 *
	 * @param FederatedShare $row The share with the change set
	 * @param string $read The status it was read with
	 *
	 * @return bool Whether it was stored
	 */
	private function write(FederatedShare $row, string $read): bool {
		return $this->shareMapper->updateIfStatus(
			share: $row,
			status: $read,
			ending: FederatedNotificationDelivery::SHARE_UNSHARED
		) > 0;
	}//end write()

	/**
	 * Whether the owner shared the same secret with the same recipient again
	 * after the decline: then that share is the live one, not this.
	 *
	 * @param FederatedShare $row The declined share
	 *
	 * @return bool
	 */
	private function sentAgain(FederatedShare $row): bool {
		foreach ($this->shareMapper->findBySourceSecret(sourceSecretId: $row->getSourceSecretId()) as $other) {
			if ($other->getId() !== $row->getId()
				&& $other->getRecipientCloudId() === $row->getRecipientCloudId()
				&& $other->getStatus() === FederatedShare::STATUS_ACTIVE
			) {
				return true;
			}
		}

		return false;
	}//end sentAgain()

	/**
	 * The recipient of the share a presented shared secret belongs to, or ''
	 * for none. Nextcloud 35 verifies the decline's signature against it.
	 *
	 * @param string $presented The secret the recipient's instance presented
	 * @param array<array-key,mixed> $notification The payload, with `providerId`
	 *
	 * @return string
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-bob-deletes-his-copy
	 */
	public function recipientOf(string $presented, array $notification): string {
		$row = $this->rowFor(providerId: (string)($notification['providerId'] ?? ''), presented: $presented);
		if ($row === null) {
			return '';
		}

		return $row->getRecipientCloudId();
	}//end recipientOf()

	/**
	 * The share, when the presented secret matches and the request is signed
	 * by the recipient's partner.
	 *
	 * @param string $providerId The share id here
	 * @param mixed $presented The presented secret
	 *
	 * @return FederatedShare
	 *
	 * @throws ShareNotFound
	 */
	private function verifiedRow(string $providerId, mixed $presented): FederatedShare {
		$row = null;
		if (is_string($presented) === true) {
			$row = $this->rowFor(providerId: $providerId, presented: $presented);
		}

		if ($row === null) {
			throw new ShareNotFound();
		}

		try {
			$signed = $this->ocmDiscovery->getIncomingSignedRequest($row->getRecipientCloudId());
		} catch (Throwable) {
			throw new ShareNotFound();
		}

		$partner = null;
		if ($signed !== null) {
			$partner = $this->partners->outboundPartnerForRemote(remote: $signed->getOrigin());
		}

		if ($partner === null || $partner->getId() !== $row->getPartnerId()) {
			throw new ShareNotFound();
		}

		return $row;
	}//end verifiedRow()

	/**
	 * The outbound share with that id whose stored hash is the SHA-256 of the
	 * presented secret, or null.
	 *
	 * @param string $providerId The share id
	 * @param string $presented The presented secret
	 *
	 * @return FederatedShare|null
	 */
	private function rowFor(string $providerId, string $presented): ?FederatedShare {
		if ($providerId === '' || $presented === '') {
			return null;
		}

		try {
			$row = $this->shareMapper->findById(id: $providerId);
		} catch (DoesNotExistException) {
			return null;
		}

		if (hash_equals($row->getSharedSecretHash(), hash('sha256', $presented)) === false) {
			return null;
		}

		return $row;
	}//end rowFor()
}//end class
