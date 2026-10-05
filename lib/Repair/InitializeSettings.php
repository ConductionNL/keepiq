<?php

/**
 * Keepiq Initialize Settings Repair Step
 *
 * Repair step that seeds Keepiq's default app config on install/upgrade and
 * records the configured version the admin version card compares against.
 *
 * @category Repair
 * @package  OCA\Keepiq\Repair
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

namespace OCA\Keepiq\Repair;

use OCA\Keepiq\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Repair step that seeds Keepiq's default configuration.
 *
 * @spec openspec/specs/app-shell/spec.md#requirement-native-admin-section-and-version-card
 */
class InitializeSettings implements IRepairStep {
	private const DEFAULT_CONFIG = [
		'master_password_min_length' => '12',
		'master_password_min_score' => '3',
		'session_timeout_default' => '600000',
		// Default opt-in flags for sharing notifications (W29 §1.6).
		// These are admin-side defaults consumed by SettingsService when
		// a per-user pref is absent — user prefs themselves stay
		// user-scoped via IConfig::setUserValue + the SettingsService
		// USER_PREF_KEYS default map ('1' for each notify_* flag).
		'default_notify_shares' => '1',
		'default_notify_group_shares' => '1',
		'default_notify_security' => '1',
		'default_notify_requests' => '1',
	];

	/**
	 * Constructor for InitializeSettings.
	 *
	 * @param IAppConfig  $appConfig  The app config interface
	 * @param IAppManager $appManager The app manager (installed version)
	 *
	 * @return void
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private IAppManager $appManager,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/app-shell/spec.md#requirement-native-admin-section-and-version-card
	 */
	public function getName(): string {
		return 'Initialize Keepiq default configuration';
	}//end getName()

	/**
	 * Run the repair step to initialize Keepiq configuration.
	 *
	 * @param IOutput $output The output interface for progress reporting
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-shell/spec.md#requirement-native-admin-section-and-version-card
	 */
	public function run(IOutput $output): void {
		$output->info('Initializing Keepiq configuration...');

		// Seed default app config values for encryption settings.
		foreach (self::DEFAULT_CONFIG as $key => $value) {
			$existing = $this->appConfig->getValueString(Application::APP_ID, $key, '');
			if ($existing === '') {
				$this->appConfig->setValueString(Application::APP_ID, $key, $value);
				$output->info("Set default config: {$key} = {$value}");
			}
		}

		// The version the admin version card compares the installed version
		// with. Keepiq imports no configuration from another app (ADR-006), so
		// seeding the defaults above IS the configuration step: once it has
		// run, the configuration is current for this version.
		$version = $this->appManager->getAppVersion(Application::APP_ID);
		$this->appConfig->setValueString(Application::APP_ID, 'config_version', $version);
		$output->info('Keepiq configuration is current (version: ' . $version . ')');
	}//end run()
}//end class
