<?php

/**
 * Keepiq domain-override registrar
 *
 * Registers Keepiq's settings stack: the three classes that own settings
 * reads, writes and install-time seeding.
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
 * Binds Keepiq's concrete settings stack.
 *
 * All three registrations belong to one capability, settings:
 *
 * - `SettingsService`    — the admin/user-preference split.
 * - `SettingsController` — admin/user settings split and
 *                          `#[AuthorizedAdminSetting(AdminSettings::class)]`.
 * - `InitializeSettings` — domain default-config seeding on install/upgrade,
 *                          and the `config_version` the version card reads.
 *
 * The closures spell out every constructor argument. They date from the
 * AppHost era, when a closure was the only shape that overrode the engine's
 * aliases; they are kept because they pin exactly which services each class
 * receives (ADR-006 removed the engine, not the need for explicit wiring).
 *
 * @spec openspec/specs/app-shell/spec.md#requirement-keepiq-operates-without-any-other-conduction-app
 */
final class DomainOverrideRegistrar {
	/**
	 * Register Keepiq's settings stack.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-shell/spec.md#requirement-keepiq-operates-without-any-other-conduction-app
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerService(
			SettingsService::class,
			static fn ($c) => new SettingsService(
				appConfig: $c->get(\OCP\IAppConfig::class),
				config: $c->get(\OCP\IConfig::class),
				container: $c,
				groupManager: $c->get(\OCP\IGroupManager::class),
				userSession: $c->get(\OCP\IUserSession::class),
				logger: $c->get(\Psr\Log\LoggerInterface::class),
				eventDispatcher: $c->get(\OCP\EventDispatcher\IEventDispatcher::class),
				// Container-built, so the password policy service it holds
				// carries the vault policies (admin-vault-policies D1).
				adminSettings: $c->get('OCA\Keepiq\Service\AdminSettingsService'),
			)
		);
		// SettingsControllerFactory spells out every argument, the integriq
		// connection reporter included (adopt-connection-registry).
		$context->registerService(SettingsController::class, new SettingsControllerFactory());
		$context->registerService(
			InitializeSettings::class,
			static fn ($c) => new InitializeSettings(
				appConfig: $c->get(\OCP\IAppConfig::class),
				appManager: $c->get(\OCP\App\IAppManager::class),
			)
		);

	}//end register()
}//end class
