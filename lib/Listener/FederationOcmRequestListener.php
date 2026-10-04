<?php

/**
 * Keepiq Federation OCM Request Listener
 *
 * Answers the partner-facing Keepiq endpoints under OCM
 * (sharing-federated-recipients D2, D3): Nextcloud's OCMRequestController
 * verifies the HTTP signature of every request to /ocm/<path> and
 * dispatches OCMEndpointRequestEvent with the signer as getRemote(). This
 * listener acts only on the `keepiq` capability.
 *
 * POST /ocm/keepiq/recipient-certificate answers a recipient's certificate
 * and CA chain, or the one unknown-recipient answer for every refusal: an
 * unsigned call, a signer that is no inbound partner, a user who does not
 * exist here, has not opted in, or holds no active suite.
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
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\OCM\Events\OCMEndpointRequestEvent;

/**
 * Serves /ocm/keepiq/... for verified partners.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
 */
class FederationOcmRequestListener implements IEventListener {
	/**
	 * Constructor for FederationOcmRequestListener.
	 *
	 * @param FederatedCertificateService $certificates The federated certificate lookup
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private FederatedCertificateService $certificates,
	) {
	}//end __construct()

	/**
	 * Answer a Keepiq OCM request; leave every other capability alone.
	 *
	 * @param Event $event The dispatched event
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
	 */
	public function handle(Event $event): void {
		if ($event instanceof OCMEndpointRequestEvent === false
			|| $event->getRequestedCapability() !== FederatedCertificateService::OCM_CAPABILITY
		) {
			return;
		}

		if ($event->getPath() === '/recipient-certificate' && strtoupper($event->getUsedMethod()) === 'POST') {
			$answer = $this->certificates->answer(signer: $event->getRemote(), payload: $event->getPayload());
			if ($answer !== null) {
				$event->setResponse(new JSONResponse(data: $answer));
				return;
			}
		}

		// One answer for every refusal and every unknown path, so nothing
		// here tells a caller why.
		$event->setResponse(
			new JSONResponse(data: ['message' => 'Unknown recipient'], statusCode: Http::STATUS_NOT_FOUND)
		);
	}//end handle()
}//end class
