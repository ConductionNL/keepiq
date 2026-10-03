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
 * POST /ocm/keepiq/shares/{id} answers a federated share's ciphertext to
 * the recipient's partner presenting the share's shared secret (D4), and
 * the same unknown answer to anyone else.
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
use OCA\Keepiq\Service\FederatedShareService;
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
	 * @param FederatedShareService $shares The outbound federated shares
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private FederatedCertificateService $certificates,
		private FederatedShareService $shares,
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

		$answer = $this->answerFor(event: $event);
		if ($answer !== null) {
			$event->setResponse(new JSONResponse(data: $answer));
			return;
		}

		// One answer for every refusal and every unknown path, so nothing
		// here tells a caller why.
		$event->setResponse(
			new JSONResponse(data: ['message' => 'Unknown recipient'], statusCode: Http::STATUS_NOT_FOUND)
		);
	}//end handle()

	/**
	 * The answer to a Keepiq OCM request, or null for the unknown answer.
	 *
	 * @param OCMEndpointRequestEvent $event The request
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
	 */
	private function answerFor(OCMEndpointRequestEvent $event): ?array {
		if (strtoupper($event->getUsedMethod()) !== 'POST') {
			return null;
		}

		$path = $event->getPath();
		if ($path === '/recipient-certificate') {
			return $this->certificates->answer(signer: $event->getRemote(), payload: $event->getPayload());
		}

		if (preg_match('#^/shares/([0-9a-f-]{36})$#', $path, $match) === 1) {
			return $this->shares->answerPull(signer: $event->getRemote(), shareId: $match[1], payload: $event->getPayload());
		}

		return null;
	}//end answerFor()
}//end class
