<?php

/**
 * Keepiq platform-integration registrar
 *
 * Registers the Nextcloud platform extension points Keepiq plugs into:
 * unified search, notifications, request middleware and setup checks.
 *
 * @category AppInfo
 * @package  OCA\Keepiq\AppInfo
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\AppInfo;

use OCA\Keepiq\Listener\FederationEventRegistrar;
use OCA\Keepiq\Middleware\MiddlewareRegistrar;
use OCA\Keepiq\Notification\NotificationRegistrar;
use OCA\Keepiq\Search\SearchRegistrar;
use OCA\Keepiq\SetupCheck\SetupCheckRegistrar;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Plugs Keepiq into Nextcloud's own extension points.
 *
 * These registrations are not Keepiq domain wiring — they are the places
 * where the PLATFORM calls into this app: the unified-search bar, the
 * notification renderer, the request pipeline, the admin overview's setup
 * checks and Open Cloud Mesh. They are grouped because
 * they share that direction of control and because each one is a single
 * class handed to a core registry, with no ordering relationship to the
 * domain listeners.
 */
final class PlatformIntegrationRegistrar {
	/**
	 * Register the search provider, the notifier, the middleware and the setup
	 * check.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec exclude composition-root wiring — the registered classes carry the
	 *   behaviour and their own spec references; this method only binds them.
	 */
	public function register(IRegistrationContext $context): void {
		(new SearchRegistrar())->register(context: $context);
		(new NotificationRegistrar())->register(context: $context);
		(new MiddlewareRegistrar())->registerPlatformMiddleware(context: $context);
		(new SetupCheckRegistrar())->register(context: $context);

		// Open Cloud Mesh: Nextcloud's OCM discovery and endpoint-request
		// events, through which partner instances reach the federation
		// endpoints (sharing-federated-recipients).
		(new FederationEventRegistrar())->register(context: $context);

	}//end register()

	/**
	 * Boot-time wiring of the platform extension points: the Open Cloud
	 * Mesh provider for federated secrets, which Nextcloud registers through
	 * a service rather than a registration context.
	 *
	 * @param IBootContext $context The boot context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
	 */
	public function boot(IBootContext $context): void {
		(new FederationEventRegistrar())->boot(context: $context);
	}//end boot()
}//end class
