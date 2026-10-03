<?php

/**
 * Keepiq Federated Share Messenger
 *
 * Everything the sending side says to a recipient's instance over OCM
 * (sharing-federated-recipients D4, D5): the share announcement, sent with
 * `sendCloudShare()` with resource type `keepiq-secret`, and the
 * `SHARE_UPDATED` and `SHARE_UNSHARED` notifications, sent with
 * `sendCloudNotification()`. None of them carries ciphertext.
 *
 * Nextcloud refuses a notification without a `sharedSecret`, and the
 * sending side keeps only the secret's SHA-256. So a notification carries
 * that hash; the receiver compares it with the hash of the secret it holds.
 * The pull of the ciphertext still needs the secret itself.
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

use OCA\Keepiq\Db\FederatedShare;
use OCP\Federation\ICloudFederationFactory;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\Federation\ICloudIdManager;
use OCP\IUserManager;
use Throwable;

/**
 * OCM messages of the sending side.
 *
 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
 */
class FederatedShareMessenger {
	/**
	 * Constructor for FederatedShareMessenger.
	 *
	 * @param ICloudFederationProviderManager $providerManager OCM delivery
	 * @param ICloudFederationFactory $factory OCM share and notification objects
	 * @param ICloudIdManager $cloudIdManager The owner's cloud id
	 * @param IUserManager $userManager The owner's display name
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private ICloudFederationProviderManager $providerManager,
		private ICloudFederationFactory $factory,
		private ICloudIdManager $cloudIdManager,
		private IUserManager $userManager,
	) {
	}//end __construct()

	/**
	 * Announce a stored share to the recipient's instance. Returns whether it
	 * took the share.
	 *
	 * @param FederatedShare $row The stored share
	 * @param string $name The secret's plain name
	 * @param string $sharedSecret The share's shared secret
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
	 */
	public function announce(FederatedShare $row, string $name, string $sharedSecret): bool {
		$owner = $this->cloudIdManager->getCloudId($row->getOwnerId(), null)->getId();
		$displayName = (string)($this->userManager->getDisplayName($row->getOwnerId()) ?? $row->getOwnerId());
		$share = $this->factory->getCloudFederationShare(
			$row->getRecipientCloudId(),
			$name,
			'',
			$row->getId(),
			$owner,
			$displayName,
			$owner,
			$displayName,
			$sharedSecret,
			'user',
			FederatedShareService::RESOURCE_TYPE,
		);
		// Keepiq's own protocol entry: the receiver reads the shared secret
		// from it, and nothing in it points at a WebDAV resource.
		$share->setProtocol(['name' => 'keepiq', 'options' => ['sharedSecret' => $sharedSecret]]);

		try {
			$response = $this->providerManager->sendCloudShare($share);
		} catch (Throwable) {
			return false;
		}

		return $response->getStatusCode() === 201;
	}//end announce()

	/**
	 * Tell the recipient's instance that a share changed or ended. Returns
	 * whether it took the notification.
	 *
	 * @param FederatedShare $row The stored share
	 * @param string $type `SHARE_UPDATED` or `SHARE_UNSHARED`
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-owner-updates-reach-the-remote-copy-and-revocation-removes-it
	 */
	public function notify(FederatedShare $row, string $type): bool {
		$notification = $this->factory->getCloudFederationNotification();
		$notification->setMessage(
			$type,
			FederatedShareService::RESOURCE_TYPE,
			$row->getId(),
			['sharedSecret' => $row->getSharedSecretHash(), 'message' => $type]
		);

		try {
			$remote = $this->cloudIdManager->resolveCloudId($row->getRecipientCloudId())->getRemote();
			$response = $this->providerManager->sendCloudNotification($remote, $notification);
		} catch (Throwable) {
			return false;
		}

		return $response->getStatusCode() === 201;
	}//end notify()
}//end class
