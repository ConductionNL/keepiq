<?php

/**
 * Doriath Admin Settings Service
 *
 * The instance-wide (admin-scoped) configuration surface: the whole
 * `getAdminSettings()` payload, the validated writes behind
 * `updateAdminSettings()`, and the two admin-only reads that hang off it —
 * the org password policy (PasswordPolicyService) and the OpenRegister
 * register import (RegisterConfigurationLoader).
 *
 * Extracted from SettingsService, which keeps the per-user preferences and
 * the small CONFIG_KEYS surface every authenticated user may read.
 *
 * Each `update*Settings()` group validates and persists one family of keys.
 * Every guard is independent — an absent key is left untouched, an
 * out-of-bounds value throws before anything in its group is written.
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
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads and validates the instance-wide Doriath configuration.
 */
class AdminSettingsService
{
    /**
     * Admin default bounds for validation.
     */
    private const MIN_PASSWORD_LENGTH_MIN = 12;
    private const MIN_PASSWORD_LENGTH_MAX = 20;
    private const VALID_PASSWORD_SCORES   = [3, 4];
    private const VALID_SESSION_TIMEOUTS  = ['session', '10min', '30min'];

    /**
     * Default audit-log retention window in days (add-secret-audit-trail §4.2).
     *
     * @var int
     */
    public const AUDIT_RETENTION_DEFAULT = 365;

    /**
     * Hard minimum audit-log retention window — below this the trail cannot
     * serve its incident-investigation purpose, so it is rejected (design D5).
     *
     * @var int
     */
    public const AUDIT_RETENTION_MIN = 30;

    /**
     * The org password policy.
     *
     * @var PasswordPolicyService
     */
    private PasswordPolicyService $policyService;

    /**
     * The OpenRegister register-configuration loader.
     *
     * @var RegisterConfigurationLoader
     */
    private RegisterConfigurationLoader $registerLoader;

    /**
     * Constructor for the AdminSettingsService.
     *
     * @param IAppConfig                       $appConfig       The app config interface
     * @param IAppManager                      $appManager      The app manager
     * @param ContainerInterface               $container       The container
     * @param IUserSession                     $userSession     The user session (audit actor)
     * @param LoggerInterface                  $logger          The logger
     * @param IEventDispatcher|null            $eventDispatcher The audit dispatcher (policy changes)
     * @param PasswordPolicyService|null       $policyService   The org password policy
     * @param RegisterConfigurationLoader|null $registerLoader  The register-configuration loader
     *
     * @return void
     *
     * @spec exclude Constructor wiring only; the settings rules carry the spec anchors.
     */
    public function __construct(
        private IAppConfig $appConfig,
        IAppManager $appManager,
        private ContainerInterface $container,
        IUserSession $userSession,
        private LoggerInterface $logger,
        ?IEventDispatcher $eventDispatcher=null,
        ?PasswordPolicyService $policyService=null,
        ?RegisterConfigurationLoader $registerLoader=null,
    ) {
        $this->policyService = ($policyService ?? new PasswordPolicyService(
            appConfig: $appConfig,
            userSession: $userSession,
            eventDispatcher: $eventDispatcher,
        ));

        $this->registerLoader = ($registerLoader ?? new RegisterConfigurationLoader(
            appManager: $appManager,
            container: $container,
            logger: $logger,
        ));
    }//end __construct()

    /**
     * Get admin-scoped settings (implement-dashboard-settings §1.3).
     *
     * @return array<string,mixed>
     *
     * @spec openspec/changes/implement-dashboard-settings/tasks.md#task-1.3
     */
    public function getAdminSettings(): array
    {
        $appId    = Application::APP_ID;
        $settings = array_merge(
            [
                'min_password_length'         => $this->appConfig->getValueInt($appId, 'min_password_length', 12),
                'min_password_score'          => $this->appConfig->getValueInt($appId, 'min_password_score', 3),
                'default_session_timeout'     => $this->appConfig->getValueString(
                    $appId,
                    'default_session_timeout',
                    'session'
                ),
                'ca_auto_renew_enabled'       => $this->appConfig->getValueBool($appId, 'ca_auto_renew_enabled', true),
                'audit_retention_days'        => $this->appConfig->getValueInt(
                    $appId,
                    'audit_retention_days',
                    self::AUDIT_RETENTION_DEFAULT
                ),
                'breach_check_enabled'        => $this->appConfig->getValueBool($appId, 'breach_check_enabled', false),
                'expiry_default_max_age_days' => $this->appConfig->getValueInt(
                    $appId,
                    'expiry_default_max_age_days',
                    0
                ),
                'expiry_reminder_days'        => json_decode(
                    $this->appConfig->getValueString($appId, 'expiry_reminder_days', '[30,7,1]'),
                    true
                ),
                'expiry_policy_enforced'      => $this->appConfig->getValueBool(
                    $appId,
                    'expiry_policy_enforced',
                    false
                ),
                'version_retention_count'     => $this->appConfig->getValueInt($appId, 'version_retention_count', 20),
                'version_retention_days'      => $this->appConfig->getValueInt($appId, 'version_retention_days', 365),
                'attachment_max_bytes'        => $this->appConfig->getValueInt(
                    $appId,
                    'attachment_max_bytes',
                    26214400
                ),
                'attachment_user_quota_bytes' => $this->appConfig->getValueInt(
                    $appId,
                    'attachment_user_quota_bytes',
                    104857600
                ),
            ],
            // Org password policy (org-password-policies §1.1) — one reader,
            // shared with the user-visible getPolicy() floor.
            $this->policyService->readPolicyKeys(),
            [
                // Machine leases (machine-secret-leases §2.4).
                'lease_default_ttl_seconds'       => $this->appConfig->getValueInt(
                    $appId,
                    'lease_default_ttl_seconds',
                    900
                ),
                'lease_max_ttl_seconds'           => $this->appConfig->getValueInt(
                    $appId,
                    'lease_max_ttl_seconds',
                    86400
                ),
                'lease_renewable'                 => $this->appConfig->getValueBool($appId, 'lease_renewable', true),
                'lease_revocation_blocks_refetch' => $this->appConfig->getValueBool(
                    $appId,
                    'lease_revocation_blocks_refetch',
                    false
                ),
                // Offline read-only cache (offline-readonly-cache §1.1) — default on.
                'offline_cache_enabled'           => $this->appConfig->getValueBool(
                    $appId,
                    'offline_cache_enabled',
                    true
                ),
            ]
        );

        // Best-effort CA status; never blocks if the service is unavailable.
        try {
            $caService = $this->container->get('OCA\Doriath\Service\CertificateAuthorityService');
            if (method_exists($caService, 'getStatus') === true) {
                $settings['ca_status'] = $caService->getStatus();
            }
        } catch (Throwable $e) {
            $this->logger->debug('Doriath: CA status unavailable: '.$e->getMessage());
            $settings['ca_status'] = ['status' => 'unknown'];
        }

        return $settings;
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
    public function updateAdminSettings(array $data): array
    {
        // Each group validates and persists one family of keys. Every guard
        // is independent — an absent key is left untouched, an out-of-bounds
        // value throws before anything in its group is written.
        $this->updateAuthenticationSettings(data: $data);
        $this->updateInstanceSettings(data: $data);
        $this->policyService->updatePolicySettings(data: $data);
        $this->updateExpirySettings(data: $data);
        $this->updateLeaseSettings(data: $data);
        $this->updateRetentionSettings(data: $data);

        return $this->getAdminSettings();
    }//end updateAdminSettings()

    /**
     * The user-visible policy floor for the write dialogs
     * (org-password-policies §1.3).
     *
     * @return array<string,mixed>
     *
     * @spec openspec/changes/org-password-policies/specs/org-password-policies/spec.md
     */
    public function getPolicy(): array
    {
        return $this->policyService->getPolicy();
    }//end getPolicy()

    /**
     * Load configuration from doriath_register.json via OpenRegister.
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
    public function loadConfiguration(bool $force=false): array
    {
        return $this->registerLoader->loadConfiguration(force: $force);
    }//end loadConfiguration()

    /**
     * Password-strength and session keys (implement-dashboard-settings §1.4).
     *
     * @param array<string,mixed> $data The admin-settings input
     *
     * @return void
     *
     * @throws InvalidArgumentException On out-of-bounds values.
     */
    private function updateAuthenticationSettings(array $data): void
    {
        $appId = Application::APP_ID;

        if (isset($data['min_password_length']) === true) {
            $length = (int) $data['min_password_length'];
            if ($length < self::MIN_PASSWORD_LENGTH_MIN || $length > self::MIN_PASSWORD_LENGTH_MAX) {
                throw new InvalidArgumentException(
                    'min_password_length must be between '.self::MIN_PASSWORD_LENGTH_MIN
                    .' and '.self::MIN_PASSWORD_LENGTH_MAX
                );
            }

            $this->appConfig->setValueInt($appId, 'min_password_length', $length);
        }

        if (isset($data['min_password_score']) === true) {
            $score = (int) $data['min_password_score'];
            if (in_array($score, self::VALID_PASSWORD_SCORES, true) === false) {
                throw new InvalidArgumentException('min_password_score must be 3 or 4');
            }

            $this->appConfig->setValueInt($appId, 'min_password_score', $score);
        }

        if (isset($data['default_session_timeout']) === true) {
            $timeout = (string) $data['default_session_timeout'];
            if (in_array($timeout, self::VALID_SESSION_TIMEOUTS, true) === false) {
                throw new InvalidArgumentException(
                    'default_session_timeout must be one of: '.implode(', ', self::VALID_SESSION_TIMEOUTS)
                );
            }

            $this->appConfig->setValueString($appId, 'default_session_timeout', $timeout);
        }
    }//end updateAuthenticationSettings()

    /**
     * Instance-wide switches: CA renewal, audit retention, breach checking
     * and the offline read-only cache (offline-readonly-cache §1.1).
     *
     * @param array<string,mixed> $data The admin-settings input
     *
     * @return void
     *
     * @throws InvalidArgumentException On out-of-bounds values.
     */
    private function updateInstanceSettings(array $data): void
    {
        $appId = Application::APP_ID;

        if (isset($data['ca_auto_renew_enabled']) === true) {
            $this->appConfig->setValueBool($appId, 'ca_auto_renew_enabled', (bool) $data['ca_auto_renew_enabled']);
        }

        if (isset($data['audit_retention_days']) === true) {
            $days = (int) $data['audit_retention_days'];
            if ($days < self::AUDIT_RETENTION_MIN) {
                throw new InvalidArgumentException(
                    'audit_retention_days must be at least '.self::AUDIT_RETENTION_MIN
                    .' days — below that the audit trail cannot serve incident investigation'
                );
            }

            $this->appConfig->setValueInt($appId, 'audit_retention_days', $days);
        }

        if (isset($data['breach_check_enabled']) === true) {
            $this->appConfig->setValueBool($appId, 'breach_check_enabled', (bool) $data['breach_check_enabled']);
        }

        if (isset($data['offline_cache_enabled']) === true) {
            $this->appConfig->setValueBool($appId, 'offline_cache_enabled', (bool) $data['offline_cache_enabled']);
        }
    }//end updateInstanceSettings()

    /**
     * Expiry defaults (rotation-expiry-policies §2.2): admin max age ships
     * OFF (0); reminder thresholds validated as positive ints.
     *
     * @param array<string,mixed> $data The admin-settings input
     *
     * @return void
     *
     * @throws InvalidArgumentException On out-of-bounds values.
     */
    private function updateExpirySettings(array $data): void
    {
        $appId = Application::APP_ID;

        if (isset($data['expiry_default_max_age_days']) === true) {
            $maxAge = (int) $data['expiry_default_max_age_days'];
            if ($maxAge < 0) {
                throw new InvalidArgumentException('expiry_default_max_age_days must be 0 (off) or positive');
            }

            $this->appConfig->setValueInt($appId, 'expiry_default_max_age_days', $maxAge);
        }

        if (isset($data['expiry_reminder_days']) === true) {
            $thresholds = array_values(
                array_filter(
                    array_map('intval', (array) $data['expiry_reminder_days']),
                    static fn (int $days): bool => $days > 0
                )
            );
            if ($thresholds === []) {
                throw new InvalidArgumentException('expiry_reminder_days needs at least one positive threshold');
            }

            $this->appConfig->setValueString($appId, 'expiry_reminder_days', (string) json_encode($thresholds));
        }

        if (isset($data['expiry_policy_enforced']) === true) {
            $this->appConfig->setValueBool($appId, 'expiry_policy_enforced', (bool) $data['expiry_policy_enforced']);
        }
    }//end updateExpirySettings()

    /**
     * Machine-lease policy (machine-secret-leases §2.4): a 60-second floor
     * keeps a lease meaningful; max must not undercut default.
     *
     * @param array<string,mixed> $data The admin-settings input
     *
     * @return void
     *
     * @throws InvalidArgumentException On out-of-bounds values.
     */
    private function updateLeaseSettings(array $data): void
    {
        $appId = Application::APP_ID;

        foreach (['lease_default_ttl_seconds', 'lease_max_ttl_seconds'] as $leaseKey) {
            if (isset($data[$leaseKey]) === true) {
                $ttl = (int) $data[$leaseKey];
                if ($ttl < 60) {
                    throw new InvalidArgumentException($leaseKey.' must be at least 60 seconds');
                }

                $this->appConfig->setValueInt($appId, $leaseKey, $ttl);
            }
        }

        if (isset($data['lease_renewable']) === true) {
            $this->appConfig->setValueBool($appId, 'lease_renewable', (bool) $data['lease_renewable']);
        }

        if (isset($data['lease_revocation_blocks_refetch']) === true) {
            $this->appConfig->setValueBool(
                $appId,
                'lease_revocation_blocks_refetch',
                (bool) $data['lease_revocation_blocks_refetch']
            );
        }
    }//end updateLeaseSettings()

    /**
     * Version retention (secret-version-history §4.1) and attachment limits
     * (encrypted-attachments §2.5). A floor of 1 kept version preserves
     * restorability; days 0 = unlimited age. Attachment limits are expressed
     * and enforced in stored CIPHERTEXT bytes — what actually consumes disk.
     *
     * @param array<string,mixed> $data The admin-settings input
     *
     * @return void
     *
     * @throws InvalidArgumentException On out-of-bounds values.
     */
    private function updateRetentionSettings(array $data): void
    {
        $appId = Application::APP_ID;

        if (isset($data['version_retention_count']) === true) {
            $count = (int) $data['version_retention_count'];
            if ($count < 1) {
                throw new InvalidArgumentException('version_retention_count must be at least 1');
            }

            $this->appConfig->setValueInt($appId, 'version_retention_count', $count);
        }

        if (isset($data['version_retention_days']) === true) {
            $days = (int) $data['version_retention_days'];
            if ($days < 0) {
                throw new InvalidArgumentException('version_retention_days must be 0 (unlimited) or positive');
            }

            $this->appConfig->setValueInt($appId, 'version_retention_days', $days);
        }

        if (isset($data['attachment_max_bytes']) === true) {
            $maxBytes = (int) $data['attachment_max_bytes'];
            if ($maxBytes < 1) {
                throw new InvalidArgumentException('attachment_max_bytes must be a positive byte count');
            }

            $this->appConfig->setValueInt($appId, 'attachment_max_bytes', $maxBytes);
        }

        if (isset($data['attachment_user_quota_bytes']) === true) {
            $quota = (int) $data['attachment_user_quota_bytes'];
            if ($quota < 1) {
                throw new InvalidArgumentException('attachment_user_quota_bytes must be a positive byte count');
            }

            $this->appConfig->setValueInt($appId, 'attachment_user_quota_bytes', $quota);
        }
    }//end updateRetentionSettings()
}//end class
