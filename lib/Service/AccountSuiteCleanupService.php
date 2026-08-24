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

use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\SuiteMigrationMapper;

/**
 * Removes a user's encryption suites and their migration records.
 */
class AccountSuiteCleanupService {
	/**
	 * Constructor for AccountSuiteCleanupService.
	 *
	 * @param EncryptionSuiteMapper $suiteMapper The encryption-suite mapper
	 * @param SuiteMigrationMapper $migrationMapper The suite-migration mapper
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private EncryptionSuiteMapper $suiteMapper,
		private SuiteMigrationMapper $migrationMapper,
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
	 * @spec openspec/changes/secret-export-gdpr/specs/gdpr-compliance/spec.md
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
}//end class
