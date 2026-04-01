<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Doriath Settings Service
 *
 * Manages admin application configuration and per-user preferences for Doriath.
 *
 * @category Service
 * @package  OCA\AppTemplate\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\AppTemplate\Service;

use OCA\AppTemplate\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IConfig;

/**
 * Manages admin application configuration and per-user preferences.
 */
class SettingsService
{
    // -------------------------------------------------------------------------
    // Admin config keys
    // -------------------------------------------------------------------------

    /** Minimum master-password length (int, 12–20). */
    public const ADMIN_KEY_PASSWORD_MIN_LENGTH = 'password_min_length';

    /** Minimum master-password strength score (int, 3–4). */
    public const ADMIN_KEY_PASSWORD_MIN_SCORE = 'password_min_score';

    /** Status of the Certificate Authority bootstrap ('healthy'|'degraded'|'unknown'). */
    public const ADMIN_KEY_CA_STATUS = 'ca_status';

    // -------------------------------------------------------------------------
    // User preference keys
    // -------------------------------------------------------------------------

    /** Session timeout in minutes (int, 5–1440). */
    public const USER_KEY_SESSION_TIMEOUT = 'session_timeout';

    /** Notify user on incoming secret shares (bool string '1'|'0'). */
    public const USER_KEY_NOTIFY_SHARES = 'notify_shares';

    /** Notify user on incoming secret requests (bool string '1'|'0'). */
    public const USER_KEY_NOTIFY_REQUESTS = 'notify_requests';

    /** (V1) Notify user on group shares (bool string '1'|'0'). */
    public const USER_KEY_NOTIFY_GROUP_SHARES = 'notify_group_shares';

    /** (V1) Notify user on security events (bool string '1'|'0'). */
    public const USER_KEY_NOTIFY_SECURITY = 'notify_security';

    /** (V1) Default secret type ('password'|'note'|'card'). */
    public const USER_KEY_DEFAULT_TYPE = 'default_type';

    /** (V1) Default vault view ('list'|'grid'). */
    public const USER_KEY_DEFAULT_VIEW = 'default_view';

    // -------------------------------------------------------------------------
    // Defaults
    // -------------------------------------------------------------------------

    private const ADMIN_DEFAULTS = [
        self::ADMIN_KEY_PASSWORD_MIN_LENGTH => 12,
        self::ADMIN_KEY_PASSWORD_MIN_SCORE  => 3,
        self::ADMIN_KEY_CA_STATUS           => 'unknown',
    ];

    private const USER_DEFAULTS = [
        self::USER_KEY_SESSION_TIMEOUT      => 30,
        self::USER_KEY_NOTIFY_SHARES        => '1',
        self::USER_KEY_NOTIFY_REQUESTS      => '1',
        self::USER_KEY_NOTIFY_GROUP_SHARES  => '0',
        self::USER_KEY_NOTIFY_SECURITY      => '1',
        self::USER_KEY_DEFAULT_TYPE         => 'password',
        self::USER_KEY_DEFAULT_VIEW         => 'list',
    ];

    /**
     * Constructor.
     *
     * @param IAppConfig $appConfig The application config service (admin).
     * @param IConfig    $config    The per-user config service.
     *
     * @return void
     */
    public function __construct(
        private IAppConfig $appConfig,
        private IConfig $config,
    ) {
    }//end __construct()

    // =========================================================================
    // Admin settings (Tasks 1.3 & 1.4)
    // =========================================================================

    /**
     * Read admin settings from IAppConfig plus CA status.
     *
     * @return array{
     *     passwordMinLength: int,
     *     passwordMinScore: int,
     *     caStatus: string
     * }
     */
    public function getAdminSettings(): array
    {
        return [
            'passwordMinLength' => (int) $this->appConfig->getValueString(
                app: Application::APP_ID,
                key: self::ADMIN_KEY_PASSWORD_MIN_LENGTH,
                default: (string) self::ADMIN_DEFAULTS[self::ADMIN_KEY_PASSWORD_MIN_LENGTH]
            ),
            'passwordMinScore'  => (int) $this->appConfig->getValueString(
                app: Application::APP_ID,
                key: self::ADMIN_KEY_PASSWORD_MIN_SCORE,
                default: (string) self::ADMIN_DEFAULTS[self::ADMIN_KEY_PASSWORD_MIN_SCORE]
            ),
            'caStatus'          => $this->appConfig->getValueString(
                app: Application::APP_ID,
                key: self::ADMIN_KEY_CA_STATUS,
                default: self::ADMIN_DEFAULTS[self::ADMIN_KEY_CA_STATUS]
            ),
        ];
    }//end getAdminSettings()

    /**
     * Validate and persist admin settings to IAppConfig.
     *
     * @param array{
     *     passwordMinLength?: int,
     *     passwordMinScore?: int
     * } $data The settings data to save.
     *
     * @return array{passwordMinLength: int, passwordMinScore: int, caStatus: string}
     *
     * @throws \InvalidArgumentException When a value is outside the allowed range.
     */
    public function updateAdminSettings(array $data): array
    {
        if (isset($data['passwordMinLength']) === true) {
            $length = (int) $data['passwordMinLength'];
            if ($length < 12 || $length > 20) {
                throw new \InvalidArgumentException('passwordMinLength must be between 12 and 20');
            }
            $this->appConfig->setValueString(
                app: Application::APP_ID,
                key: self::ADMIN_KEY_PASSWORD_MIN_LENGTH,
                value: (string) $length
            );
        }

        if (isset($data['passwordMinScore']) === true) {
            $score = (int) $data['passwordMinScore'];
            if ($score < 3 || $score > 4) {
                throw new \InvalidArgumentException('passwordMinScore must be 3 or 4');
            }
            $this->appConfig->setValueString(
                app: Application::APP_ID,
                key: self::ADMIN_KEY_PASSWORD_MIN_SCORE,
                value: (string) $score
            );
        }

        return $this->getAdminSettings();
    }//end updateAdminSettings()

    // =========================================================================
    // User preferences (Tasks 1.5 & 1.6)
    // =========================================================================

    /**
     * Read user preferences from IConfig, falling back to defaults.
     *
     * @param string $userId The user identifier.
     *
     * @return array{
     *     sessionTimeout: int,
     *     notifyShares: bool,
     *     notifyRequests: bool,
     *     notifyGroupShares: bool,
     *     notifySecurity: bool,
     *     defaultType: string,
     *     defaultView: string
     * }
     */
    public function getUserPreferences(string $userId): array
    {
        return [
            'sessionTimeout'    => (int) $this->config->getUserValue(
                userId: $userId,
                appName: Application::APP_ID,
                key: self::USER_KEY_SESSION_TIMEOUT,
                default: (string) self::USER_DEFAULTS[self::USER_KEY_SESSION_TIMEOUT]
            ),
            'notifyShares'      => $this->config->getUserValue(
                userId: $userId,
                appName: Application::APP_ID,
                key: self::USER_KEY_NOTIFY_SHARES,
                default: self::USER_DEFAULTS[self::USER_KEY_NOTIFY_SHARES]
            ) === '1',
            'notifyRequests'    => $this->config->getUserValue(
                userId: $userId,
                appName: Application::APP_ID,
                key: self::USER_KEY_NOTIFY_REQUESTS,
                default: self::USER_DEFAULTS[self::USER_KEY_NOTIFY_REQUESTS]
            ) === '1',
            'notifyGroupShares' => $this->config->getUserValue(
                userId: $userId,
                appName: Application::APP_ID,
                key: self::USER_KEY_NOTIFY_GROUP_SHARES,
                default: self::USER_DEFAULTS[self::USER_KEY_NOTIFY_GROUP_SHARES]
            ) === '1',
            'notifySecurity'    => $this->config->getUserValue(
                userId: $userId,
                appName: Application::APP_ID,
                key: self::USER_KEY_NOTIFY_SECURITY,
                default: self::USER_DEFAULTS[self::USER_KEY_NOTIFY_SECURITY]
            ) === '1',
            'defaultType'       => $this->config->getUserValue(
                userId: $userId,
                appName: Application::APP_ID,
                key: self::USER_KEY_DEFAULT_TYPE,
                default: self::USER_DEFAULTS[self::USER_KEY_DEFAULT_TYPE]
            ),
            'defaultView'       => $this->config->getUserValue(
                userId: $userId,
                appName: Application::APP_ID,
                key: self::USER_KEY_DEFAULT_VIEW,
                default: self::USER_DEFAULTS[self::USER_KEY_DEFAULT_VIEW]
            ),
        ];
    }//end getUserPreferences()

    /**
     * Whitelist-filtered write of user preferences to IConfig.
     *
     * Only keys present in the whitelist will be persisted; unknown keys are
     * silently ignored.
     *
     * @param string $userId The user identifier.
     * @param array  $data   Preference key-value pairs to persist.
     *
     * @return array The updated preferences.
     */
    public function updateUserPreferences(string $userId, array $data): array
    {
        $allowedKeys = [
            'sessionTimeout'    => self::USER_KEY_SESSION_TIMEOUT,
            'notifyShares'      => self::USER_KEY_NOTIFY_SHARES,
            'notifyRequests'    => self::USER_KEY_NOTIFY_REQUESTS,
            'notifyGroupShares' => self::USER_KEY_NOTIFY_GROUP_SHARES,
            'notifySecurity'    => self::USER_KEY_NOTIFY_SECURITY,
            // V1: defaultType and defaultView are read-only stubs for now.
        ];

        foreach ($allowedKeys as $field => $configKey) {
            if (isset($data[$field]) === false) {
                continue;
            }

            $value = match ($field) {
                'sessionTimeout'    => (string) max(5, min(1440, (int) $data[$field])),
                default             => $data[$field] ? '1' : '0',
            };

            $this->config->setUserValue(
                userId: $userId,
                appName: Application::APP_ID,
                key: $configKey,
                value: $value
            );
        }

        return $this->getUserPreferences($userId);
    }//end updateUserPreferences()
}//end class
