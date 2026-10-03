<?php

/**
 * Keepiq Suite Setup Guard
 *
 * Runs the "no active suite yet" check and the insert of a plain suite
 * creation under one exclusive lock per owner (keepiq#751). Without it two
 * first-time setups submitted at the same moment (a double click, two tabs)
 * both counted zero active suites and both inserted one, leaving the owner
 * with two active suites. The compromise-recovery successor path does not
 * come here: it is the one flow allowed a second active suite.
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
 *
 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-plain-create-refuses-to-mint-a-second-active-suite
 */

declare(strict_types=1);

namespace OCA\Keepiq\Service;

use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Exception\ConflictException;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * The single-active-suite rule, made atomic per owner.
 *
 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-plain-create-refuses-to-mint-a-second-active-suite
 */
class SuiteSetupGuard {
	/**
	 * Constructor for SuiteSetupGuard.
	 *
	 * @param EncryptionSuiteMapper $mapper The encryption suite mapper
	 * @param ILockingProvider|null $locking Nextcloud's locking provider
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private EncryptionSuiteMapper $mapper,
		private ?ILockingProvider $locking = null,
	) {
	}//end __construct()

	/**
	 * Refuse when the owner already has an active suite, else persist one,
	 * both while holding the owner's setup lock. A setup that finds the lock
	 * held by another request for the same owner is refused too.
	 *
	 * @param string $ownerType 'user' or 'application'
	 * @param string $ownerId Nextcloud user ID or Application ID
	 * @param callable():EncryptionSuite $persist Inserts the suite
	 *
	 * @return EncryptionSuite
	 *
	 * @throws ConflictException When a suite exists or a setup is in progress
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-plain-create-refuses-to-mint-a-second-active-suite
	 */
	public function createExclusive(string $ownerType, string $ownerId, callable $persist): EncryptionSuite {
		$lockKey = 'keepiq/suite/'.$ownerType.'/'.$ownerId;
		try {
			$this->locking?->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			throw new ConflictException(message: 'A vault setup for this owner is already in progress.');
		}

		try {
			// Reported as #289: without this check any session could mint a
			// second active suite, and resolution picks the NEWEST, so new
			// secrets were sealed to a key the owner was not unlocking with.
			if ($this->mapper->countActiveByOwner(ownerType: $ownerType, ownerId: $ownerId) > 0) {
				throw new ConflictException(
					message: 'An active EncryptionSuite already exists for this owner. '
					.'Change the master password or start a compromise recovery instead of creating a second suite.'
				);
			}

			return $persist();
		} finally {
			$this->locking?->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}//end createExclusive()
}//end class
