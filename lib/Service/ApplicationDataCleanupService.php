<?php

/**
 * Keepiq Application Data Cleanup Service
 *
 * The data half of the application-mgmt "Delete Application" cascade: every
 * secret attributed to the application (with its attachments, versions and
 * tags), the secret requests on those secrets and the ones the application
 * created, the application's encryption suites and their migration records.
 * Runs in one transaction, before the application row goes, so a failure
 * leaves the application in place for a retry instead of orphaned rows.
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
 *
 * @spec openspec/specs/application-mgmt/spec.md#requirement-delete-application
 */

declare(strict_types=1);

namespace OCA\Keepiq\Service;

use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretRequest;
use OCA\Keepiq\Db\SecretRequestMapper;
use OCA\Keepiq\Db\SuiteMigrationMapper;
use OCP\IDBConnection;
use Throwable;

/**
 * Removes an application's secrets, requests and encryption suites.
 *
 * @spec openspec/specs/application-mgmt/spec.md#requirement-delete-application
 */
class ApplicationDataCleanupService {
	/**
	 * Constructor for ApplicationDataCleanupService.
	 *
	 * @param IDBConnection $db The database connection (transaction)
	 * @param SecretMapper $secretMapper The secret mapper
	 * @param EncryptionSuiteMapper $suiteMapper The encryption-suite mapper
	 * @param SecretRequestMapper $requestMapper The secret-request mapper
	 * @param SuiteMigrationMapper $migrationMapper The suite-migration mapper
	 * @param SecretChildDataCleaner $childData The attachment/version/tag cascade
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private IDBConnection $db,
		private SecretMapper $secretMapper,
		private EncryptionSuiteMapper $suiteMapper,
		private SecretRequestMapper $requestMapper,
		private SuiteMigrationMapper $migrationMapper,
		private SecretChildDataCleaner $childData,
	) {
	}//end __construct()

	/**
	 * Remove everything the application owns, in one transaction.
	 *
	 * @param string $applicationId The application being deleted
	 *
	 * @return array{secrets:int,suites:int,requests:int} What was removed
	 *
	 * @throws Throwable When a step fails; the transaction is rolled back
	 *
	 * @spec openspec/specs/application-mgmt/spec.md#requirement-delete-application
	 */
	public function removeFor(string $applicationId): array {
		$this->db->beginTransaction();
		try {
			$removed = $this->removeRows(applicationId: $applicationId);
			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		return $removed;
	}//end removeFor()

	/**
	 * The ordered steps: child data and requests per secret, then the
	 * secrets, then the requests the application created, then the suites.
	 *
	 * @param string $applicationId The application being deleted
	 *
	 * @return array{secrets:int,suites:int,requests:int} What was removed
	 *
	 * @spec openspec/specs/application-mgmt/spec.md#requirement-delete-application
	 */
	private function removeRows(string $applicationId): array {
		$secrets = $this->secretMapper->findByOwner('application', $applicationId, null, null, 'asc', 100000, 0);
		foreach ($secrets as $secret) {
			$this->childData->purgeForSecret(secretId: $secret->getId());
			$this->requestMapper->deleteBySecretId(secretId: $secret->getId());
		}

		$secretCount = $this->secretMapper->deleteByOwnerApplication(applicationId: $applicationId);
		$requestCount = $this->requestMapper->deleteByCreatedBy(
			userId: SecretRequest::ACTOR_APPLICATION_PREFIX.$applicationId
		);

		$suites = $this->suiteMapper->findByOwner(ownerType: 'application', ownerId: $applicationId);
		$suiteIds = [];
		foreach ($suites as $suite) {
			$suiteIds[] = $suite->getId();
		}

		$this->migrationMapper->deleteBySuiteIds(suiteIds: $suiteIds);
		foreach ($suites as $suite) {
			$this->suiteMapper->delete($suite);
		}

		$suiteCount = count($suites);

		return ['secrets' => $secretCount, 'suites' => $suiteCount, 'requests' => $requestCount];
	}//end removeRows()
}//end class
