<?php

/**
 * Keepiq SeedVersionGate
 *
 * @category Repair
 * @package  OCA\Keepiq\Repair
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

namespace OCA\Keepiq\Repair;

use OCA\Keepiq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IConfig;

/**
 * When a development seed step runs: only in debug mode, and once per
 * installed app version, since post-migration repair steps re-run on every
 * upgrade and repair.
 *
 * @spec exclude idempotency fix — dev-only seed guard, no spec scenario
 */
class SeedVersionGate {

	/**
	 * Constructor.
	 *
	 * @param IConfig    $config    The system config
	 * @param IAppConfig $appConfig The app config holding the seeded versions
	 *
	 * @return void
	 */
	public function __construct(
		private IConfig $config,
		private IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The installed app version when the seed under this key should run now,
	 * or null when it should not.
	 *
	 * @param string $key The app config key the seed records its version under
	 *
	 * @return string|null
	 *
	 * @spec exclude idempotency fix — dev-only seed guard, no spec scenario
	 */
	public function dueVersion(string $key): ?string {
		if ($this->config->getSystemValueBool('debug', false) === false) {
			return null;
		}

		$appVersion = $this->appConfig->getValueString(Application::APP_ID, 'installed_version', '');
		if ($appVersion !== ''
			&& $this->appConfig->getValueString(Application::APP_ID, $key, '') === $appVersion
		) {
			return null;
		}

		return $appVersion;
	}//end dueVersion()

	/**
	 * Record that the seed under this key ran for this app version.
	 *
	 * @param string $key     The app config key
	 * @param string $version The app version it ran for
	 *
	 * @return void
	 *
	 * @spec exclude idempotency fix — dev-only seed guard, no spec scenario
	 */
	public function markSeeded(string $key, string $version): void {
		$this->appConfig->setValueString(Application::APP_ID, $key, $version);
	}//end markSeeded()
}//end class
