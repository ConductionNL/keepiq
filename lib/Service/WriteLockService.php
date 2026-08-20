<?php

/**
 * Doriath Write Lock Service
 *
 * Answers one question — is this owner write-locked by an in-progress
 * compromise-recovery migration? — and depends on nothing but the two mappers
 * needed to answer it.
 *
 * That narrow dependency set is the point. MigrationService already owns
 * `isWriteLocked`, but it also depends on LinkShareService for the terminal
 * cascade, so injecting MigrationService into LinkShareService to enforce the
 * lock would close a dependency cycle and the container would refuse to build
 * it. Every service that needs to ASK about the lock takes this instead, and
 * MigrationService delegates here so there is still exactly one implementation.
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

use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\SuiteMigrationMapper;
use OCA\Doriath\Exception\WriteLockedException;

/**
 * Reports and enforces the compromise-recovery write lock.
 */
class WriteLockService {
	/**
	 * Constructor for WriteLockService.
	 *
	 * @param SuiteMigrationMapper $migrationMapper The suite migration mapper
	 * @param EncryptionSuiteMapper $suiteMapper The encryption suite mapper
	 *
	 * @return void
	 */
	public function __construct(
		private SuiteMigrationMapper $migrationMapper,
		private EncryptionSuiteMapper $suiteMapper,
	) {
	}//end __construct()

	/**
	 * Whether an owner has a compromise-recovery migration in progress.
	 *
	 * A migration row IS the write lock: it exists for exactly as long as the
	 * vault is mid-rotation. Reads are deliberately not affected — the
	 * migration itself has to read the old ciphertext, and the user needs their
	 * vault legible while it runs.
	 *
	 * @param string $ownerType The owner type
	 * @param string $ownerId The owner ID
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-suite-migration
	 */
	public function isWriteLocked(string $ownerType, string $ownerId): bool {
		foreach ($this->suiteMapper->findByOwner($ownerType, $ownerId) as $suite) {
			if ($this->migrationMapper->hasInProgress($suite->getId()) === true) {
				return true;
			}
		}

		return false;
	}//end isWriteLocked()

	/**
	 * Refuse a write while the owner is mid-rotation.
	 *
	 * @param string $ownerId The owner ID
	 * @param string $ownerType The owner type
	 *
	 * @return void
	 *
	 * @throws WriteLockedException When a migration is in progress
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-suite-migration
	 */
	public function assertNotWriteLocked(string $ownerId, string $ownerType = 'user'): void {
		if ($this->isWriteLocked(ownerType: $ownerType, ownerId: $ownerId) === true) {
			throw new WriteLockedException(
				message: 'A compromise-recovery migration is in progress. '
					. 'Finish or resume it before making changes.'
			);
		}
	}//end assertNotWriteLocked()
}//end class
