<?php

/**
 * Doriath Settings Service
 *
 * Service for managing Doriath application configuration and settings.
 *
 * @category Service
 * @package  OCA\Doriath\Service
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

namespace OCA\Doriath\Service;

use InvalidArgumentException;
use OCA\Doriath\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Service for managing Doriath application configuration and settings.
 */
class SettingsService
{

    /**
     * Configuration keys managed by this service.
     *
     * @var array<string>
     */
    private const CONFIG_KEYS = [
        'register',
    ];

    /**
     * Hardcoded floor for the master-password minimum length. An admin
     * may raise the policy above this value but never below it.
     *
     * @var int
     */
    private const MIN_PASSWORD_LENGTH_FLOOR = 12;

    /**
     * Hardcoded ceiling for the master-password minimum length.
     *
     * @var int
     */
    private const MIN_PASSWORD_LENGTH_CEILING = 20;

    /**
     * Hardcoded floor for the master-password zxcvbn strength score.
     *
     * @var int
     */
    private const MIN_PASSWORD_SCORE_FLOOR = 3;

    /**
     * Hardcoded ceiling for the master-password zxcvbn strength score.
     *
     * @var int
     */
    private const MIN_PASSWORD_SCORE_CEILING = 4;

    /**
     * Allowed values for the session-timeout preference.
     *
     * @var array<string>
     */
    private const SESSION_TIMEOUT_VALUES = [
        'session',
        '10min',
        '30min',
    ];

    /**
     * Per-user preference keys writable via IConfig, with their default
     * fallback values. Booleans are stored as the string '1' / '0' per
     * Nextcloud convention. `session_timeout` falls back to the
     * admin-configured default at read time (see getUserPreferences()).
     *
     * @var array<string,string>
     */
    private const USER_PREF_KEYS = [
        'session_timeout'     => '',
        'notify_shares'       => '1',
        'notify_requests'     => '1',
        'notify_group_shares' => '1',
        'notify_security'     => '1',
        'default_secret_type' => 'login',
        'default_view'        => 'list',
    ];

    /**
     * Constructor for the SettingsService.
     *
     * @param IAppConfig         $appConfig    The app config interface
     * @param IConfig            $config       The per-user config interface
     * @param IAppManager        $appManager   The app manager
     * @param ContainerInterface $container    The container
     * @param IGroupManager      $groupManager The group manager
     * @param IUserSession       $userSession  The user session
     * @param LoggerInterface    $logger       The logger
     *
     * @return void
     */
    public function __construct(
        private IAppConfig $appConfig,
        private IConfig $config,
        private IAppManager $appManager,
        private ContainerInterface $container,
        private IGroupManager $groupManager,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Check whether OpenRegister is installed and available.
     *
     * @return bool
     */
    public function isOpenRegisterAvailable(): bool
    {
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
    public function getSettings(): array
    {
        $settings = [];
        foreach (self::CONFIG_KEYS as $key) {
            $settings[$key] = $this->appConfig->getValueString(Application::APP_ID, $key, '');
        }

        $user    = $this->userSession->getUser();
        $isAdmin = ($user !== null && $this->groupManager->isAdmin($user->getUID()));

        return array_merge(
            $settings,
            [
                'openregisters' => $this->isOpenRegisterAvailable(),
                'isAdmin'       => $isAdmin,
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
     * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-5
     */
    public function updateSettings(array $data): array
    {
        foreach (self::CONFIG_KEYS as $key) {
            if (isset($data[$key]) === true) {
                $this->appConfig->setValueString(Application::APP_ID, $key, (string) $data[$key]);
            }
        }

        return $this->getSettings();
    }//end updateSettings()

    /**
     * Read the administrator-configurable settings from IAppConfig.
     *
     * Returns the master-password policy (minimum length and zxcvbn
     * score) plus the default session timeout. All values fall back to
     * their security baselines when unset.
     *
     * @return array<string,mixed> The admin settings.
     *
     * @spec openspec/changes/implement-dashboard-settings/specs/admin-settings/spec.md
     */
    public function getAdminSettings(): array
    {
        return [
            'min_password_length'     => $this->appConfig->getValueInt(
                Application::APP_ID,
                'min_password_length',
                self::MIN_PASSWORD_LENGTH_FLOOR
            ),
            'min_password_score'      => $this->appConfig->getValueInt(
                Application::APP_ID,
                'min_password_score',
                self::MIN_PASSWORD_SCORE_FLOOR
            ),
            'default_session_timeout' => $this->appConfig->getValueString(
                Application::APP_ID,
                'default_session_timeout',
                'session'
            ),
            'ca_auto_renew_enabled'   => $this->appConfig->getValueBool(
                Application::APP_ID,
                'ca_auto_renew_enabled',
                true
            ),
        ];
    }//end getAdminSettings()

    /**
     * Validate and persist administrator settings to IAppConfig.
     *
     * Enforces the hardcoded security floors: an admin may tighten the
     * master-password policy but never weaken it below the app baseline.
     * Out-of-bounds values raise an InvalidArgumentException and leave
     * the stored configuration unchanged.
     *
     * @param array<string,mixed> $data The submitted admin settings.
     *
     * @return array<string,mixed> The updated admin settings.
     *
     * @throws InvalidArgumentException When a value is out of bounds.
     *
     * @spec openspec/changes/implement-dashboard-settings/specs/admin-settings/spec.md
     */
    public function updateAdminSettings(array $data): array
    {
        if (isset($data['min_password_length']) === true) {
            $length = $this->validateBoundedInt(
                value: (int) $data['min_password_length'],
                floor: self::MIN_PASSWORD_LENGTH_FLOOR,
                ceiling: self::MIN_PASSWORD_LENGTH_CEILING,
                label: 'min_password_length'
            );
            $this->appConfig->setValueInt(Application::APP_ID, 'min_password_length', $length);
        }

        if (isset($data['min_password_score']) === true) {
            $score = $this->validateBoundedInt(
                value: (int) $data['min_password_score'],
                floor: self::MIN_PASSWORD_SCORE_FLOOR,
                ceiling: self::MIN_PASSWORD_SCORE_CEILING,
                label: 'min_password_score'
            );
            $this->appConfig->setValueInt(Application::APP_ID, 'min_password_score', $score);
        }

        if (isset($data['default_session_timeout']) === true) {
            $timeout = (string) $data['default_session_timeout'];
            if (in_array($timeout, self::SESSION_TIMEOUT_VALUES, true) === false) {
                throw new InvalidArgumentException(
                    'default_session_timeout must be one of: '.implode(', ', self::SESSION_TIMEOUT_VALUES)
                );
            }

            $this->appConfig->setValueString(Application::APP_ID, 'default_session_timeout', $timeout);
        }

        if (isset($data['ca_auto_renew_enabled']) === true) {
            $this->appConfig->setValueBool(
                Application::APP_ID,
                'ca_auto_renew_enabled',
                filter_var($data['ca_auto_renew_enabled'], FILTER_VALIDATE_BOOLEAN)
            );
        }

        return $this->getAdminSettings();
    }//end updateAdminSettings()

    /**
     * Validate that an integer falls within an inclusive bounded range.
     *
     * @param int    $value   The value to validate.
     * @param int    $floor   The inclusive lower bound.
     * @param int    $ceiling The inclusive upper bound.
     * @param string $label   The setting name, used in the error message.
     *
     * @return int The validated value (unchanged).
     *
     * @throws InvalidArgumentException When the value is out of range.
     *
     * @spec openspec/changes/implement-dashboard-settings/specs/admin-settings/spec.md
     */
    private function validateBoundedInt(int $value, int $floor, int $ceiling, string $label): int
    {
        if ($value < $floor || $value > $ceiling) {
            throw new InvalidArgumentException(
                $label.' must be between '.$floor.' and '.$ceiling
            );
        }

        return $value;
    }//end validateBoundedInt()

    /**
     * Read the per-user preferences for the given user from IConfig.
     *
     * Booleans are returned as native bool. The `session_timeout`
     * preference falls back to the admin-configured default when the
     * user has not set one (per the spec's default-resolution rule).
     *
     * @param string $userId The user identifier.
     *
     * @return array<string,mixed> The user's preferences.
     *
     * @spec openspec/changes/implement-dashboard-settings/specs/user-settings/spec.md
     */
    public function getUserPreferences(string $userId): array
    {
        $adminDefaultTimeout = $this->appConfig->getValueString(
            Application::APP_ID,
            'default_session_timeout',
            'session'
        );

        $preferences = [];
        foreach (self::USER_PREF_KEYS as $key => $default) {
            $fallback = $default;
            if ($key === 'session_timeout' && $fallback === '') {
                $fallback = $adminDefaultTimeout;
            }

            $stored = $this->config->getUserValue(
                $userId,
                Application::APP_ID,
                $key,
                $fallback
            );

            $preferences[$key] = $stored;
            if (str_starts_with($key, 'notify_') === true) {
                $preferences[$key] = ($stored === '1');
            }
        }

        return $preferences;
    }//end getUserPreferences()

    /**
     * Whitelist-filter and persist per-user preferences via IConfig.
     *
     * Only keys present in USER_PREF_KEYS are written; any other key in
     * the payload is silently ignored to prevent arbitrary key
     * injection. Boolean toggles are normalised to the '1' / '0' string
     * form Nextcloud expects.
     *
     * @param string              $userId The user identifier.
     * @param array<string,mixed> $data   The submitted preferences.
     *
     * @return array<string,mixed> The updated preferences.
     *
     * @spec openspec/changes/implement-dashboard-settings/specs/user-settings/spec.md
     */
    public function updateUserPreferences(string $userId, array $data): array
    {
        foreach ($data as $key => $value) {
            if (array_key_exists($key, self::USER_PREF_KEYS) === false) {
                continue;
            }

            $stored = (string) $value;
            if (str_starts_with($key, 'notify_') === true) {
                $stored = '0';
                if (filter_var($value, FILTER_VALIDATE_BOOLEAN) === true) {
                    $stored = '1';
                }
            }

            $this->config->setUserValue($userId, Application::APP_ID, $key, $stored);
        }

        return $this->getUserPreferences(userId: $userId);
    }//end updateUserPreferences()

    /**
     * Load configuration from doriath_register.json via OpenRegister.
     *
     * Reads the bundled register configuration file from
     * `lib/Settings/doriath_register.json`, parses it, and passes it
     * to OpenRegister's `ConfigurationService::importFromApp()` using
     * the ADR-022 4-arg signature (appId, data, version, force).
     * Mirrors the scholiq / procest / decidesk pattern.
     *
     * @param bool $force Force re-import even if already configured.
     *
     * @return array<string,mixed> Result with success flag, message, and version.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     *
     * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-6
     */
    public function loadConfiguration(bool $force=false): array
    {
        if ($this->isOpenRegisterAvailable() === false) {
            $this->logger->warning('Doriath: OpenRegister not available, skipping register initialization');
            return [
                'success' => false,
                'message' => 'OpenRegister is not installed or enabled.',
            ];
        }

        try {
            $configPath = __DIR__.'/../Settings/doriath_register.json';
            if (file_exists($configPath) === false) {
                $this->logger->error('Doriath: doriath_register.json not found at '.$configPath);
                return [
                    'success' => false,
                    'message' => 'Configuration file doriath_register.json not found.',
                ];
            }

            $configContent = file_get_contents($configPath);
            if ($configContent === false) {
                $this->logger->error('Doriath: failed to read doriath_register.json');
                return [
                    'success' => false,
                    'message' => 'Failed to read configuration file.',
                ];
            }

            $configData = json_decode($configContent, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->error('Doriath: failed to parse doriath_register.json: '.json_last_error_msg());
                return [
                    'success' => false,
                    'message' => 'Failed to parse configuration file: '.json_last_error_msg(),
                ];
            }

            $configVersion = ($configData['info']['version'] ?? '0.0.0');

            $configurationService = $this->container->get('OCA\OpenRegister\Service\ConfigurationService');
            $result = $configurationService->importFromApp(
                appId: Application::APP_ID,
                data: $configData,
                version: $configVersion,
                force: $force
            );

            if (empty($result) === false) {
                $this->logger->info('Doriath: register configuration imported successfully');
                return [
                    'success' => true,
                    'message' => 'Configuration imported successfully.',
                    'version' => ($result['version'] ?? 'unknown'),
                ];
            }

            return [
                'success' => false,
                'message' => 'Import returned an empty result.',
            ];
        } catch (Throwable $e) {
            $this->logger->error(
                'Doriath: configuration import failed',
                ['exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }//end try
    }//end loadConfiguration()
}//end class
