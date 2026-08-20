<?php

/**
 * Doriath Secret Request Suite Lock Service
 *
 * The compromise-recovery half of the secret-request lifecycle. While a
 * requester migrates to a new EncryptionSuite, every pending request bound
 * to the old suite is LOCKED — its fill-in link answers "temporarily
 * unavailable" instead of encrypting a value under key material that is
 * being retired. When the migration completes the same rows are re-pointed
 * at the new suite and reopened. Driven by the SuiteMigrationStarted and
 * SuiteMigrationCompleted listeners.
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
use OCA\Doriath\Db\SecretRequestMapper;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Suite-migration locking of pending secret requests.
 */
class SecretRequestSuiteLockService {
	/**
	 * Constructor for SecretRequestSuiteLockService.
	 *
	 * @param SecretRequestMapper $mapper The request mapper
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only — no domain logic.
	 */
	public function __construct(
		private SecretRequestMapper $mapper,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Lock all pending requests bound to an EncryptionSuite. Invoked by
	 * the compromise-recovery flow when the recipient's keys are flagged.
	 *
	 * @param string $encryptionSuiteId The recipient's old EncryptionSuite ID
	 *
	 * @return int The number of rows affected.
	 *
	 * @throws InvalidArgumentException When the suite ID is blank.
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-master-password-change-compromise-recovery
	 */
	public function lockByEncryptionSuiteId(string $encryptionSuiteId): int {
		if ($encryptionSuiteId === '') {
			throw new InvalidArgumentException(message: 'encryptionSuiteId is required');
		}

		$count = $this->mapper->lockByEncryptionSuiteId($encryptionSuiteId);

		$this->logger->info(
			'Locked ' . $count . ' pending secret requests for compromised suite ' . $encryptionSuiteId,
			['app' => 'doriath']
		);

		return $count;
	}//end lockByEncryptionSuiteId()

	/**
	 * Re-point locked requests at a new EncryptionSuite + reopen them.
	 *
	 * @param string $oldEncryptionSuiteId The old EncryptionSuite ID
	 * @param string $newEncryptionSuiteId The new EncryptionSuite ID
	 *
	 * @return int The number of rows affected.
	 *
	 * @throws InvalidArgumentException When either suite ID is blank.
	 * @throws RuntimeException When both suite IDs are the same.
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-suite-migration
	 */
	public function unlockAndUpdateSuite(string $oldEncryptionSuiteId, string $newEncryptionSuiteId): int {
		if ($oldEncryptionSuiteId === '' || $newEncryptionSuiteId === '') {
			throw new InvalidArgumentException(message: 'Both suite IDs are required');
		}

		if ($oldEncryptionSuiteId === $newEncryptionSuiteId) {
			throw new RuntimeException(message: 'oldEncryptionSuiteId and newEncryptionSuiteId must differ');
		}

		$count = $this->mapper->unlockAndUpdateSuite($oldEncryptionSuiteId, $newEncryptionSuiteId);

		$this->logger->info(
			'Unlocked ' . $count . ' secret requests by migrating suite ' . $oldEncryptionSuiteId . ' -> ' . $newEncryptionSuiteId,
			['app' => 'doriath']
		);

		return $count;
	}//end unlockAndUpdateSuite()
}//end class
