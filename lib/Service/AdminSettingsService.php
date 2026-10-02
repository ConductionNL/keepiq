<?php

/**
 * Keepiq Admin Settings Service
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
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads and validates the instance-wide Keepiq configuration.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) 51 against a threshold of
 *   50, reached when the browser extension's maximum idle period joined the
 *   admin settings (clients-extension-unlock-lock-and-accounts). The service
 *   is being split per admin area by admin-scoped-roles (keepiq#774), which
 *   removes this; a separate service now would add a dependency to a class
 *   that sits at its coupling limit.
 */
class AdminSettingsService {
	/**
	 * Admin default bounds for validation.
	 */
	private const MIN_PASSWORD_LENGTH_MIN = 12;
	private const MIN_PASSWORD_LENGTH_MAX = 20;
	private const VALID_PASSWORD_SCORES = [3, 4];
	private const VALID_SESSION_TIMEOUTS = ['session', '10min', '30min'];

	/**
	 * The session timeout when neither the admin nor the user chose one. Ten
	 * minutes is what the vault did in practice before 'session' meant no idle
	 * timer, so an unset value never switches the idle lock off (crypto-07).
	 */
	public const DEFAULT_SESSION_TIMEOUT = '10min';

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
	 * The keys each settings-bearing admin area owns, except Policies, whose
	 * keys are POLICY_AREA_OWN_KEYS plus the password and vault policy keys
	 * (admin-scoped-roles, decision of 2 Oct:
	 * version and trash retention are vault rules, so they are Policies).
	 * The People area owns no settings keys, so it has no settings route.
	 *
	 * @var array<string,string[]>
	 */
	public const AREA_KEYS = [
		'general' => [
			'ca_auto_renew_enabled',
			'breach_check_enabled',
			'offline_cache_enabled',
			'offline_edits_enabled',
			'device_approval_enabled',
			'attachment_max_bytes',
			'attachment_user_quota_bytes',
		],
		'applications' => [
			'lease_default_ttl_seconds',
			'lease_max_ttl_seconds',
			'lease_renewable',
			'lease_revocation_blocks_refetch',
		],
		'audit' => ['audit_retention_days'],
	];

	/**
	 * The Policies keys this service writes itself; the org password and
	 * vault policy keys come from PasswordPolicyService.
	 *
	 * @var string[]
	 */
	private const POLICY_AREA_OWN_KEYS = [
		'min_password_length',
		'min_password_score',
		'default_session_timeout',
		'expiry_default_max_age_days',
		'expiry_reminder_days',
		'expiry_policy_enforced',
		'version_retention_count',
		'version_retention_days',
		'trash_retention_days',
		'extension_max_idle_minutes',
	];

	/**
	 * The settings-bearing areas, in route order.
	 *
	 * @var string[]
	 */
	public const SETTINGS_AREAS = ['general', 'policies', 'applications', 'audit'];

	/**
	 * The idle lock delays the browser extension offers, in minutes
	 * (browser-extension-autofill, user-chosen idle lock period). The
	 * administrator maximum is one of these.
	 *
	 * @var int[]
	 */
	public const EXTENSION_IDLE_CHOICES = [1, 5, 15, 30, 60, 240];

	/**
	 * The extension idle maximum when the administrator set none.
	 *
	 * @var int
	 */
	public const EXTENSION_MAX_IDLE_DEFAULT = 240;

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
	 * @param IAppConfig $appConfig The app config interface
	 * @param IAppManager $appManager The app manager
	 * @param ContainerInterface $container The container
	 * @param IUserSession $userSession The user session (audit actor)
	 * @param LoggerInterface $logger The logger
	 * @param IEventDispatcher|null $eventDispatcher The audit dispatcher (policy changes)
	 * @param PasswordPolicyService|null $policyService The org password policy
	 * @param RegisterConfigurationLoader|null $registerLoader The register-configuration loader
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
		?IEventDispatcher $eventDispatcher = null,
		?PasswordPolicyService $policyService = null,
		?RegisterConfigurationLoader $registerLoader = null,
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
	public function getAdminSettings(): array {
		$appId = Application::APP_ID;
		$settings = array_merge(
			[
				'min_password_length' => $this->appConfig->getValueInt($appId, 'min_password_length', 12),
				'min_password_score' => $this->appConfig->getValueInt($appId, 'min_password_score', 3),
				'default_session_timeout' => $this->appConfig->getValueString(
					$appId,
					'default_session_timeout',
					self::DEFAULT_SESSION_TIMEOUT
				),
				'ca_auto_renew_enabled' => $this->appConfig->getValueBool($appId, 'ca_auto_renew_enabled', true),
				'audit_retention_days' => $this->appConfig->getValueInt(
					$appId,
					'audit_retention_days',
					self::AUDIT_RETENTION_DEFAULT
				),
				'breach_check_enabled' => $this->appConfig->getValueBool($appId, 'breach_check_enabled', false),
				'expiry_default_max_age_days' => $this->appConfig->getValueInt(
					$appId,
					'expiry_default_max_age_days',
					0
				),
				'expiry_reminder_days' => json_decode(
					$this->appConfig->getValueString($appId, 'expiry_reminder_days', '[30,7,1]'),
					true
				),
				'version_retention_count' => $this->appConfig->getValueInt($appId, 'version_retention_count', 20),
				'version_retention_days' => $this->appConfig->getValueInt($appId, 'version_retention_days', 365),
				// Trash retention (vault-trash-and-archive D4), 1 to 365 days.
				'trash_retention_days' => $this->appConfig->getValueInt(
					$appId,
					'trash_retention_days',
					SecretTrashService::RETENTION_DEFAULT
				),
				'attachment_max_bytes' => $this->appConfig->getValueInt(
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
			$this->policyService->readAdminPolicyKeys(),
			[
				// Machine leases (machine-secret-leases §2.4).
				'lease_default_ttl_seconds' => $this->appConfig->getValueInt(
					$appId,
					'lease_default_ttl_seconds',
					900
				),
				'lease_max_ttl_seconds' => $this->appConfig->getValueInt(
					$appId,
					'lease_max_ttl_seconds',
					86400
				),
				'lease_renewable' => $this->appConfig->getValueBool($appId, 'lease_renewable', true),
				'lease_revocation_blocks_refetch' => $this->appConfig->getValueBool(
					$appId,
					'lease_revocation_blocks_refetch',
					false
				),
				// The longest idle lock delay a user may pick in the browser extension.
				'extension_max_idle_minutes' => $this->extensionMaxIdleMinutes(),
				// New device approval (crypto-new-device-approval D5), default on.
				'device_approval_enabled' => $this->appConfig->getValueBool($appId, 'device_approval_enabled', true),
			],
			$this->offlineSettings()
		);

		// Best-effort CA status; never blocks if the service is unavailable.
		try {
			$caService = $this->container->get('OCA\Keepiq\Service\CertificateAuthorityService');
			if (method_exists($caService, 'getStatus') === true) {
				$settings['ca_status'] = $caService->getStatus();
			}
		} catch (Throwable $e) {
			$this->logger->debug('Keepiq: CA status unavailable: ' . $e->getMessage());
			$settings['ca_status'] = ['status' => 'unknown'];
		}

		return $settings;
	}//end getAdminSettings()

	/**
	 * The offline cache switches: offline reading (default on) and offline
	 * edits (default off).
	 *
	 * @return array<string,bool>
	 *
	 * @spec openspec/specs/offline-readonly-cache/spec.md#requirement-an-admin-can-disable-offline-caching-org-wide
	 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-administrators-control-offline-edits
	 */
	private function offlineSettings(): array {
		$appId = Application::APP_ID;
		return [
			'offline_cache_enabled' => $this->appConfig->getValueBool($appId, 'offline_cache_enabled', true),
			'offline_edits_enabled' => $this->appConfig->getValueBool($appId, 'offline_edits_enabled', false),
		];
	}//end offlineSettings()

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
	 * @spec openspec/changes/admin-vault-policies/tasks.md#1.2
	 */
	public function updateAdminSettings(array $data): array {
		// Each group validates and persists one family of keys. Every guard
		// is independent — an absent key is left untouched, an out-of-bounds
		// value throws before anything in its group is written.
		foreach (self::SETTINGS_AREAS as $area) {
			$this->writeArea(area: $area, data: $data);
		}

		return $this->getAdminSettings();
	}//end updateAdminSettings()

	/**
	 * The settings of one admin area (admin-scoped-roles D2). General also
	 * carries the CA status its section shows.
	 *
	 * @param string $area One of SETTINGS_AREAS
	 *
	 * @return array<string,mixed>
	 *
	 * @throws InvalidArgumentException On an unknown area.
	 *
	 * @spec openspec/changes/admin-scoped-roles/tasks.md#2.1
	 */
	public function getAreaSettings(string $area): array {
		$keys = $this->areaKeys(area: $area);
		if ($area === 'general') {
			$keys[] = 'ca_status';
		}

		return array_intersect_key($this->getAdminSettings(), array_flip($keys));
	}//end getAreaSettings()

	/**
	 * Write one admin area's keys and nothing else (admin-scoped-roles D2).
	 *
	 * A key that belongs to another area is refused rather than dropped, so
	 * a caller can never read a partial save as a whole one. Keys no area
	 * owns are ignored, as the combined write always did.
	 *
	 * @param string $area One of SETTINGS_AREAS
	 * @param array<string,mixed> $data The input data
	 *
	 * @return array<string,mixed> The area's settings after the write
	 *
	 * @throws InvalidArgumentException On an unknown area, a key of another
	 *                                  area, or an out-of-bounds value.
	 *
	 * @spec openspec/changes/admin-scoped-roles/tasks.md#2.1
	 */
	public function updateAreaSettings(string $area, array $data): array {
		$own = $this->areaKeys(area: $area);
		foreach (self::SETTINGS_AREAS as $other) {
			if ($other === $area) {
				continue;
			}

			$foreign = array_values(array_intersect(array_keys($data), $this->areaKeys(area: $other)));
			if ($foreign !== []) {
				throw new InvalidArgumentException(
					$foreign[0] . ' belongs to the ' . $other . ' area, not to ' . $area
				);
			}
		}

		$this->writeArea(area: $area, data: array_intersect_key($data, array_flip($own)));

		return $this->getAreaSettings(area: $area);
	}//end updateAreaSettings()

	/**
	 * The keys one area owns.
	 *
	 * @param string $area One of SETTINGS_AREAS
	 *
	 * @return string[]
	 *
	 * @throws InvalidArgumentException On an unknown area.
	 */
	private function areaKeys(string $area): array {
		if ($area === 'policies') {
			return array_merge(
				self::POLICY_AREA_OWN_KEYS,
				array_keys($this->policyService->readAdminPolicyKeys())
			);
		}

		if (isset(self::AREA_KEYS[$area]) === false) {
			throw new InvalidArgumentException('Unknown admin settings area: ' . $area);
		}

		return self::AREA_KEYS[$area];
	}//end areaKeys()

	/**
	 * Run the validated writers of one area. Each writer ignores absent keys.
	 *
	 * @param string $area One of SETTINGS_AREAS
	 * @param array<string,mixed> $data The input data
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException On out-of-bounds values.
	 */
	private function writeArea(string $area, array $data): void {
		switch ($area) {
			case 'general':
				$this->updateInstanceSettings(data: $data);
				$this->updateAttachmentSettings(data: $data);
				return;
			case 'policies':
				$this->updateAuthenticationSettings(data: $data);
				$this->policyService->updatePolicySettings(data: $data);
				$this->updateExpirySettings(data: $data);
				$this->updateRetentionSettings(data: $data);
				$this->updateTrashSettings(data: $data);
				$this->updateExtensionSettings(data: $data);
				return;
			case 'applications':
				$this->updateLeaseSettings(data: $data);
				return;
			case 'audit':
				$this->updateAuditSettings(data: $data);
				return;
			default:
				throw new InvalidArgumentException('Unknown admin settings area: ' . $area);
		}
	}//end writeArea()

	/**
	 * The user-visible policy floor for the write dialogs
	 * (org-password-policies §1.3).
	 *
	 * @param string|null $userId The session user, for the effective vault policies
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/org-password-policies/specs/org-password-policies/spec.md
	 * @spec openspec/changes/admin-vault-policies/tasks.md#1.2
	 */
	public function getPolicy(?string $userId = null): array {
		return $this->policyService->getPolicy(userId: $userId);
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
	private function updateAuthenticationSettings(array $data): void {
		$appId = Application::APP_ID;

		if (isset($data['min_password_length']) === true) {
			$length = (int)$data['min_password_length'];
			if ($length < self::MIN_PASSWORD_LENGTH_MIN || $length > self::MIN_PASSWORD_LENGTH_MAX) {
				throw new InvalidArgumentException(
					'min_password_length must be between ' . self::MIN_PASSWORD_LENGTH_MIN
					. ' and ' . self::MIN_PASSWORD_LENGTH_MAX
				);
			}

			$this->appConfig->setValueInt($appId, 'min_password_length', $length);
		}

		if (isset($data['min_password_score']) === true) {
			$score = (int)$data['min_password_score'];
			if (in_array($score, self::VALID_PASSWORD_SCORES, true) === false) {
				throw new InvalidArgumentException('min_password_score must be 3 or 4');
			}

			$this->appConfig->setValueInt($appId, 'min_password_score', $score);
		}

		if (isset($data['default_session_timeout']) === true) {
			$timeout = (string)$data['default_session_timeout'];
			if (in_array($timeout, self::VALID_SESSION_TIMEOUTS, true) === false) {
				throw new InvalidArgumentException(
					'default_session_timeout must be one of: ' . implode(', ', self::VALID_SESSION_TIMEOUTS)
				);
			}

			$this->appConfig->setValueString($appId, 'default_session_timeout', $timeout);
		}
	}//end updateAuthenticationSettings()

	/**
	 * Instance-wide switches: CA renewal, breach checking and the offline
	 * read-only cache (offline-readonly-cache §1.1).
	 *
	 * @param array<string,mixed> $data The admin-settings input
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException On out-of-bounds values.
	 */
	private function updateInstanceSettings(array $data): void {
		$appId = Application::APP_ID;

		if (isset($data['ca_auto_renew_enabled']) === true) {
			$this->appConfig->setValueBool($appId, 'ca_auto_renew_enabled', (bool)$data['ca_auto_renew_enabled']);
		}

		if (isset($data['breach_check_enabled']) === true) {
			$this->appConfig->setValueBool($appId, 'breach_check_enabled', (bool)$data['breach_check_enabled']);
		}

		if (isset($data['offline_cache_enabled']) === true) {
			$this->appConfig->setValueBool($appId, 'offline_cache_enabled', (bool)$data['offline_cache_enabled']);
		}

		if (isset($data['device_approval_enabled']) === true) {
			$this->appConfig->setValueBool($appId, 'device_approval_enabled', (bool)$data['device_approval_enabled']);
		}

		if (isset($data['offline_edits_enabled']) === true) {
			$this->appConfig->setValueBool($appId, 'offline_edits_enabled', (bool)$data['offline_edits_enabled']);
		}
	}//end updateInstanceSettings()

	/**
	 * Audit retention (add-secret-audit-trail §4.2), the one Audit area key.
	 *
	 * @param array<string,mixed> $data The admin-settings input
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the window is below the minimum.
	 */
	private function updateAuditSettings(array $data): void {
		if (isset($data['audit_retention_days']) === false) {
			return;
		}

		$days = (int)$data['audit_retention_days'];
		if ($days < self::AUDIT_RETENTION_MIN) {
			throw new InvalidArgumentException(
				'audit_retention_days must be at least ' . self::AUDIT_RETENTION_MIN
				. ' days — below that the audit trail cannot serve incident investigation'
			);
		}

		$this->appConfig->setValueInt(Application::APP_ID, 'audit_retention_days', $days);
	}//end updateAuditSettings()

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
	private function updateExpirySettings(array $data): void {
		$appId = Application::APP_ID;

		if (isset($data['expiry_default_max_age_days']) === true) {
			$maxAge = (int)$data['expiry_default_max_age_days'];
			if ($maxAge < 0) {
				throw new InvalidArgumentException('expiry_default_max_age_days must be 0 (off) or positive');
			}

			$this->appConfig->setValueInt($appId, 'expiry_default_max_age_days', $maxAge);
		}

		if (isset($data['expiry_reminder_days']) === true) {
			$thresholds = array_values(
				array_filter(
					array_map('intval', (array)$data['expiry_reminder_days']),
					static fn (int $days): bool => $days > 0
				)
			);
			if ($thresholds === []) {
				throw new InvalidArgumentException('expiry_reminder_days needs at least one positive threshold');
			}

			$this->appConfig->setValueString($appId, 'expiry_reminder_days', (string)json_encode($thresholds));
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
	private function updateLeaseSettings(array $data): void {
		$appId = Application::APP_ID;

		foreach (['lease_default_ttl_seconds', 'lease_max_ttl_seconds'] as $leaseKey) {
			if (isset($data[$leaseKey]) === true) {
				$ttl = (int)$data[$leaseKey];
				if ($ttl < 60) {
					throw new InvalidArgumentException($leaseKey . ' must be at least 60 seconds');
				}

				$this->appConfig->setValueInt($appId, $leaseKey, $ttl);
			}
		}

		if (isset($data['lease_renewable']) === true) {
			$this->appConfig->setValueBool($appId, 'lease_renewable', (bool)$data['lease_renewable']);
		}

		if (isset($data['lease_revocation_blocks_refetch']) === true) {
			$this->appConfig->setValueBool(
				$appId,
				'lease_revocation_blocks_refetch',
				(bool)$data['lease_revocation_blocks_refetch']
			);
		}
	}//end updateLeaseSettings()

	/**
	 * Version retention (secret-version-history §4.1). A floor of 1 kept
	 * version preserves restorability; days 0 = unlimited age.
	 *
	 * @param array<string,mixed> $data The admin-settings input
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException On out-of-bounds values.
	 */
	private function updateRetentionSettings(array $data): void {
		$appId = Application::APP_ID;

		if (isset($data['version_retention_count']) === true) {
			$count = (int)$data['version_retention_count'];
			if ($count < 1) {
				throw new InvalidArgumentException('version_retention_count must be at least 1');
			}

			$this->appConfig->setValueInt($appId, 'version_retention_count', $count);
		}

		if (isset($data['version_retention_days']) === true) {
			$days = (int)$data['version_retention_days'];
			if ($days < 0) {
				throw new InvalidArgumentException('version_retention_days must be 0 (unlimited) or positive');
			}

			$this->appConfig->setValueInt($appId, 'version_retention_days', $days);
		}
	}//end updateRetentionSettings()

	/**
	 * Attachment limits (encrypted-attachments §2.5), in stored CIPHERTEXT
	 * bytes, which is what actually consumes disk.
	 *
	 * @param array<string,mixed> $data The admin-settings input
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException On a non-positive byte count.
	 */
	private function updateAttachmentSettings(array $data): void {
		$appId = Application::APP_ID;

		if (isset($data['attachment_max_bytes']) === true) {
			$maxBytes = (int)$data['attachment_max_bytes'];
			if ($maxBytes < 1) {
				throw new InvalidArgumentException('attachment_max_bytes must be a positive byte count');
			}

			$this->appConfig->setValueInt($appId, 'attachment_max_bytes', $maxBytes);
		}

		if (isset($data['attachment_user_quota_bytes']) === true) {
			$quota = (int)$data['attachment_user_quota_bytes'];
			if ($quota < 1) {
				throw new InvalidArgumentException('attachment_user_quota_bytes must be a positive byte count');
			}

			$this->appConfig->setValueInt($appId, 'attachment_user_quota_bytes', $quota);
		}
	}//end updateAttachmentSettings()

	/**
	 * Validate and persist the trash retention (vault-trash-and-archive D4).
	 *
	 * @param array<string,mixed> $data The input data
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the retention is outside 1 to 365 days.
	 *
	 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-restoring-and-purging-trashed-secrets
	 */
	private function updateTrashSettings(array $data): void {
		if (isset($data['trash_retention_days']) === false) {
			return;
		}

		$days = (int)$data['trash_retention_days'];
		if ($days < SecretTrashService::RETENTION_MIN || $days > SecretTrashService::RETENTION_MAX) {
			throw new InvalidArgumentException('trash_retention_days must be between 1 and 365');
		}

		$this->appConfig->setValueInt(Application::APP_ID, 'trash_retention_days', $days);
	}//end updateTrashSettings()

	/**
	 * The administrator maximum for the extension idle lock delay, in
	 * minutes. A stored value outside the offered delays falls back to the
	 * default, so a hand-edited config never switches the idle lock off.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/browser-extension-autofill/spec.md#requirement-user-chosen-idle-lock-period-with-an-administrator-maximum
	 */
	public function extensionMaxIdleMinutes(): int {
		$minutes = $this->appConfig->getValueInt(
			Application::APP_ID,
			'extension_max_idle_minutes',
			self::EXTENSION_MAX_IDLE_DEFAULT
		);
		if (in_array($minutes, self::EXTENSION_IDLE_CHOICES, true) === false) {
			return self::EXTENSION_MAX_IDLE_DEFAULT;
		}

		return $minutes;
	}//end extensionMaxIdleMinutes()

	/**
	 * Persist the extension idle maximum: one of the offered delays.
	 *
	 * @param array<string,mixed> $data The input data
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the value is not an offered delay.
	 *
	 * @spec openspec/specs/browser-extension-autofill/spec.md#requirement-user-chosen-idle-lock-period-with-an-administrator-maximum
	 */
	private function updateExtensionSettings(array $data): void {
		if (isset($data['extension_max_idle_minutes']) === false) {
			return;
		}

		$minutes = (int)$data['extension_max_idle_minutes'];
		if (in_array($minutes, self::EXTENSION_IDLE_CHOICES, true) === false) {
			throw new InvalidArgumentException('extension_max_idle_minutes must be one of 1, 5, 15, 30, 60 or 240');
		}

		$this->appConfig->setValueInt(Application::APP_ID, 'extension_max_idle_minutes', $minutes);
	}//end updateExtensionSettings()
}//end class
