<?php

/**
 * Keepiq Root Renewal Notice Sender
 *
 * One OCM renewal notice to one partner (partner-root-renewal-announcement D2): its own notification type, this
 * server as sender, no share and no certificate. RootRenewalNoticeDelivery decides when, and retries.
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

use OCA\Keepiq\Kernel\AppIdentity;
use OCP\Federation\ICloudFederationFactory;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\Federation\ICloudIdManager;
use Throwable;

/**
 * Sends a root renewal notice over OCM.
 *
 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-a-renewed-root-is-announced-to-partners
 */
class RootRenewalNoticeSender {
	/**
	 * Constructor for RootRenewalNoticeSender.
	 *
	 * @param ICloudFederationProviderManager $providerManager OCM delivery
	 * @param ICloudFederationFactory $factory OCM notification objects
	 * @param ICloudIdManager $cloudIdManager This server's own OCM address
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private ICloudFederationProviderManager $providerManager,
		private ICloudFederationFactory $factory,
		private ICloudIdManager $cloudIdManager,
	) {
	}//end __construct()

	/**
	 * Send the notice to a partner.
	 *
	 * @param string $baseUrl The partner's base URL
	 *
	 * @return bool Whether the partner took it
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#scenario-the-partner-renews-its-root
	 */
	public function send(string $baseUrl): bool {
		try {
			$notification = $this->factory->getCloudFederationNotification();
			$notification->setMessage(
				RootRenewalNoticeDelivery::ROOT_RENEWED,
				FederatedShareService::RESOURCE_TYPE,
				RootRenewalNoticeDelivery::NOTICE_ID,
				[
					'sharedSecret' => RootRenewalNoticeDelivery::NOTICE_ID,
					'providerId' => RootRenewalNoticeDelivery::NOTICE_ID,
					// Names this server, so the partner's Nextcloud knows whose signature to expect.
					'sender' => $this->cloudIdManager->getCloudId(AppIdentity::APP_ID, null)->getId(),
					'message' => RootRenewalNoticeDelivery::ROOT_RENEWED,
				]
			);
			$response = $this->providerManager->sendCloudNotification($baseUrl, $notification);
		} catch (Throwable) {
			return false;
		}

		return $response->getStatusCode() === 201;
	}//end send()
}//end class
