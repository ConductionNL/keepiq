<?php

/**
 * Keepiq Settings Service
 *
 * The settings surface every authenticated user touches: the CONFIG_KEYS
 * whitelist (`getSettings()` / `updateSettings()`) and the per-user
 * preferences. The instance-wide admin configuration — the admin payload,
 * its validated writes, the org password policy and the OpenRegister
 * register import — lives in AdminSettingsService and is re-exported here
 * so SettingsController and InitializeSettings keep one seam.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
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

namespace OCA\Keepiq\Service;

use InvalidArgumentException;
use OCA\Keepiq\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for the user-scoped Keepiq configuration surface.
 */
class SettingsService {

	/**
	 * Configuration keys managed by this service, mapped to the value
	 * `getSettings()` reports when the key has never been written.
	 *
	 * This map is a WHITELIST on both directions: `updateSettings()` writes
	 * only keys present here, and `getSettings()` reads back only keys
	 * present here. A key the settings UI posts that is missing from this
	 * map is discarded silently while the endpoint still answers
	 * `{"success": true}` — which is exactly how the two master-password
	 * floors went unpersisted from the day the panel shipped (#192).
	 *
	 * The `master_password_*` defaults are taken from PasswordPolicyService,
	 * which republishes the same floors to every authenticated user, and
	 * they deliberately match `Repair\InitializeSettings::DEFAULT_CONFIG`,
	 * so a vault whose repair step has not run still reports the real floor
	 * instead of an empty string that the UI would parse as `NaN`.
	 *
	 * @var array<string,string> key => default value
	 */
	private const CONFIG_KEYS = [
		'register' => '',
		'master_password_min_length' => '12',
		'master_password_min_score' => '3',
	];

	/**
	 * Inclusive [min, max] bounds for the numeric keys of CONFIG_KEYS.
	 *
	 * The admin panel clamps to these ranges in the browser, which means an
	 * out-of-range value can only ever arrive from a caller that bypassed
	 * the UI. Storing it unchecked would let an administrator lower the
	 * master-password floor below the app minimum through the API alone, so
	 * the bound is enforced here as well — the browser clamp is a
	 * convenience, this is the rule.
	 *
	 * @var array<string,array{0:int,1:int}>
	 */
	private const CONFIG_KEY_BOUNDS = [
		'master_password_min_length' => [12, 20],
		'master_password_min_score' => [3, 4],
	];

	/**
	 * User-scoped preference keys (implement-dashboard-settings §1.2).
	 *
	 * @var array<string,string> key => default-value
	 */
	private const USER_PREF_KEYS = [
		'session_timeout' => '',
		'notify_shares' => '1',
		'notify_requests' => '1',
		'notify_group_shares' => '1',
		'notify_security' => '1',
		'default_secret_type' => 'login',
		'default_view' => 'list',
		// Password-health staleness threshold in days: '90' | '180' | '365' |
		// 'never' (password-health §1.6, default 365). Per-user opt-in for breach
		// checking; UI is shown only when the admin gate is also on.
		'health_staleness_days' => '365',
		'breach_check_opt_in' => '0',
		// Per-user max credential age in days (rotation-expiry-policies
		// §2.2); '0' = off. Feeds effective-expiry resolution.
		'expiry_max_age_days' => '0',
		// Offline read-only cache per-device opt-out (offline-readonly-cache
		// §1.2); default on, gated behind the admin org-wide switch.
		'offline_cache_optin' => '1',
	];

	/**
	 * Permitted password-health staleness threshold values (password-health §1.6).
	 *
	 * @var string[]
	 */
	private const VALID_STALENESS_DAYS = ['90', '180', '365', 'never'];

	/**
	 * Default audit-log retention window in days (add-secret-audit-trail §4.2).
	 *
	 * Re-exported from AdminSettingsService, which owns the admin payload
	 * that reads and writes the key, so PurgeAuditLogJob keeps one name for
	 * it.
	 *
	 * @var int
	 */
	public const AUDIT_RETENTION_DEFAULT = AdminSettingsService::AUDIT_RETENTION_DEFAULT;

	/**
	 * Hard minimum audit-log retention window — below this the trail cannot
	 * serve its incident-investigation purpose, so it is rejected (design D5).
	 *
	 * @var int
	 */
	public const AUDIT_RETENTION_MIN = AdminSettingsService::AUDIT_RETENTION_MIN;

	/**
	 * The instance-wide admin configuration surface.
	 *
	 * @var AdminSettingsService
	 */
	private AdminSettingsService $adminSettings;

	/**
	 * Constructor for the SettingsService.
	 *
	 * The first eight parameters are unchanged, in name, order and type,
	 * because `AppInfo\Application::registerDomainOverrides()` constructs
	 * this service through a hand-written closure that names all eight. The
	 * ninth is the extracted admin surface: optional, so the existing
	 * closure keeps working, and container-resolved once that closure names
	 * it. When it is absent the collaborator is assembled here from the same
	 * dependencies it used to reach through `$this`.
	 *
	 * @param IAppConfig $appConfig The app config interface
	 * @param IConfig $config The per-user config interface
	 * @param IAppManager $appManager The app manager
	 * @param ContainerInterface $container The container
	 * @param IGroupManager $groupManager The group manager
	 * @param IUserSession $userSession The user session
	 * @param LoggerInterface $logger The logger
	 * @param IEventDispatcher|null $eventDispatcher The audit dispatcher (policy changes)
	 * @param AdminSettingsService|null $adminSettings The admin configuration surface
	 *
	 * @return void
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private IConfig $config,
		private IAppManager $appManager,
		ContainerInterface $container,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
		LoggerInterface $logger,
		?IEventDispatcher $eventDispatcher = null,
		?AdminSettingsService $adminSettings = null,
	) {
		$this->adminSettings = ($adminSettings ?? new AdminSettingsService(
			appConfig: $appConfig,
			appManager: $appManager,
			container: $container,
			userSession: $userSession,
			logger: $logger,
			eventDispatcher: $eventDispatcher,
		));
	}//end __construct()

	/**
	 * Get admin-scoped settings (implement-dashboard-settings §1.3).
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/implement-dashboard-settings/tasks.md#task-1.3
	 */
	public function getAdminSettings(): array {
		return $this->adminSettings->getAdminSettings();
	}//end getAdminSettings()

	/**
	 * Update admin-scoped settings with validation (implement-dashboard-settings §1.4).
	 *
	 * @param array<string,mixed> $data The input data
	 *
	 * @return array<string,mixed> The updated settings
	 *
	 * @throws InvalidArgumentException On out-of-bounds values.
	 *
	 * @spec openspec/changes/implement-dashboard-settings/tasks.md#task-1.4
	 */
	public function updateAdminSettings(array $data): array {
		return $this->adminSettings->updateAdminSettings(data: $data);
	}//end updateAdminSettings()

	/**
	 * The user-visible policy floor for the write dialogs — policy gate,
	 * generator floor, score floor, HIBP block, and exempt types only
	 * (org-password-policies §1.3).
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/org-password-policies/specs/org-password-policies/spec.md
	 */
	public function getPolicy(): array {
		return $this->adminSettings->getPolicy();
	}//end getPolicy()

	/**
	 * Load configuration from keepiq_register.json via OpenRegister.
	 *
	 * @param bool $force Force re-import even if already configured.
	 *
	 * @return array<string,mixed> Result with success flag, message, and version.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $force is passed straight through to
	 *   OpenRegister's ADR-022 importFromApp(appId, data, version, force) signature; it is
	 *   never a branch here. See RegisterConfigurationLoader::loadConfiguration().
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-6
	 */
	public function loadConfiguration(bool $force = false): array {
		return $this->adminSettings->loadConfiguration(force: $force);
	}//end loadConfiguration()

	/**
	 * Get the per-user preferences (implement-dashboard-settings §1.5).
	 *
	 * @param string $userId The user ID
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/implement-dashboard-settings/tasks.md#task-1.5
	 */
	public function getUserPreferences(string $userId): array {
		$appId = Application::APP_ID;
		$adminDefault = $this->appConfig->getValueString($appId, 'default_session_timeout', 'session');

		$prefs = [];
		foreach (self::USER_PREF_KEYS as $key => $default) {
			if ($key === 'session_timeout') {
				$prefs[$key] = $this->config->getUserValue($userId, $appId, $key, $adminDefault);
				continue;
			}

			$prefs[$key] = $this->config->getUserValue($userId, $appId, $key, $default);
		}

		return $prefs;
	}//end getUserPreferences()

	/**
	 * Update per-user preferences (whitelisted; implement-dashboard-settings §1.6).
	 *
	 * @param string $userId The user ID
	 * @param array<string,mixed> $data The input data
	 *
	 * @return array<string,mixed> The updated preferences
	 *
	 * @spec openspec/changes/implement-dashboard-settings/tasks.md#task-1.6
	 */
	public function updateUserPreferences(string $userId, array $data): array {
		$appId = Application::APP_ID;

		foreach ($data as $key => $value) {
			if (array_key_exists($key, self::USER_PREF_KEYS) === false) {
				continue;
			}

			if (is_bool($value) === true) {
				$encoded = '0';
				if ($value === true) {
					$encoded = '1';
				}

				$value = $encoded;
			}

			// Reject an out-of-set staleness threshold so the client cannot store
			// an arbitrary "never-stale" sentinel (password-health §1.6).
			if ($key === 'health_staleness_days'
				&& in_array((string)$value, self::VALID_STALENESS_DAYS, true) === false
			) {
				throw new InvalidArgumentException(
					'health_staleness_days must be one of: ' . implode(', ', self::VALID_STALENESS_DAYS)
				);
			}

			$this->config->setUserValue($userId, $appId, $key, (string)$value);
		}//end foreach

		return $this->getUserPreferences(userId: $userId);
	}//end updateUserPreferences()

	/**
	 * Check whether OpenRegister is installed and available.
	 *
	 * @return bool
	 */
	public function isOpenRegisterAvailable(): bool {
		return $this->appManager->isInstalled('openregister');
	}//end isOpenRegisterAvailable()

	/**
	 * Retrieve all current settings.
	 *
	 * Returns a flat array containing all app config values plus metadata
	 * fields (openregisters, isAdmin) consumed by the frontend.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-5
	 */
	public function getSettings(): array {
		$settings = [];
		foreach (self::CONFIG_KEYS as $key => $default) {
			$settings[$key] = $this->appConfig->getValueString(Application::APP_ID, $key, $default);
		}

		$user = $this->userSession->getUser();
		$isAdmin = ($user !== null && $this->groupManager->isAdmin($user->getUID()));

		return array_merge(
			$settings,
			[
				'openregisters' => $this->isOpenRegisterAvailable(),
				'isAdmin' => $isAdmin,
			]
		);
	}//end getSettings()

	/**
	 * Update settings with the provided data.
	 *
	 * @param array<string,mixed> $data The data to update
	 *
	 * @return array<string,mixed> The updated settings
	 *
	 * @throws InvalidArgumentException When a bounded key carries a value
	 *                                  outside its CONFIG_KEY_BOUNDS range.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-5
	 */
	public function updateSettings(array $data): array {
		foreach (array_keys(self::CONFIG_KEYS) as $key) {
			if (isset($data[$key]) === false) {
				continue;
			}

			$this->appConfig->setValueString(
				Application::APP_ID,
				$key,
				$this->boundedConfigValue(key: $key, value: $data[$key])
			);
		}

		return $this->getSettings();
	}//end updateSettings()

	/**
	 * Coerce one CONFIG_KEYS value to the string that gets stored, enforcing
	 * the key's bounds when it has any.
	 *
	 * @param string $key The CONFIG_KEYS key being written
	 * @param mixed $value The submitted value
	 *
	 * @return string The value to store
	 *
	 * @throws InvalidArgumentException When a bounded key is non-integer or
	 *                                  outside its inclusive range.
	 */
	private function boundedConfigValue(string $key, mixed $value): string {
		if (isset(self::CONFIG_KEY_BOUNDS[$key]) === false) {
			return (string)$value;
		}

		[$min, $max] = self::CONFIG_KEY_BOUNDS[$key];

		$number = filter_var($value, FILTER_VALIDATE_INT);
		if ($number === false || $number < $min || $number > $max) {
			throw new InvalidArgumentException(
				message: $key . ' must be a whole number between ' . $min . ' and ' . $max
			);
		}

		return (string)$number;
	}//end boundedConfigValue()
}//end class
