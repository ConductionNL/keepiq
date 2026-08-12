<?php

/**
 * Doriath platform-integration registrar
 *
 * Registers the three Nextcloud platform extension points Doriath plugs into:
 * unified search, notifications, and request middleware.
 *
 * @category AppInfo
 * @package  OCA\Doriath\AppInfo
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

namespace OCA\Doriath\AppInfo;

use OCA\Doriath\Middleware\JwtAuthMiddleware;
use OCA\Doriath\Notification\DoriathNotifier;
use OCA\Doriath\Search\SecretSearchProvider;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Plugs Doriath into Nextcloud's own extension points.
 *
 * These three registrations are not Doriath domain wiring — they are the
 * places where the PLATFORM calls into this app: the unified-search bar, the
 * notification renderer, and the request pipeline. They are grouped because
 * they share that direction of control and because each one is a single
 * class handed to a core registry, with no ordering relationship to the
 * domain listeners or the AppHost plumbing.
 */
final class PlatformIntegrationRegistrar {
	/**
	 * Register the search provider, the notifier and the middleware.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec exclude composition-root wiring — the registered classes carry the
	 *   behaviour and their own spec references; this method only binds them.
	 */
	public function register(IRegistrationContext $context): void {
		// The Nextcloud unified search provider for secrets. It queries
		// unencrypted name/url metadata only and needs no vault session
		// (ADR-003).
		$context->registerSearchProvider(SecretSearchProvider::class);

		// The notifier responsible for rendering sharing, secret-request and
		// application-management notification subjects.
		$context->registerNotifierService(DoriathNotifier::class);

		// The JWT-Bearer middleware for application-authenticated routes.
		// Fires only on ApplicationApiController subclasses; session
		// controllers pass through untouched.
		$context->registerMiddleware(JwtAuthMiddleware::class);

	}//end register()
}//end class
