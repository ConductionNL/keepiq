<?php

/**
 * Keepiq RecoveryPolicyService
 *
 * @category Service
 * @package  OCA\Keepiq\Service
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

namespace OCA\Keepiq\Service;

use InvalidArgumentException;
use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\RecoveryOfficerMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;

/**
 * The administrator's settings for organisation account recovery
 * (crypto-organisation-account-recovery D2, D5): the policy, the officers and
 * the approval threshold.
 */
class RecoveryPolicyService {

	public const POLICY_KEY = 'account_recovery_policy';

	public const OFFICERS_KEY = 'account_recovery_officers';

	public const THRESHOLD_KEY = 'account_recovery_threshold';

	/**
	 * The policy values.
	 *
	 * @var string[]
	 */
	public const POLICIES = ['off', 'optional', 'required'];

	/**
	 * Constructor for RecoveryPolicyService.
	 *
	 * @param IAppConfig            $appConfig     The app config
	 * @param EncryptionSuiteMapper $suiteMapper   The suite mapper (officers need an active suite)
	 * @param RecoveryOfficerMapper $officerMapper The officer copies (removed officers lose theirs)
	 * @param NotificationService   $notifications The notification dispatcher
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private EncryptionSuiteMapper $suiteMapper,
		private RecoveryOfficerMapper $officerMapper,
		private NotificationService $notifications,
	) {
	}//end __construct()

	/**
	 * The policy: `off` (default), `optional` or `required`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-administrators-name-recovery-officers-a-threshold-and-a-policy
	 */
	public function policy(): string {
		$policy = $this->appConfig->getValueString(Application::APP_ID, self::POLICY_KEY, 'off');
		if (in_array($policy, self::POLICIES, true) === false) {
			return 'off';
		}

		return $policy;
	}//end policy()

	/**
	 * The named officers.
	 *
	 * @return string[]
	 *
	 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-administrators-name-recovery-officers-a-threshold-and-a-policy
	 */
	public function officers(): array {
		$decoded = json_decode($this->appConfig->getValueString(Application::APP_ID, self::OFFICERS_KEY, '[]'), true);
		if (is_array($decoded) === false) {
			return [];
		}

		return array_values(array_filter($decoded, static fn ($uid): bool => is_string($uid) && $uid !== ''));
	}//end officers()

	/**
	 * Whether a user is a named officer.
	 *
	 * @param string $userId The user
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-recovery-needs-a-threshold-of-proven-officer-approvals
	 */
	public function isOfficer(string $userId): bool {
		return in_array($userId, $this->officers(), true);
	}//end isOfficer()

	/**
	 * The approval threshold (at least 1).
	 *
	 * @return int
	 *
	 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-recovery-needs-a-threshold-of-proven-officer-approvals
	 */
	public function threshold(): int {
		return max(1, $this->appConfig->getValueInt(Application::APP_ID, self::THRESHOLD_KEY, 1));
	}//end threshold()

	/**
	 * The settings as the admin section shows them.
	 *
	 * @return array{policy:string,officers:string[],threshold:int}
	 *
	 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-administrators-name-recovery-officers-a-threshold-and-a-policy
	 */
	public function settings(): array {
		return [
			'policy' => $this->policy(),
			'officers' => $this->officers(),
			'threshold' => $this->threshold(),
		];
	}//end settings()

	/**
	 * Store the policy, officers and threshold. Refuses an unknown policy, an
	 * officer without an active suite, and a threshold outside 1..officers.
	 * Officers who are no longer named lose their copies of the recovery key.
	 *
	 * @param string   $policy    The policy
	 * @param string[] $officers  The officer user ids
	 * @param int      $threshold The approval threshold
	 *
	 * @return array{policy:string,officers:string[],threshold:int,removed:string[]}
	 *
	 * @throws InvalidArgumentException When a value is refused
	 *
	 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-administrators-name-recovery-officers-a-threshold-and-a-policy
	 */
	public function update(string $policy, array $officers, int $threshold): array {
		if (in_array($policy, self::POLICIES, true) === false) {
			throw new InvalidArgumentException(message: 'policy must be off, optional or required');
		}

		$named = array_values(array_unique(array_filter($officers, static fn ($uid): bool => is_string($uid) && $uid !== '')));
		$this->assertOfficersUsable(officers: $named);
		$this->assertThresholdFits(policy: $policy, officers: $named, threshold: $threshold);

		$before  = $this->officers();
		$removed = array_values(array_diff($before, $named));
		foreach ($removed as $uid) {
			$this->officerMapper->deleteByOfficer($uid);
		}

		$this->appConfig->setValueString(Application::APP_ID, self::POLICY_KEY, $policy);
		$this->appConfig->setValueString(Application::APP_ID, self::OFFICERS_KEY, (string)json_encode($named));
		$this->appConfig->setValueInt(Application::APP_ID, self::THRESHOLD_KEY, max(1, $threshold));

		foreach (array_diff($named, $before) as $uid) {
			$this->notifications->notify(subject: 'recovery_officer_named', recipientId: $uid);
		}

		return $this->settings() + ['removed' => $removed];
	}//end update()

	/**
	 * Refuse an officer without an active suite: they could not hold a copy.
	 *
	 * @param string[] $officers The officer user ids
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException
	 */
	private function assertOfficersUsable(array $officers): void {
		foreach ($officers as $uid) {
			try {
				$this->suiteMapper->findActiveByOwner('user', $uid);
			} catch (DoesNotExistException) {
				throw new InvalidArgumentException(message: 'Officer ' . $uid . ' has no active encryption suite');
			}
		}
	}//end assertOfficersUsable()

	/**
	 * Refuse recovery without officers, and a threshold outside 1..officers.
	 *
	 * @param string   $policy    The policy
	 * @param string[] $officers  The officer user ids
	 * @param int      $threshold The threshold
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException
	 */
	private function assertThresholdFits(string $policy, array $officers, int $threshold): void {
		if ($policy !== 'off' && $officers === []) {
			throw new InvalidArgumentException(message: 'Name at least one officer before turning recovery on');
		}

		if ($officers !== [] && ($threshold < 1 || $threshold > count($officers))) {
			throw new InvalidArgumentException(message: 'threshold must be between 1 and the number of officers');
		}
	}//end assertThresholdFits()
}//end class
