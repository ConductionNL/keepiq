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

use OCA\Keepiq\Federation\KeepiqSecretFederationProvider;
use OCA\Keepiq\Listener\FederationOcmDiscoveryListener;
use OCA\Keepiq\Listener\FederationOcmRequestListener;
use OCA\Keepiq\Service\FederatedShareService;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\OCM\Events\LocalOCMDiscoveryEvent;
use OCP\OCM\Events\OCMEndpointRequestEvent;

/**
 * Wires the federation OCM listeners.
 *
 * @spec openspec/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
 */
final class FederationEventRegistrar {
	/**
	 * Bind the OCM discovery and endpoint listeners.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
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

	/**
	 * Register the `keepiq-secret` OCM provider (task 3.1). Only where the
	 * OCM endpoint event exists (Nextcloud 33 and later): below that,
	 * federation stays off and no share of this type is accepted.
	 *
	 * @param IBootContext $context The boot context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
	 */
	public function boot(IBootContext $context): void {
		if (class_exists(OCMEndpointRequestEvent::class) === false) {
			return;
		}

		$container = $context->getAppContainer();
		$context->injectFn(
			static function (ICloudFederationProviderManager $manager) use ($container): void {
				$manager->addCloudFederationProvider(
					FederatedShareService::RESOURCE_TYPE,
					'Keepiq secret',
					static fn () => $container->get(KeepiqSecretFederationProvider::class)
				);
			}
		);
	}//end boot()
}//end class
