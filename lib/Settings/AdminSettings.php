<?php

/**
 * Keepiq Admin Settings: the General area
 *
 * The first of the five delegable Keepiq admin areas (admin-scoped-roles D1).
 * It keeps its historical class name, so a delegation an administrator made
 * before the split still means something: it now grants the General area.
 * Nextcloud loads it from info.xml `<settings><admin>`, and
 * DomainOverrideRegistrar binds this concrete class over the AppHost generic,
 * so `get_class()` of the registered instance is this class and a delegation
 * of it satisfies `#[AuthorizedAdminSetting(AdminSettings::class)]`.
 *
 * @category Settings
 * @package  OCA\Keepiq\Settings
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

namespace OCA\Keepiq\Settings;

use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Service\AdminAreaAuthorizer;
use OCP\App\IAppManager;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;

/**
 * The General area: version, CA, attachments, offline cache, breach check,
 * secret types and vault backups.
 *
 * @psalm-suppress UnusedClass Loaded by Nextcloud from appinfo/info.xml <settings>.
 */
class AdminSettings extends AdminAreaSettings {
	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n Translates the area name
	 * @param IInitialState $initialState The admin bundle's initial state
	 * @param IAppManager $appManager The running app version
	 * @param IAppConfig $appConfig The version the configuration was imported for
	 * @param IGroupManager $groupManager Counts the legacy vault_admin group
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		IL10N $l10n,
		IInitialState $initialState,
		private readonly IAppManager $appManager,
		private readonly IAppConfig $appConfig,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct(l10n: $l10n, initialState: $initialState);
	}//end __construct()

	/**
	 * The area key.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/admin-scoped-roles/tasks.md#1.1
	 */
	public function getArea(): string {
		return 'general';
	}//end getArea()

	/**
	 * The area name on Nextcloud's "Administration privileges" page.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/admin-scoped-roles/tasks.md#1.1
	 */
	public function getName(): string {
		return $this->l10n->t('General');
	}//end getName()

	/**
	 * First in the section.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/admin-scoped-roles/tasks.md#1.1
	 */
	public function getPriority(): int {
		return 10;
	}//end getPriority()

	/**
	 * The version card state the admin settings shell reads (formerly
	 * provided by the AppHost generic), and the size of the legacy
	 * vault_admin group for the "Admin areas" notice (admin-scoped-roles D4).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-scoped-roles/tasks.md#3.3
	 */
	protected function provideAreaState(): void {
		$version = $this->appManager->getAppVersion(Application::APP_ID);
		// unclosable-gate exclude 'config_version' is written by OpenRegister's AppHost configuration
		// import, not by this app; it is only displayed here and guards no setup.
		$configuredVersion = $this->appConfig->getValueString(Application::APP_ID, 'config_version', '');
		$this->initialState->provideInitialState('version', $version);
		$this->initialState->provideInitialState('configuredVersion', $configuredVersion);
		$this->initialState->provideInitialState('isUpToDate', ($configuredVersion !== '' && $configuredVersion === $version));

		$legacyGroup = $this->groupManager->get(AdminAreaAuthorizer::LEGACY_PEOPLE_GROUP);
		$this->initialState->provideInitialState('vault-admin-members', ($legacyGroup?->count() ?? 0));
	}//end provideAreaState()
}//end class
