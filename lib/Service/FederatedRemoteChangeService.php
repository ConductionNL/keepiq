<?php

/**
 * Keepiq Federated Remote Change Service
 *
 * The receiving side of `SHARE_UPDATED` and `SHARE_UNSHARED`
 * (sharing-federated-recipients D5, tasks 4.1 and 4.2). A notification
 * counts only when the verified signer of the request is the inbound
 * partner that sent the share, and it carries the SHA-256 of the share's
 * shared secret (see FederatedShareMessenger). Then an update pulls the
 * ciphertext again and replaces the copy whole, and an unshare deletes the
 * copy. Every refusal is the same "share not found".
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
use OCA\Keepiq\Db\FederatedInbound;
use OCA\Keepiq\Db\FederatedInboundMapper;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCP\OCM\IOCMDiscoveryService;
use OCP\Security\ICrypto;
use OCP\Share\Exceptions\ShareNotFound;
use Throwable;

/**
 * Applies the sender's changes to the recipient's copy.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) One notification joins the
 *   inbound row, the partner allowlist, Nextcloud's signature check, the
 *   stored secret, the copy and the audit, and answers a declined share with
 *   the decline (task 4.4); each refusal is the same "share not found".
 *
 * @spec openspec/specs/federated-sharing/spec.md#requirement-owner-updates-reach-the-remote-copy-and-revocation-removes-it
 */
class FederatedRemoteChangeService {
	/**
	 * The OCM notification that a share changed.
	 *
	 * @var string
	 */
	private const SHARE_UPDATED = 'SHARE_UPDATED';

	/**
	 * The OCM notification that a share ended.
	 *
	 * @var string
	 */
	private const SHARE_UNSHARED = 'SHARE_UNSHARED';

	/**
	 * Constructor for FederatedRemoteChangeService.
	 *
	 * @param FederatedInboundMapper $inboundMapper Inbound share rows
	 * @param FederationPartnerService $partners The partner allowlist
	 * @param IOCMDiscoveryService $ocmDiscovery The verified signer of the request
	 * @param ICrypto $crypto Opens the stored shared secret
	 * @param FederatedCopyService $copies Refreshes or removes the copy
	 * @param FederatedShareAuditTrail $audit Identifier-only audit
	 * @param FederatedCopyDeclineService|null $declines Repeats a decline the owner did not get
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private FederatedInboundMapper $inboundMapper,
		private FederationPartnerService $partners,
		private IOCMDiscoveryService $ocmDiscovery,
		private ICrypto $crypto,
		private FederatedCopyService $copies,
		private FederatedShareAuditTrail $audit,
		private ?FederatedCopyDeclineService $declines = null,
	) {
	}//end __construct()

	/**
	 * Handle one notification from the sending instance.
	 *
	 * @param string $type `SHARE_UPDATED` or `SHARE_UNSHARED`; others are acknowledged and ignored
	 * @param string $providerId The share id on the sender
	 * @param array<array-key,mixed> $notification The payload, `{sharedSecret}` (its SHA-256)
	 *
	 * @return array<string,mixed>
	 *
	 * @throws ShareNotFound For every refusal
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-owner-updates-reach-the-remote-copy-and-revocation-removes-it
	 */
	public function handle(string $type, string $providerId, array $notification): array {
		$row = $this->verifiedRow(providerId: $providerId, presented: $notification['sharedSecret'] ?? null);

		if ($type === self::SHARE_UPDATED) {
			$this->applyUpdate(row: $row);
		}

		if ($type === self::SHARE_UNSHARED) {
			$this->applyUnshare(row: $row);
		}

		return [];
	}//end handle()

	/**
	 * Pull again when the share is accepted; a pending share pulls on
	 * acceptance anyway, and a declined one answers with the decline.
	 *
	 * @param FederatedInbound $row The share
	 *
	 * @return void
	 *
	 * @throws ShareNotFound When the pull fails, so the sender retries
	 */
	private function applyUpdate(FederatedInbound $row): void {
		// The recipient deleted the copy, and the owner still sends changes:
		// the decline did not arrive, so it goes again (task 4.4).
		if ($row->getStatus() === FederatedInbound::STATUS_DECLINED) {
			$this->declines?->tellOwner(row: $row);
			return;
		}

		if ($row->getStatus() !== FederatedInbound::STATUS_ACCEPTED) {
			return;
		}

		try {
			$this->copies->refresh(row: $row);
		} catch (Throwable) {
			throw new ShareNotFound();
		}

		$row->setUpdatedAt(new DateTime());
		$this->inboundMapper->update(entity: $row);
		$this->audit->recordInbound(eventType: AuditEventTypes::FEDERATED_COPY_UPDATED, row: $row, actorId: null);
	}//end applyUpdate()

	/**
	 * Delete the copy and mark the share revoked.
	 *
	 * @param FederatedInbound $row The share
	 *
	 * @return void
	 */
	private function applyUnshare(FederatedInbound $row): void {
		if ($row->getStatus() === FederatedInbound::STATUS_ACCEPTED) {
			$this->copies->remove(row: $row);
			$this->audit->recordInbound(eventType: AuditEventTypes::FEDERATED_COPY_REMOVED, row: $row, actorId: null);
		}

		$row->setSecretId(null);
		$row->setStatus(FederatedInbound::STATUS_REVOKED);
		$row->setUpdatedAt(new DateTime());
		$this->inboundMapper->update(entity: $row);
	}//end applyUnshare()

	/**
	 * The sender of the share a notification's shared secret hash belongs to,
	 * or '' for none. Nextcloud 35 needs it to verify the signature
	 * (ISignedCloudFederationProvider::getFederationIdFromSharedSecret()).
	 *
	 * @param string $presented The hash the sender presented
	 * @param array<array-key,mixed> $notification The payload, with `providerId`
	 *
	 * @return string
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-owner-updates-reach-the-remote-copy-and-revocation-removes-it
	 */
	public function senderOf(string $presented, array $notification): string {
		$row = $this->rowFor(providerId: (string)($notification['providerId'] ?? ''), presented: $presented);
		if ($row === null) {
			return '';
		}

		return $row->getSenderCloudId();
	}//end senderOf()

	/**
	 * The share this notification is about, when the presented hash matches
	 * the shared secret held here and the request is signed by that share's
	 * own partner, verified against the share's sender.
	 *
	 * @param string $providerId The share id on the sender
	 * @param mixed $presented The hash the sender presented
	 *
	 * @return FederatedInbound
	 *
	 * @throws ShareNotFound
	 */
	private function verifiedRow(string $providerId, mixed $presented): FederatedInbound {
		$row = null;
		if (is_string($presented) === true) {
			$row = $this->rowFor(providerId: $providerId, presented: $presented);
		}

		if ($row === null || $row->getStatus() === FederatedInbound::STATUS_REVOKED) {
			throw new ShareNotFound();
		}

		try {
			$signed = $this->ocmDiscovery->getIncomingSignedRequest($row->getSenderCloudId());
		} catch (Throwable) {
			throw new ShareNotFound();
		}

		$partner = null;
		if ($signed !== null) {
			$partner = $this->partners->inboundPartnerForSigner(signer: $signed->getOrigin());
		}

		if ($partner === null || $partner->getId() !== $row->getPartnerId()) {
			throw new ShareNotFound();
		}

		return $row;
	}//end verifiedRow()

	/**
	 * The inbound share with that remote id whose shared secret hashes to
	 * the presented value, or null.
	 *
	 * @param string $providerId The share id on the sender
	 * @param string $presented The presented hash
	 *
	 * @return FederatedInbound|null
	 */
	private function rowFor(string $providerId, string $presented): ?FederatedInbound {
		if ($providerId === '' || $presented === '') {
			return null;
		}

		foreach ($this->inboundMapper->findByRemoteShareId(remoteShareId: $providerId) as $row) {
			try {
				$held = hash('sha256', $this->crypto->decrypt($row->getSharedSecretEnc()));
			} catch (Throwable) {
				continue;
			}

			if (hash_equals($held, $presented) === true) {
				return $row;
			}
		}

		return null;
	}//end rowFor()
}//end class
