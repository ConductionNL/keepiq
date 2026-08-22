<?php

/**
 * Keepiq Password Policy Service
 *
 * The organisation password policy (org-password-policies §1.1/§1.3/§3.1):
 * the nine policy keys, the floor every write dialog must enforce, and the
 * `password_policy.updated` audit event with before/after values.
 *
 * Extracted from SettingsService: the policy family is the only settings
 * group that is audited, and the only one every authenticated user may
 * read — both of which are rules about the policy, not about settings.
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
use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IUserSession;

/**
 * Validates, persists, audits and republishes the org password policy.
 */
class PasswordPolicyService {
	/**
	 * The nine admin-writable policy keys. `updatePolicySettings()` audits a
	 * write only when the payload touches at least one of them, and the
	 * before/after snapshot is taken over exactly this list.
	 *
	 * @var string[]
	 */
	private const POLICY_KEYS = [
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

	/**
	 * The boolean policy keys, written verbatim with no bound to check.
	 *
	 * @var string[]
	 */
	private const POLICY_BOOL_KEYS = [
		'policy_enabled',
		'generator_require_upper',
		'generator_require_lower',
		'generator_require_digit',
		'generator_require_symbol',
	];

	/**
	 * The default exempt-type list, stored as a JSON string.
	 *
	 * @var string
	 */
	private const DEFAULT_EXEMPT_TYPES = '["note","ssh_key","certificate","passkey","card","identity"]';

	/**
	 * Default master-password floor length.
	 *
	 * This value has two other homes that must agree with it:
	 * `SettingsService::CONFIG_KEYS` (which is what the admin panel writes
	 * through) and `Repair\InitializeSettings::DEFAULT_CONFIG` (which seeds
	 * it). A vault whose repair step has not run still reports the real
	 * floor here instead of an empty string the UI would parse as `NaN`.
	 *
	 * @var string
	 */
	public const MASTER_PASSWORD_MIN_LENGTH_DEFAULT = '12';

	/**
	 * Default master-password floor zxcvbn score. Same three-way tie as
	 * MASTER_PASSWORD_MIN_LENGTH_DEFAULT.
	 *
	 * @var string
	 */
	public const MASTER_PASSWORD_MIN_SCORE_DEFAULT = '3';

	/**
	 * Constructor for the PasswordPolicyService.
	 *
	 * @param IAppConfig $appConfig The app config interface
	 * @param IUserSession $userSession The user session (audit actor)
	 * @param IEventDispatcher|null $eventDispatcher The audit dispatcher (policy changes)
	 * @param AuditEventFactory $auditEvents The audit-event factory
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; the policy rules carry the spec anchors.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private IUserSession $userSession,
		private ?IEventDispatcher $eventDispatcher = null,
		private AuditEventFactory $auditEvents = new AuditEventFactory(),
	) {
	}//end __construct()

	/**
	 * The user-visible policy floor for the write dialogs — policy gate,
	 * generator floor, score floor, HIBP block, and exempt types only
	 * (org-password-policies §1.3).
	 *
	 * The two `master_password_*` entries are the floors the admin panel
	 * writes through `updateSettings()`. They are republished here because
	 * this is the endpoint every authenticated user may read, and
	 * `PasswordStrengthMeter` — which gates the master-password forms — has
	 * no other source for them. Without this the admin panel would persist
	 * a value that nothing ever consults.
	 *
	 * They are read as strings and cast, matching how `updateSettings()` and
	 * `Repair\InitializeSettings` write them; `getValueInt()` on a
	 * string-typed app-config key raises a type conflict in Nextcloud.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/org-password-policies/specs/org-password-policies/spec.md
	 */
	public function getPolicy(): array {
		$appId = Application::APP_ID;

		return array_merge(
			[
				'master_password_min_length' => (int)$this->appConfig->getValueString(
					$appId,
					'master_password_min_length',
					self::MASTER_PASSWORD_MIN_LENGTH_DEFAULT
				),
				'master_password_min_score' => (int)$this->appConfig->getValueString(
					$appId,
					'master_password_min_score',
					self::MASTER_PASSWORD_MIN_SCORE_DEFAULT
				),
			],
			$this->readPolicyKeys()
		);
	}//end getPolicy()

	/**
	 * The nine policy keys with their stored (or default) values. This is
	 * the single reader both `getPolicy()` and the admin-settings payload
	 * use, so the two can never disagree about a default.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/org-password-policies/specs/org-password-policies/spec.md
	 */
	public function readPolicyKeys(): array {
		$appId = Application::APP_ID;

		return [
			'policy_enabled' => $this->appConfig->getValueBool($appId, 'policy_enabled', false),
			'generator_min_length' => $this->appConfig->getValueInt($appId, 'generator_min_length', 12),
			'generator_require_upper' => $this->appConfig->getValueBool($appId, 'generator_require_upper', false),
			'generator_require_lower' => $this->appConfig->getValueBool($appId, 'generator_require_lower', false),
			'generator_require_digit' => $this->appConfig->getValueBool($appId, 'generator_require_digit', false),
			'generator_require_symbol' => $this->appConfig->getValueBool($appId, 'generator_require_symbol', false),
			'min_zxcvbn_score' => $this->appConfig->getValueInt($appId, 'min_zxcvbn_score', 0),
			'block_on_hibp_hit' => $this->appConfig->getValueBool($appId, 'block_on_hibp_hit', false),
			'policy_exempt_types' => json_decode(
				$this->appConfig->getValueString($appId, 'policy_exempt_types', self::DEFAULT_EXEMPT_TYPES),
				true
			),
		];
	}//end readPolicyKeys()

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
	 * @spec openspec/changes/org-password-policies/specs/org-password-policies/spec.md
	 */
	public function updatePolicySettings(array $data): void {
		$touched = array_values(array_intersect(self::POLICY_KEYS, array_keys($data)));
		if ($touched === []) {
			return;
		}

		$before = array_intersect_key($this->readPolicyKeys(), array_flip($touched));

		$this->writeGeneratorFloors(data: $data);
		$this->writeBreachPolicyKeys(data: $data);
		$this->writeBooleanPolicyKeys(data: $data);

		$after = array_intersect_key($this->readPolicyKeys(), array_flip($touched));

		$this->dispatchPolicyAudit(before: $before, after: $after);
	}//end updatePolicySettings()

	/**
	 * The two generated-password floors, each with its own numeric bound.
	 *
	 * @param array<string,mixed> $data The admin-settings input
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException On invalid policy values
	 */
	private function writeGeneratorFloors(array $data): void {
		$appId = Application::APP_ID;

		if (isset($data['generator_min_length']) === true) {
			$minLength = (int)$data['generator_min_length'];
			if ($minLength < 8) {
				throw new InvalidArgumentException('generator_min_length must be at least 8');
			}

			$this->appConfig->setValueInt($appId, 'generator_min_length', $minLength);
		}

		if (isset($data['min_zxcvbn_score']) === true) {
			$score = (int)$data['min_zxcvbn_score'];
			if ($score < 0 || $score > 4) {
				throw new InvalidArgumentException('min_zxcvbn_score must be between 0 and 4');
			}

			$this->appConfig->setValueInt($appId, 'min_zxcvbn_score', $score);
		}
	}//end writeGeneratorFloors()

	/**
	 * The breach-check block and the exempt-type list. `block_on_hibp_hit`
	 * is the one policy key with a cross-key precondition: it is meaningless
	 * without the instance-wide breach-check gate, so enabling it while the
	 * gate is off is rejected rather than silently stored.
	 *
	 * @param array<string,mixed> $data The admin-settings input
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException On invalid policy values
	 */
	private function writeBreachPolicyKeys(array $data): void {
		$appId = Application::APP_ID;

		if (isset($data['block_on_hibp_hit']) === true) {
			$block = (bool)$data['block_on_hibp_hit'];
			$gate = $this->appConfig->getValueBool($appId, 'breach_check_enabled', false);
			if ($block === true && $gate === false) {
				throw new InvalidArgumentException(
					'block_on_hibp_hit requires breach_check_enabled — enable the breach check gate first'
				);
			}

			$this->appConfig->setValueBool($appId, 'block_on_hibp_hit', $block);
		}

		if (isset($data['policy_exempt_types']) === true) {
			$types = array_values(array_filter(array_map('strval', (array)$data['policy_exempt_types'])));
			$this->appConfig->setValueString($appId, 'policy_exempt_types', (string)json_encode($types));
		}
	}//end writeBreachPolicyKeys()

	/**
	 * The unbounded boolean policy keys.
	 *
	 * @param array<string,mixed> $data The admin-settings input
	 *
	 * @return void
	 */
	private function writeBooleanPolicyKeys(array $data): void {
		$appId = Application::APP_ID;

		foreach (self::POLICY_BOOL_KEYS as $boolKey) {
			if (isset($data[$boolKey]) === true) {
				$this->appConfig->setValueBool($appId, $boolKey, (bool)$data[$boolKey]);
			}
		}
	}//end writeBooleanPolicyKeys()

	/**
	 * Dispatch `password_policy.updated` with the before/after values of
	 * the touched keys — identifiers and settings values only.
	 *
	 * @param array<string,mixed> $before The touched keys before the write
	 * @param array<string,mixed> $after The touched keys after the write
	 *
	 * @return void
	 */
	private function dispatchPolicyAudit(array $before, array $after): void {
		$actorId = $this->userSession->getUser()?->getUID() ?? 'system';
		$this->eventDispatcher?->dispatchTyped(
			$this->auditEvents->forUser(
				actorId: $actorId,
				eventType: AuditEventTypes::PASSWORD_POLICY_UPDATED,
				objectType: 'settings',
				objectId: 'password_policy',
				objectName: '',
				metadata: [
					'before' => $before,
					'after' => $after,
				],
			)
		);
	}//end dispatchPolicyAudit()
}//end class
