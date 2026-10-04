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
 * dropped. Every refusal is the same "share not found".
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
	 * @return array<string,mixed>
	 *
	 * @throws ShareNotFound For every refusal
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-bob-deletes-his-copy
	 */
	public function handle(string $providerId, array $notification): array {
		$row = $this->verifiedRow(providerId: $providerId, presented: $notification['sharedSecret'] ?? null);

		// A revocation on its way finishes as a revocation; a second decline
		// changes nothing.
		if ($row->getStatus() === FederatedShare::STATUS_REVOKED || $row->getStatus() === FederatedShare::STATUS_DECLINED) {
			return [];
		}

		$row->setStatus(FederatedShare::STATUS_DECLINED);
		$row->setPendingNotification(null);
		$row->setNotifyAttempts(0);
		$row->setNextNotifyAt(null);
		$row->setUpdatedAt(new DateTime());
		$this->shareMapper->update(entity: $row);
		$this->audit->recordOutbound(eventType: AuditEventTypes::FEDERATED_SHARE_RECIPIENT_DECLINED, row: $row, actorId: null);

		return [];
	}//end handle()

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
