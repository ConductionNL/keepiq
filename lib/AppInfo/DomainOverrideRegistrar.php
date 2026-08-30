<?php

/**
 * Keepiq domain-override registrar
 *
 * Re-registers the three plumbing classes whose Keepiq behaviour diverges
 * from the generic AppHost implementation, so the concrete leaf classes win
 * over the engine's aliases.
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

use OCA\Keepiq\Controller\SettingsController;
use OCA\Keepiq\Repair\InitializeSettings;
use OCA\Keepiq\Service\SettingsService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Binds Keepiq's concrete settings stack over the AppHost generics.
 *
 * All three registrations belong to one capability — settings — and MUST run
 * after {@see AppHostRegistrar}, because a `registerService()` for a class the
 * engine already aliased only wins when it is registered last:
 *
 * - `SettingsService`    — register.d fragment merge + admin/user-preference
 *                          split (ADR-037).
 * - `SettingsController` — admin/user settings split and
 *                          `#[AuthorizedAdminSetting(AdminSettings::class)]`.
 * - `InitializeSettings` — domain default-config seeding on install/upgrade.
 *
 * The closures spell out every constructor argument rather than relying on
 * autowiring because the container already holds an alias for these ids; a
 * closure is the only registration shape that overrides one.
 */
final class DomainOverrideRegistrar {
	/**
	 * Override the generic AppHost aliases with Keepiq's concretes.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerService(
			SettingsService::class,
			static fn ($c) => new SettingsService(
				appConfig: $c->get(\OCP\IAppConfig::class),
				config: $c->get(\OCP\IConfig::class),
				appManager: $c->get(\OCP\App\IAppManager::class),
				container: $c,
				groupManager: $c->get(\OCP\IGroupManager::class),
				userSession: $c->get(\OCP\IUserSession::class),
				logger: $c->get(\Psr\Log\LoggerInterface::class),
				eventDispatcher: $c->get(\OCP\EventDispatcher\IEventDispatcher::class),
			)
		);
		$context->registerService(
			SettingsController::class,
			static fn ($c) => new SettingsController(
				request: $c->get(\OCP\IRequest::class),
				settingsService: $c->get(SettingsService::class),
				userSession: $c->get(\OCP\IUserSession::class),
			)
		);
		$context->registerService(
			InitializeSettings::class,
			static fn ($c) => new InitializeSettings(
				settingsService: $c->get(SettingsService::class),
				appConfig: $c->get(\OCP\IAppConfig::class),
				logger: $c->get(\Psr\Log\LoggerInterface::class),
			)
		);

	}//end register()
}//end class
