<?php

/**
 * Keepiq Federated Copy Decline Service
 *
 * The recipient's side of deleting a read-only copy from another
 * organisation (sharing-federated-recipients task 4.4, decision of 4 Oct
 * 2026). Moving the copy to the trash, or purging it, declines the share it
 * came from: the inbound row is marked declined and the owner's instance
 * gets an OCM `SHARE_DECLINED`, after which the owner's share shows
 * "declined" and no more changes are sent.
 *
 * The notification carries the share's shared secret itself: the owner's
 * instance keeps only its SHA-256 and checks the secret against it, and
 * Nextcloud verifies the signature against the recipient that secret
 * belongs to (ISignedCloudFederationProvider).
 *
 * The decline is sent once. When it did not arrive, the owner's next
 * `SHARE_UPDATED` for the declined share sends it again
 * (FederatedRemoteChangeService), so a lost decline heals at the owner's
 * next change without a retry queue here.
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
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCP\Federation\ICloudFederationFactory;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\Federation\ICloudIdManager;
use OCP\Security\ICrypto;
use Throwable;

/**
 * Declines the share behind a deleted read-only copy.
 *
 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#scenario-bob-deletes-his-copy
 */
class FederatedCopyDeclineService {
	/**
	 * The OCM notification that the recipient declined a share.
	 *
	 * @var string
	 */
	public const SHARE_DECLINED = 'SHARE_DECLINED';

	/**
	 * Constructor for FederatedCopyDeclineService.
	 *
	 * @param FederatedInboundMapper $inboundMapper Inbound share rows
	 * @param ICrypto $crypto Opens the stored shared secret
	 * @param ICloudFederationProviderManager $providerManager OCM delivery
	 * @param ICloudFederationFactory $factory OCM notification objects
	 * @param ICloudIdManager $cloudIdManager The owner's instance
	 * @param FederatedShareAuditTrail $audit Identifier-only audit
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private FederatedInboundMapper $inboundMapper,
		private ICrypto $crypto,
		private ICloudFederationProviderManager $providerManager,
		private ICloudFederationFactory $factory,
		private ICloudIdManager $cloudIdManager,
		private FederatedShareAuditTrail $audit,
	) {
	}//end __construct()

	/**
	 * The user moved a secret to the trash or purged it. When it is their
	 * read-only copy of an accepted federated share, decline that share and
	 * tell the owner's instance. Any other secret is left alone.
	 *
	 * @param Secret $secret The secret being deleted
	 * @param string $userId The user deleting it
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#scenario-bob-deletes-his-copy
	 */
	public function copyDeleted(Secret $secret, string $userId): void {
		if ($secret->getReadOnly() !== true) {
			return;
		}

		foreach ($this->inboundMapper->findBySecretId(secretId: $secret->getId()) as $row) {
			if ($row->getRecipientUid() !== $userId || $row->getStatus() !== FederatedInbound::STATUS_ACCEPTED) {
				continue;
			}

			$row->setStatus(FederatedInbound::STATUS_DECLINED);
			$row->setSecretId(null);
			$row->setUpdatedAt(new DateTime());
			$this->inboundMapper->update(entity: $row);
			$this->audit->recordInbound(eventType: AuditEventTypes::FEDERATED_SHARE_DECLINED, row: $row, actorId: $userId);

			$this->tellOwner(row: $row);
		}
	}//end copyDeleted()

	/**
	 * Send `SHARE_DECLINED` for a declined share to the owner's instance.
	 * Returns whether it took the notification.
	 *
	 * @param FederatedInbound $row The declined share
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#scenario-bob-deletes-his-copy
	 */
	public function tellOwner(FederatedInbound $row): bool {
		try {
			$sharedSecret = $this->crypto->decrypt($row->getSharedSecretEnc());
			$remote = $this->cloudIdManager->resolveCloudId($row->getSenderCloudId())->getRemote();
		} catch (Throwable) {
			return false;
		}

		$notification = $this->factory->getCloudFederationNotification();
		$notification->setMessage(
			self::SHARE_DECLINED,
			FederatedShareService::RESOURCE_TYPE,
			$row->getRemoteShareId(),
			[
				'sharedSecret' => $sharedSecret,
				// The share id lets the owner's Nextcloud find whose
				// signature this is (ISignedCloudFederationProvider).
				'providerId' => $row->getRemoteShareId(),
				'message' => 'The recipient removed their copy',
			]
		);

		try {
			$response = $this->providerManager->sendCloudNotification($remote, $notification);
		} catch (Throwable) {
			return false;
		}

		return $response->getStatusCode() === 201;
	}//end tellOwner()
}//end class
