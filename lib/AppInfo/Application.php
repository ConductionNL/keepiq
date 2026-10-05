<?php

/**
 * Keepiq Application
 *
 * Main application class for the Keepiq Nextcloud app.
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

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Main application class for the Keepiq Nextcloud app.
 *
 * This class is the composition root and little else. The wiring itself lives
 * in single-purpose registrars in this namespace, each of which owns one
 * domain's bindings and can be unit-tested on its own — `Application` cannot
 * be constructed without a Nextcloud DI container, so anything written inline
 * here is unreachable from a test.
 *
 * The registrars are plain collaborators instantiated with `new` rather than
 * resolved from the container: `register()` IS the point at which the
 * container is being populated, so there is nothing to resolve from yet.
 *
 * Keepiq runs its own app shell and references no class from another app
 * (ADR-006): it is installable and fully usable with no other Conduction app
 * enabled. Integrations with another app register inertly and only come
 * alive when that app runs (see McpRegistrar).
 *
 * @spec openspec/specs/app-shell/spec.md#requirement-keepiq-operates-without-any-other-conduction-app
 */
class Application extends App implements IBootstrap {
	public const APP_ID = 'keepiq';

	/**
	 * The app version by which every pre-rename compatibility shim is gone.
	 *
	 * The doriath -> keepiq rename left a handful of published identifiers
	 * carrying the old codename: the assertion audience, the discovery path
	 * and the envelope format name. Each is accepted or announced in parallel
	 * with its replacement so no consumer needs a flag day — but only while
	 * the app is pre-stable. This app has never shipped a stable release, so
	 * there is no released contract to preserve and no reason to carry a dead
	 * codename past 1.0.0; the shims are removed before the first stable
	 * release, not deferred to a future apiVersion.
	 *
	 * `apiVersion` therefore stays at 1 throughout. The "breaking changes
	 * MUST ship as a new apiVersion" rule in the secret-store-api spec binds
	 * from the first stable release onward, which is exactly the point these
	 * shims stop existing.
	 *
	 * @var string
	 */
	public const PRE_STABLE_COMPAT_REMOVED_IN = '1.0.0';

	/**
	 * Constructor for the Application class.
	 *
	 * @return void
	 */
	public function __construct() {
		parent::__construct(appName: self::APP_ID);
	}//end __construct()

	/**
	 * Register event listeners and services.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-shell/spec.md#requirement-keepiq-operates-without-any-other-conduction-app
	 */
	public function register(IRegistrationContext $context): void {
		include_once __DIR__ . '/../../vendor/autoload.php';

		// Keepiq's own settings stack and the admin areas, as themselves, so a
		// delegation of an area satisfies the guard that names it
		// (admin-scoped-roles D1).
		(new DomainOverrideRegistrar())->register(context: $context);
		(new AdminAreaRegistrar())->register(context: $context);

		// MCP opt-in (hermiq-ai-tooling): three metadata-only read tools for AI
		// agents. The alias is inert unless OpenRegister runs and asks for it.
		(new McpRegistrar())->register(context: $context);

		// Domain event wiring, one registrar per trigger family. Each is
		// independent: a listener graph can be extended without touching the
		// other two, and none of them can abort the others.
		(new SuiteLifecycleEventRegistrar())->register(context: $context);
		(new UserLifecycleEventRegistrar())->register(context: $context);
		(new AuditStreamEventRegistrar())->register(context: $context);

		// Nextcloud's own extension points: unified search, notifications and
		// the JWT-Bearer request middleware.
		(new PlatformIntegrationRegistrar())->register(context: $context);

		// Domain repair steps (BootstrapCertificateAuthority, InitializeSettings,
		// SeedSecretTypes, the Seed* development data steps) are registered via
		// info.xml <repair-steps>. InitializeSettings is the Keepiq concrete
		// registered above (domain default-config seeding); the rest are
		// crypto/seed domain steps owned by the app.
	}//end register()

	/**
	 * Boot the application.
	 *
	 * @param IBootContext $context The boot context
	 *
	 * All wiring happens in register(), except the OCM provider of federated
	 * sharing, which registers at boot.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-shell/spec.md#requirement-keepiq-operates-without-any-other-conduction-app
	 */
	public function boot(IBootContext $context): void {
		(new PlatformIntegrationRegistrar())->boot(context: $context);
	}//end boot()
}//end class
