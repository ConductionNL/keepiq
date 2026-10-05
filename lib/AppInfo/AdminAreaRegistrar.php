<?php

/**
 * Keepiq admin area registrar
 *
 * Registers the five admin area settings classes as themselves
 * (admin-scoped-roles D1).
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

use OCA\Keepiq\Settings\AdminSettings;
use OCA\Keepiq\Settings\ApplicationAdminSettings;
use OCA\Keepiq\Settings\AuditAdminSettings;
use OCA\Keepiq\Settings\PeopleAdminSettings;
use OCA\Keepiq\Settings\PolicyAdminSettings;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Binds every admin area id to an instance of exactly that class.
 *
 * @spec openspec/specs/app-shell/spec.md#requirement-native-admin-section-and-version-card
 */
final class AdminAreaRegistrar {
	/**
	 * Register the five admin area classes as themselves
	 * (admin-scoped-roles D1).
	 *
	 * `get_class()` on the delegation page and in
	 * `IManager::getAllowedAdminSettings()` must name Keepiq's own class, or a
	 * delegation can never satisfy
	 * `#[AuthorizedAdminSetting(AdminSettings::class)]`. Registering each
	 * concrete class as itself makes the registered instance the class a
	 * guard names. (Under the former AppHost engine, `AdminSettings` was
	 * bound to a generic class, which is why this registrar exists.)
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#1.2
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerService(
			AdminSettings::class,
			static fn ($c) => new AdminSettings(
				l10n: $c->get(\OCP\IL10N::class),
				initialState: $c->get(\OCP\AppFramework\Services\IInitialState::class),
				appManager: $c->get(\OCP\App\IAppManager::class),
				appConfig: $c->get(\OCP\IAppConfig::class),
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
	}//end register()
}//end class
