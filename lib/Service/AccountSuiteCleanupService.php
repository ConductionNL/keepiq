<?php

/**
 * Keepiq Account Suite Cleanup Service
 *
 * The key-material step of the GDPR Art. 17 erasure cascade
 * (secret-export-gdpr D4), split out of AccountDeletionService: a user's
 * encryption suites (certificate + encrypted private key) and the suite
 * migration records that reference them are removed together, migrations
 * first so no record is left pointing at a deleted suite.
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

use OCA\Keepiq\Db\EmergencyContactMapper;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\PasskeyMapper;
use OCA\Keepiq\Db\SuiteMigrationMapper;

/**
 * Removes a user's encryption suites, their migration records, and the
 * key material escrowed outside them (emergency envelopes, passkeys).
 */
class AccountSuiteCleanupService {
	/**
	 * Constructor for AccountSuiteCleanupService.
	 *
	 * @param EncryptionSuiteMapper $suiteMapper The encryption-suite mapper
	 * @param SuiteMigrationMapper $migrationMapper The suite-migration mapper
	 * @param EmergencyContactMapper $emergencyMapper The emergency-contact mapper
	 * @param PasskeyMapper $passkeyMapper The passkey-credential mapper
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private EncryptionSuiteMapper $suiteMapper,
		private SuiteMigrationMapper $migrationMapper,
		private EmergencyContactMapper $emergencyMapper,
		private PasskeyMapper $passkeyMapper,
	) {
	}//end __construct()

	/**
	 * Remove the user's suites and their migration records.
	 *
	 * @param string $userId The departing user
	 * @param DeletionReport $report The running report
	 *
	 * @return void
	 *
	 * @spec openspec/specs/gdpr-compliance/spec.md
	 */
	public function removeSuites(string $userId, DeletionReport $report): void {
		$suites = $this->suiteMapper->findByOwner(ownerType: 'user', ownerId: $userId);
		$suiteIds = [];
		foreach ($suites as $suite) {
			$suiteIds[] = $suite->getId();
		}

		$this->migrationMapper->deleteBySuiteIds(suiteIds: $suiteIds);
		$report->suitesDeleted = $this->suiteMapper->deleteByOwnerUser(ownerId: $userId);
	}//end removeSuites()

	/**
	 * Remove the key material held OUTSIDE the suite rows: every emergency
	 * relationship the user is part of (a grantor row escrows the user's
	 * private key to the grantee) and the user's passkey unlock envelopes.
	 *
	 * The suites are hard-deleted through the mapper, so the revoke listener
	 * that clears envelopes never runs; this step does that work directly.
	 *
	 * @param string $userId The departing user
	 * @param DeletionReport $report The running report
	 *
	 * @return void
	 *
	 * @spec openspec/specs/gdpr-compliance/spec.md
	 */
	public function removeEscrowedKeys(string $userId, DeletionReport $report): void {
		$report->emergencyDeleted = $this->emergencyMapper->deleteByUser(userId: $userId);
		$report->passkeysDeleted = count($this->passkeyMapper->findByOwner(ownerId: $userId));
		$this->passkeyMapper->deleteByOwner(ownerId: $userId);
	}//end removeEscrowedKeys()
}//end class
