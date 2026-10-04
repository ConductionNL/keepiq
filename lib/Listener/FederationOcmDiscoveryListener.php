<?php

/**
 * Keepiq Federation OCM Discovery Listener
 *
 * Advertises the OCM capability `keepiq` in this instance's OCM discovery
 * while at least one federation partner exists (sharing-federated-recipients
 * D2). A partner's requestRemoteOcmEndpoint('keepiq', ...) checks for it, and
 * an instance without partners does not announce the feature.
 *
 * @category Listener
 * @package  OCA\Keepiq\Listener
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

namespace OCA\Keepiq\Listener;

use OCA\Keepiq\Service\FederatedCertificateService;
use OCA\Keepiq\Service\FederationPartnerService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\OCM\Events\LocalOCMDiscoveryEvent;

/**
 * Adds the `keepiq` capability to the local OCM discovery.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
 */
class FederationOcmDiscoveryListener implements IEventListener {
	/**
	 * Constructor for FederationOcmDiscoveryListener.
	 *
	 * @param FederationPartnerService $partners The partner allowlist
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private FederationPartnerService $partners,
	) {
	}//end __construct()

	/**
	 * Add the capability when a partner exists.
	 *
	 * @param Event $event The dispatched event
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
	 */
	public function handle(Event $event): void {
		if ($event instanceof LocalOCMDiscoveryEvent === false || $this->partners->hasAny() === false) {
			return;
		}

		$event->addCapability(FederatedCertificateService::OCM_CAPABILITY);
	}//end handle()
}//end class
