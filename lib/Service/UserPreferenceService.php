<?php

/**
 * Doriath User Preference Service
 *
 * Reads and writes per-user Doriath preferences (session timeout, notification
 * toggles, default secret type / view) via OCP\IConfig, behind a whitelist so
 * arbitrary keys cannot be injected. Split out of SettingsService to keep that
 * class focused on app-level configuration (ADR-008).
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

use OCA\Doriath\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IConfig;

/**
 * Per-user preference storage for Doriath.
 */
class UserPreferenceService
{

    /**
     * Per-user preference keys whitelisted for storage via IConfig (design.md D5).
     *
     * @var array<string>
     */
    private const USER_PREF_KEYS = [
        'session_timeout',
        'notify_shares',
        'notify_requests',
        'notify_group_shares',
        'notify_security',
        'default_secret_type',
        'default_view',
    ];

    /**
     * Default values for each per-user preference key.
     *
     * @var array<string,string>
     */
    private const USER_PREF_DEFAULTS = [
        'notify_shares'       => '1',
        'notify_requests'     => '1',
        'notify_group_shares' => '1',
        'notify_security'     => '1',
        'default_secret_type' => 'login',
        'default_view'        => 'list',
    ];

    /**
     * Boolean-typed per-user preference keys (stored as '1' / '0' strings).
     *
     * @var array<string>
     */
    private const USER_PREF_BOOL_KEYS = [
        'notify_shares',
        'notify_requests',
        'notify_group_shares',
        'notify_security',
    ];

    /**
     * Constructor.
     *
     * @param IConfig    $config    The per-user config interface.
     * @param IAppConfig $appConfig The app config interface (for the timeout default).
     *
     * @return void
     */
    public function __construct(
        private IConfig $config,
        private IAppConfig $appConfig,
    ) {
    }//end __construct()

    /**
     * Retrieve the per-user preferences for the given user.
     *
     * Booleans are normalised to PHP bools, the session_timeout falls back to the
     * admin-configured default when the user has not chosen one, and the remaining
     * keys fall back to their hardcoded defaults.
     *
     * @param string $userId The user identifier.
     *
     * @return array<string,mixed> The resolved user preferences.
     *
     * @spec openspec/changes/implement-dashboard-settings/tasks.md#task-1.5
     */
    public function getUserPreferences(string $userId): array
    {
        $adminDefaultTimeout = $this->appConfig->getValueString(
            Application::APP_ID,
            'default_session_timeout',
            'session'
        );

        $preferences = [
            'session_timeout' => $this->config->getUserValue(
                $userId,
                Application::APP_ID,
                'session_timeout',
                $adminDefaultTimeout
            ),
        ];

        foreach (self::USER_PREF_KEYS as $key) {
            if ($key === 'session_timeout') {
                continue;
            }

            $stored = $this->config->getUserValue(
                $userId,
                Application::APP_ID,
                $key,
                self::USER_PREF_DEFAULTS[$key]
            );

            $preferences[$key] = $stored;
            if (in_array($key, self::USER_PREF_BOOL_KEYS, true) === true) {
                $preferences[$key] = ($stored === '1');
            }
        }

        return $preferences;
    }//end getUserPreferences()

    /**
     * Whitelist-filter and persist per-user preferences.
     *
     * Only keys in {@see self::USER_PREF_KEYS} are stored; any other key is
     * silently ignored to prevent arbitrary preference injection. Boolean toggles
     * are coerced to '1' / '0' strings (IConfig stores strings).
     *
     * @param string              $userId The user identifier.
     * @param array<string,mixed> $data   The submitted preferences.
     *
     * @return array<string,mixed> The updated, resolved user preferences.
     *
     * @spec openspec/changes/implement-dashboard-settings/tasks.md#task-1.6
     */
    public function updateUserPreferences(string $userId, array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array($key, self::USER_PREF_KEYS, true) === false) {
                continue;
            }

            $stored = (string) $value;
            if (in_array($key, self::USER_PREF_BOOL_KEYS, true) === true) {
                $stored = '0';
                if (filter_var($value, FILTER_VALIDATE_BOOLEAN) === true) {
                    $stored = '1';
                }
            }

            $this->config->setUserValue($userId, Application::APP_ID, $key, $stored);
        }

        return $this->getUserPreferences(userId: $userId);
    }//end updateUserPreferences()
}//end class
