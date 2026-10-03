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
 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-owner-updates-reach-the-remote-copy-and-revocation-removes-it
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
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-owner-updates-reach-the-remote-copy-and-revocation-removes-it
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
	 * acceptance anyway.
	 *
	 * @param FederatedInbound $row The share
	 *
	 * @return void
	 *
	 * @throws ShareNotFound When the pull fails, so the sender retries
	 */
	private function applyUpdate(FederatedInbound $row): void {
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
	 * The share this notification is about, when the signer is its partner
	 * and the presented hash matches the shared secret held here.
	 *
	 * @param string $providerId The share id on the sender
	 * @param mixed $presented The hash the sender presented
	 *
	 * @return FederatedInbound
	 *
	 * @throws ShareNotFound
	 */
	private function verifiedRow(string $providerId, mixed $presented): FederatedInbound {
		try {
			$signed = $this->ocmDiscovery->getIncomingSignedRequest();
			$partner = null;
			if ($signed !== null) {
				$partner = $this->partners->inboundPartnerForSigner(signer: $signed->getOrigin());
			}

			if ($partner === null || is_string($presented) === false) {
				throw new ShareNotFound();
			}

			$row = $this->inboundMapper->findByRemote(partnerId: $partner->getId(), remoteShareId: $providerId);
			$held = hash('sha256', $this->crypto->decrypt($row->getSharedSecretEnc()));
		} catch (Throwable) {
			throw new ShareNotFound();
		}

		if (hash_equals($held, $presented) === false || $row->getStatus() === FederatedInbound::STATUS_REVOKED) {
			throw new ShareNotFound();
		}

		return $row;
	}//end verifiedRow()
}//end class
