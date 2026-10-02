<?php

/**
 * Keepiq domain-override registrar
 *
 * Re-registers the plumbing classes whose Keepiq behaviour diverges
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
use OCA\Keepiq\Settings\AdminSettings;
use OCA\Keepiq\Settings\ApplicationAdminSettings;
use OCA\Keepiq\Settings\AuditAdminSettings;
use OCA\Keepiq\Settings\PeopleAdminSettings;
use OCA\Keepiq\Settings\PolicyAdminSettings;
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
				// Container-built, so the password policy service it holds
				// carries the vault policies (admin-vault-policies D1).
				adminSettings: $c->get('OCA\Keepiq\Service\AdminSettingsService'),
				areas: $c->get(\OCA\Keepiq\Service\AdminAreaAuthorizer::class),
			)
		);
		// SettingsControllerFactory spells out every argument, the integriq
		// connection reporter included (adopt-connection-registry).
		$context->registerService(SettingsController::class, new SettingsControllerFactory());
		$context->registerService(
			InitializeSettings::class,
			static fn ($c) => new InitializeSettings(
				settingsService: $c->get(SettingsService::class),
				appConfig: $c->get(\OCP\IAppConfig::class),
				logger: $c->get(\Psr\Log\LoggerInterface::class),
			)
		);

		$this->registerAdminAreas(context: $context);
	}//end register()

	/**
	 * Register the five admin area classes as themselves
	 * (admin-scoped-roles D1).
	 *
	 * The AppHost engine binds `AdminSettings` to an instance of its generic
	 * class, so `get_class()` on the delegation page and in
	 * `IManager::getAllowedAdminSettings()` named the generic, never Keepiq's
	 * class, and a delegation could never satisfy
	 * `#[AuthorizedAdminSetting(AdminSettings::class)]`. Registering the
	 * concrete classes here, after the engine, makes the registered instance
	 * the class a guard names.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-scoped-roles/tasks.md#1.2
	 */
	private function registerAdminAreas(IRegistrationContext $context): void {
		$context->registerService(
			AdminSettings::class,
			static fn ($c) => new AdminSettings(
				l10n: $c->get(\OCP\IL10N::class),
				initialState: $c->get(\OCP\AppFramework\Services\IInitialState::class),
				appManager: $c->get(\OCP\App\IAppManager::class),
				appConfig: $c->get(\OCP\IAppConfig::class),
				groupManager: $c->get(\OCP\IGroupManager::class),
			)
		);

		foreach ([PolicyAdminSettings::class, ApplicationAdminSettings::class, PeopleAdminSettings::class, AuditAdminSettings::class] as $area) {
			$context->registerService(
				$area,
				static fn ($c) => new $area(
					l10n: $c->get(\OCP\IL10N::class),
					initialState: $c->get(\OCP\AppFramework\Services\IInitialState::class),
				)
			);
		}
	}//end registerAdminAreas()
}//end class
