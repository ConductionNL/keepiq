<?php

/**
 * Keepiq Federation Event Registrar
 *
 * Binds the OCM listeners of federated sharing (sharing-federated-recipients
 * D2). Both events exist from Nextcloud 33; on 32 they are never dispatched,
 * so the registration is inert there.
 *
 * @category AppInfo
 * @package  OCA\Keepiq\AppInfo
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

namespace OCA\Keepiq\AppInfo;

use OCA\Keepiq\Listener\FederationOcmDiscoveryListener;
use OCA\Keepiq\Listener\FederationOcmRequestListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\OCM\Events\LocalOCMDiscoveryEvent;
use OCP\OCM\Events\OCMEndpointRequestEvent;

/**
 * Wires the federation OCM listeners.
 *
 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
 */
final class FederationEventRegistrar {
	/**
	 * Bind the OCM discovery and endpoint listeners.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(
			event: LocalOCMDiscoveryEvent::class,
			listener: FederationOcmDiscoveryListener::class
		);
		$context->registerEventListener(
			event: OCMEndpointRequestEvent::class,
			listener: FederationOcmRequestListener::class
		);
	}//end register()
}//end class
