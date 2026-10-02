<?php

/**
 * Keepiq RecoveryEnrolmentService
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

use DateTime;
use InvalidArgumentException;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\RecoveryEnrolment;
use OCA\Keepiq\Db\RecoveryEnrolmentMapper;
use OCA\Keepiq\Exception\ForbiddenException;
use OCP\AppFramework\Db\DoesNotExistException;
use Ramsey\Uuid\Uuid;

/**
 * A user's enrolment in organisation account recovery (D1, D5): their suite
 * private key wrapped, in their own browser, to the recovery certificate.
 */
class RecoveryEnrolmentService {

	/**
	 * Constructor for RecoveryEnrolmentService.
	 *
	 * @param RecoveryEnrolmentMapper $mapper      The enrolment mapper
	 * @param RecoveryPolicyService   $policy      The policy
	 * @param RecoveryKeyService      $keys        The recovery keys
	 * @param EncryptionSuiteMapper   $suiteMapper The suite mapper
	 * @param RecoveryAudit           $audit       The audit trail
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private RecoveryEnrolmentMapper $mapper,
		private RecoveryPolicyService $policy,
		private RecoveryKeyService $keys,
		private EncryptionSuiteMapper $suiteMapper,
		private RecoveryAudit $audit,
	) {
	}//end __construct()

	/**
	 * What the user's browser needs: the policy, whether the user is enrolled
	 * to the current key and suite, and the active key to enrol with.
	 *
	 * @param string $userId The user
	 *
	 * @return array{policy:string,enrolled:bool,current:bool,key:array<string,mixed>|null}
	 *
	 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-users-enrol-by-wrapping-their-own-key-to-the-recovery-certificate
	 */
	public function status(string $userId): array {
		$key       = $this->keys->publicInfo();
		$enrolment = $this->current(userId: $userId);

		return [
			'policy' => $this->policy->policy(),
			'enrolled' => ($enrolment !== null),
			// Enrolled to the active key and the active suite: nothing to redo.
			'current' => ($enrolment !== null
				&& $key !== null
				&& $enrolment->getRecoveryKeyId() === $key['id']
				&& $enrolment->getSuiteId() === $this->activeSuiteId(userId: $userId)),
			'key' => $key,
		];
	}//end status()

	/**
	 * The user's enrolment, if any.
	 *
	 * @param string $userId The user
	 *
	 * @return RecoveryEnrolment|null
	 *
	 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-users-enrol-by-wrapping-their-own-key-to-the-recovery-certificate
	 */
	public function current(string $userId): ?RecoveryEnrolment {
		return ($this->mapper->findByUser($userId)[0] ?? null);
	}//end current()

	/**
	 * Enrol, replacing any earlier enrolment of the user.
	 *
	 * @param string $userId        The user
	 * @param string $recoveryKeyId The key the envelope is wrapped to (must be active)
	 * @param string $envelope      The hybrid envelope of the user's private key
	 *
	 * @return RecoveryEnrolment
	 *
	 * @throws ForbiddenException       When recovery is off
	 * @throws InvalidArgumentException When the key is not the active one or the envelope is empty
	 *
	 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-users-enrol-by-wrapping-their-own-key-to-the-recovery-certificate
	 */
	public function enrol(string $userId, string $recoveryKeyId, string $envelope): RecoveryEnrolment {
		if ($this->policy->policy() === 'off') {
			throw new ForbiddenException(message: 'Account recovery is off');
		}

		$active = $this->keys->activeKey();
		if ($active === null || $active->getId() !== $recoveryKeyId) {
			throw new InvalidArgumentException(message: 'Enrol with the active recovery key');
		}

		$decoded = json_decode($envelope, true);
		if (is_array($decoded) === false || isset($decoded['encKey'], $decoded['ct']) === false) {
			throw new InvalidArgumentException(message: 'envelope is not a recovery envelope');
		}

		$suiteId = $this->activeSuiteId(userId: $userId);
		if ($suiteId === '') {
			throw new InvalidArgumentException(message: 'You have no active encryption suite');
		}

		foreach ($this->mapper->findByUser($userId) as $old) {
			$this->mapper->delete($old);
		}

		$enrolment = new RecoveryEnrolment();
		$enrolment->setId(Uuid::uuid4()->toString());
		$enrolment->setUserId($userId);
		$enrolment->setSuiteId($suiteId);
		$enrolment->setRecoveryKeyId($recoveryKeyId);
		$enrolment->setEnvelope($envelope);
		$enrolment->setEnrolledAt(new DateTime());
		$enrolment = $this->mapper->insert($enrolment);

		$this->audit->record(
			actorId: $userId,
			eventType: RecoveryAudit::ENROLLED,
			objectId: $enrolment->getId(),
			metadata: ['suiteId' => $enrolment->getSuiteId()]
		);

		return $enrolment;
	}//end enrol()

	/**
	 * Withdraw, refused under the `required` policy.
	 *
	 * @param string $userId The user
	 *
	 * @return void
	 *
	 * @throws ForbiddenException When the policy requires enrolment
	 *
	 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-users-enrol-by-wrapping-their-own-key-to-the-recovery-certificate
	 */
	public function withdraw(string $userId): void {
		if ($this->policy->policy() === 'required') {
			throw new ForbiddenException(message: 'Your organisation requires account recovery');
		}

		foreach ($this->mapper->findByUser($userId) as $enrolment) {
			$this->mapper->delete($enrolment);
			$this->audit->record(actorId: $userId, eventType: RecoveryAudit::WITHDRAWN, objectId: $enrolment->getId());
		}
	}//end withdraw()

	/**
	 * Delete the enrolments of a suite (rotation done or suite revoked, D7).
	 *
	 * @param string $suiteId The suite
	 *
	 * @return int The number deleted
	 *
	 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-enrolments-and-officer-copies-follow-the-suite
	 */
	public function deleteForSuite(string $suiteId): int {
		$deleted = 0;
		foreach ($this->mapper->findBySuite($suiteId) as $enrolment) {
			$this->mapper->delete($enrolment);
			++$deleted;
		}

		return $deleted;
	}//end deleteForSuite()

	/**
	 * The user's active suite id, or '' when there is none.
	 *
	 * @param string $userId The user
	 *
	 * @return string
	 */
	private function activeSuiteId(string $userId): string {
		try {
			return $this->suiteMapper->findActiveByOwner('user', $userId)->getId();
		} catch (DoesNotExistException) {
			return '';
		}
	}//end activeSuiteId()
}//end class
