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
use OCA\Doriath\Event\Audit\AuditEventFactory;
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
     * User-scoped preference keys (implement-dashboard-settings §1.2).
     *
     * @var array<string,string> key => default-value
     */
    private const USER_PREF_KEYS = [
        'session_timeout'       => '',
        'notify_shares'         => '1',
        'notify_requests'       => '1',
        'notify_group_shares'   => '1',
        'notify_security'       => '1',
        'default_secret_type'   => 'login',
        'default_view'          => 'list',
        // Password-health staleness threshold in days: '90' | '180' | '365' |
        // 'never' (password-health §1.6, default 365). Per-user opt-in for breach
        // checking; UI is shown only when the admin gate is also on.
        'health_staleness_days' => '365',
        'breach_check_opt_in'   => '0',
        // Per-user max credential age in days (rotation-expiry-policies
        // §2.2); '0' = off. Feeds effective-expiry resolution.
        'expiry_max_age_days'   => '0',
        // Offline read-only cache per-device opt-out (offline-readonly-cache
        // §1.2); default on, gated behind the admin org-wide switch.
        'offline_cache_optin'   => '1',
    ];

    /**
     * Permitted password-health staleness threshold values (password-health §1.6).
     *
     * @var string[]
     */
    private const VALID_STALENESS_DAYS = ['90', '180', '365', 'never'];

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
     * Constructor for the SettingsService.
     *
     * @param IAppConfig                                 $appConfig       The app config interface
     * @param IConfig                                    $config          The per-user config interface
     * @param IAppManager                                $appManager      The app manager
     * @param ContainerInterface                         $container       The container
     * @param IGroupManager                              $groupManager    The group manager
     * @param IUserSession                               $userSession     The user session
     * @param LoggerInterface                            $logger          The logger
     * @param \OCP\EventDispatcher\IEventDispatcher|null $eventDispatcher The audit dispatcher (policy changes)
     * @param AuditEventFactory                          $auditEvents     The audit-event factory
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
        private ?\OCP\EventDispatcher\IEventDispatcher $eventDispatcher=null,
        private AuditEventFactory $auditEvents=new AuditEventFactory(),
    ) {
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
        $settings = [
            'min_password_length'             => $this->appConfig->getValueInt($appId, 'min_password_length', 12),
            'min_password_score'              => $this->appConfig->getValueInt($appId, 'min_password_score', 3),
            'default_session_timeout'         => $this->appConfig->getValueString($appId, 'default_session_timeout', 'session'),
            'ca_auto_renew_enabled'           => $this->appConfig->getValueBool($appId, 'ca_auto_renew_enabled', true),
            'audit_retention_days'            => $this->appConfig->getValueInt(
                $appId,
                'audit_retention_days',
                self::AUDIT_RETENTION_DEFAULT
            ),
            'breach_check_enabled'            => $this->appConfig->getValueBool($appId, 'breach_check_enabled', false),
            'expiry_default_max_age_days'     => $this->appConfig->getValueInt($appId, 'expiry_default_max_age_days', 0),
            'expiry_reminder_days'            => json_decode(
                $this->appConfig->getValueString($appId, 'expiry_reminder_days', '[30,7,1]'),
                true
            ),
            'expiry_policy_enforced'          => $this->appConfig->getValueBool($appId, 'expiry_policy_enforced', false),
            'version_retention_count'         => $this->appConfig->getValueInt($appId, 'version_retention_count', 20),
            'version_retention_days'          => $this->appConfig->getValueInt($appId, 'version_retention_days', 365),
            'attachment_max_bytes'            => $this->appConfig->getValueInt($appId, 'attachment_max_bytes', 26214400),
            'attachment_user_quota_bytes'     => $this->appConfig->getValueInt(
                $appId,
                'attachment_user_quota_bytes',
                104857600
            ),
            // Org password policy (org-password-policies §1.1).
            'policy_enabled'                  => $this->appConfig->getValueBool($appId, 'policy_enabled', false),
            'generator_min_length'            => $this->appConfig->getValueInt($appId, 'generator_min_length', 12),
            'generator_require_upper'         => $this->appConfig->getValueBool($appId, 'generator_require_upper', false),
            'generator_require_lower'         => $this->appConfig->getValueBool($appId, 'generator_require_lower', false),
            'generator_require_digit'         => $this->appConfig->getValueBool($appId, 'generator_require_digit', false),
            'generator_require_symbol'        => $this->appConfig->getValueBool($appId, 'generator_require_symbol', false),
            'min_zxcvbn_score'                => $this->appConfig->getValueInt($appId, 'min_zxcvbn_score', 0),
            'block_on_hibp_hit'               => $this->appConfig->getValueBool($appId, 'block_on_hibp_hit', false),
            'policy_exempt_types'             => json_decode(
                $this->appConfig->getValueString($appId, 'policy_exempt_types', '["note","ssh_key","certificate","passkey","card","identity"]'),
                true
            ),
            // Machine leases (machine-secret-leases §2.4).
            'lease_default_ttl_seconds'       => $this->appConfig->getValueInt($appId, 'lease_default_ttl_seconds', 900),
            'lease_max_ttl_seconds'           => $this->appConfig->getValueInt($appId, 'lease_max_ttl_seconds', 86400),
            'lease_renewable'                 => $this->appConfig->getValueBool($appId, 'lease_renewable', true),
            'lease_revocation_blocks_refetch' => $this->appConfig->getValueBool(
                $appId,
                'lease_revocation_blocks_refetch',
                false
            ),
            // Offline read-only cache (offline-readonly-cache §1.1) — default on.
            'offline_cache_enabled'           => $this->appConfig->getValueBool($appId, 'offline_cache_enabled', true),
        ];

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

        // Offline read-only cache org-wide switch (offline-readonly-cache §1.1).
        if (isset($data['offline_cache_enabled']) === true) {
            $this->appConfig->setValueBool($appId, 'offline_cache_enabled', (bool) $data['offline_cache_enabled']);
        }

        $this->updatePolicySettings(data: $data);

        // Expiry defaults (rotation-expiry-policies §2.2): admin max age
        // ships OFF (0); reminder thresholds validated as positive ints.
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

        // Machine-lease policy (machine-secret-leases §2.4): a 60-second
        // floor keeps a lease meaningful; max must not undercut default.
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

        // Version retention (secret-version-history §4.1): a floor of 1
        // kept version preserves restorability; days 0 = unlimited age.
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

        // Attachment limits (encrypted-attachments §2.5): expressed and
        // enforced in stored CIPHERTEXT bytes — what actually consumes disk.
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

        return $this->getAdminSettings();
    }//end updateAdminSettings()

    /**
     * Validate + persist the org password-policy keys and dispatch the
     * `password_policy.updated` audit event with before/after values —
     * never any secret data (org-password-policies §1.1/§3.1).
     *
     * @param array<string,mixed> $data The admin-settings input
     *
     * @return void
     *
     * @throws InvalidArgumentException On invalid policy values
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) One flat guard per key.
     * @SuppressWarnings(PHPMD.NPathComplexity)      Same: independent guards.
     *
     * @spec openspec/changes/org-password-policies/specs/org-password-policies/spec.md#requirement-policy-storage-and-validation
     */
    private function updatePolicySettings(array $data): void
    {
        $appId      = Application::APP_ID;
        $policyKeys = [
            'policy_enabled',
            'generator_min_length',
            'generator_require_upper',
            'generator_require_lower',
            'generator_require_digit',
            'generator_require_symbol',
            'min_zxcvbn_score',
            'block_on_hibp_hit',
            'policy_exempt_types',
        ];
        $touched    = array_values(array_intersect($policyKeys, array_keys($data)));
        if ($touched === []) {
            return;
        }

        $before = array_intersect_key($this->getAdminSettings(), array_flip($touched));

        if (isset($data['generator_min_length']) === true) {
            $minLength = (int) $data['generator_min_length'];
            if ($minLength < 8) {
                throw new InvalidArgumentException('generator_min_length must be at least 8');
            }

            $this->appConfig->setValueInt($appId, 'generator_min_length', $minLength);
        }

        if (isset($data['min_zxcvbn_score']) === true) {
            $score = (int) $data['min_zxcvbn_score'];
            if ($score < 0 || $score > 4) {
                throw new InvalidArgumentException('min_zxcvbn_score must be between 0 and 4');
            }

            $this->appConfig->setValueInt($appId, 'min_zxcvbn_score', $score);
        }

        if (isset($data['block_on_hibp_hit']) === true) {
            $block = (bool) $data['block_on_hibp_hit'];
            $gate  = $this->appConfig->getValueBool($appId, 'breach_check_enabled', false);
            if ($block === true && $gate === false) {
                throw new InvalidArgumentException(
                    'block_on_hibp_hit requires breach_check_enabled — enable the breach check gate first'
                );
            }

            $this->appConfig->setValueBool($appId, 'block_on_hibp_hit', $block);
        }

        if (isset($data['policy_exempt_types']) === true) {
            $types = array_values(array_filter(array_map('strval', (array) $data['policy_exempt_types'])));
            $this->appConfig->setValueString($appId, 'policy_exempt_types', (string) json_encode($types));
        }

        $boolKeys = [
            'policy_enabled',
            'generator_require_upper',
            'generator_require_lower',
            'generator_require_digit',
            'generator_require_symbol',
        ];
        foreach ($boolKeys as $boolKey) {
            if (isset($data[$boolKey]) === true) {
                $this->appConfig->setValueBool($appId, $boolKey, (bool) $data[$boolKey]);
            }
        }

        $after = array_intersect_key($this->getAdminSettings(), array_flip($touched));

        $actorId = $this->userSession->getUser()?->getUID() ?? 'system';
        $this->eventDispatcher?->dispatchTyped(
            $this->auditEvents->forUser(
                actorId: $actorId,
                eventType: \OCA\Doriath\Event\Audit\AuditEventTypes::PASSWORD_POLICY_UPDATED,
                objectType: 'settings',
                objectId: 'password_policy',
                objectName: '',
                metadata: [
                    'before' => $before,
                    'after'  => $after,
                ],
            )
        );
    }//end updatePolicySettings()

    /**
     * The user-visible policy floor for the write dialogs — policy gate,
     * generator floor, score floor, HIBP block, and exempt types only
     * (org-password-policies §1.3).
     *
     * @return array<string,mixed>
     *
     * @spec openspec/changes/org-password-policies/specs/org-password-policies/spec.md#requirement-policy-storage-and-validation
     */
    public function getPolicy(): array
    {
        $appId = Application::APP_ID;

        return [
            'policy_enabled'           => $this->appConfig->getValueBool($appId, 'policy_enabled', false),
            'generator_min_length'     => $this->appConfig->getValueInt($appId, 'generator_min_length', 12),
            'generator_require_upper'  => $this->appConfig->getValueBool($appId, 'generator_require_upper', false),
            'generator_require_lower'  => $this->appConfig->getValueBool($appId, 'generator_require_lower', false),
            'generator_require_digit'  => $this->appConfig->getValueBool($appId, 'generator_require_digit', false),
            'generator_require_symbol' => $this->appConfig->getValueBool($appId, 'generator_require_symbol', false),
            'min_zxcvbn_score'         => $this->appConfig->getValueInt($appId, 'min_zxcvbn_score', 0),
            'block_on_hibp_hit'        => $this->appConfig->getValueBool($appId, 'block_on_hibp_hit', false),
            'policy_exempt_types'      => json_decode(
                $this->appConfig->getValueString($appId, 'policy_exempt_types', '["note","ssh_key","certificate","passkey","card","identity"]'),
                true
            ),
        ];
    }//end getPolicy()

    /**
     * Get the per-user preferences (implement-dashboard-settings §1.5).
     *
     * @param string $userId The user ID
     *
     * @return array<string,mixed>
     *
     * @spec openspec/changes/implement-dashboard-settings/tasks.md#task-1.5
     */
    public function getUserPreferences(string $userId): array
    {
        $appId        = Application::APP_ID;
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
     * @param string              $userId The user ID
     * @param array<string,mixed> $data   The input data
     *
     * @return array<string,mixed> The updated preferences
     *
     * @spec openspec/changes/implement-dashboard-settings/tasks.md#task-1.6
     */
    public function updateUserPreferences(string $userId, array $data): array
    {
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
                && in_array((string) $value, self::VALID_STALENESS_DAYS, true) === false
            ) {
                throw new InvalidArgumentException(
                    'health_staleness_days must be one of: '.implode(', ', self::VALID_STALENESS_DAYS)
                );
            }

            $this->config->setUserValue($userId, $appId, $key, (string) $value);
        }//end foreach

        return $this->getUserPreferences(userId: $userId);
    }//end updateUserPreferences()

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
     * Load and parse the doriath_register.json configuration file.
     *
     * Reads the monolith register file, then deep-merges every modular
     * fragment from lib/Settings/register.d/*.json (ADR-037) so concurrent
     * OpenSpec change builds drop disjoint fragment files instead of editing
     * the shared monolith. Returns the parsed data array on success, or an
     * error result array on failure (same shape as the public load method so
     * callers can return it).
     *
     * @return array<string,mixed> Either ['data' => array, 'version' => string]
     *                             or ['success' => false, 'message' => string]
     */
    private function loadRegisterConfigData(): array
    {
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

        // ADR-037: merge modular register fragments from Settings/register.d/*.json.
        // Each OpenSpec change drops its own fragment file instead of editing this
        // monolith, so concurrent builds touch disjoint files (no merge conflicts).
        // OpenAPI `components.schemas` / `paths` are keyed objects, so disjoint
        // fragments union cleanly by key.
        $fragmentDir = __DIR__.'/../Settings/register.d';
        $fragmentSig = '';
        if (is_dir($fragmentDir) === true) {
            $fragmentFiles = glob($fragmentDir.'/*.json');
            sort($fragmentFiles);
            foreach ($fragmentFiles as $fragmentFile) {
                $fragmentContent = file_get_contents($fragmentFile);
                if ($fragmentContent === false) {
                    continue;
                }

                $fragmentData = json_decode($fragmentContent, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->logger->warning(
                        'Doriath: skipping malformed register fragment '.basename($fragmentFile)
                        .': '.json_last_error_msg()
                    );
                    continue;
                }

                $configData   = self::deepMergeConfig(base: $configData, overlay: $fragmentData);
                $fragmentSig .= basename($fragmentFile).':'.md5($fragmentContent).';';
            }
        }//end if

        // Fold the fragment signature into the version so OpenRegister's
        // version-gated importFromApp re-imports whenever fragments change.
        $version = ($configData['info']['version'] ?? '0.0.0');
        if ($fragmentSig !== '') {
            $version .= '+frag.'.substr(md5($fragmentSig), 0, 8);
        }

        return [
            'data'    => $configData,
            'version' => $version,
        ];

    }//end loadRegisterConfigData()

    /**
     * Deep-merge a register fragment onto the base config (ADR-037).
     *
     * Associative arrays (OpenAPI objects like `components.schemas`, `paths`) are
     * merged by key union (recursing on shared keys); list arrays are concatenated;
     * scalars in the fragment overwrite the base. Disjoint fragments never collide.
     *
     * @param array<mixed> $base    The accumulated config.
     * @param array<mixed> $overlay The fragment to merge in.
     *
     * @return array<mixed> The merged config.
     */
    private static function deepMergeConfig(array $base, array $overlay): array
    {
        foreach ($overlay as $key => $value) {
            $bothArrays = (is_array($value) === true
                && isset($base[$key]) === true
                && is_array($base[$key]) === true);

            if ($bothArrays === false) {
                // Scalar (or new key): the overlay value wins.
                $base[$key] = $value;
                continue;
            }

            $baseIsList    = ($base[$key] === [] || array_keys($base[$key]) === range(0, (count($base[$key]) - 1)));
            $overlayIsList = ($value === [] || array_keys($value) === range(0, (count($value) - 1)));
            if ($baseIsList === true && $overlayIsList === true) {
                // Two lists: concatenate.
                $base[$key] = array_merge($base[$key], $value);
                continue;
            }

            // Two associative arrays: recurse.
            $base[$key] = self::deepMergeConfig(base: $base[$key], overlay: $value);
        }//end foreach

        return $base;

    }//end deepMergeConfig()

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
            $configLoad = $this->loadRegisterConfigData();
            if (isset($configLoad['success']) === true && $configLoad['success'] === false) {
                return $configLoad;
            }

            $configData    = $configLoad['data'];
            $configVersion = $configLoad['version'];

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
