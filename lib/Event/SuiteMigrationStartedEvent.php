<?php

/**
 * Keepiq SuiteMigrationStartedEvent
 *
 * Dispatched when compromise recovery for an EncryptionSuite begins.
 * Listeners typically lock dependent resources (SecretRequests, etc.).
 *
 * @category Event
 * @package  OCA\Keepiq\Event
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

namespace OCA\Keepiq\Event;

use OCP\EventDispatcher\Event;

/**
 * Fired when compromise recovery for an EncryptionSuite begins.
 */
class SuiteMigrationStartedEvent extends Event {
	/**
	 * Constructor for SuiteMigrationStartedEvent.
	 *
	 * @param string $oldSuiteId The compromised suite ID
	 * @param string $newSuiteId The replacement suite ID
	 * @param string $migrationId The migration record ID
	 *
	 * @return void
	 */
	public function __construct(
		private string $oldSuiteId,
		private string $newSuiteId,
		private string $migrationId,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Get the compromised suite ID.
	 *
	 * @return string
	 */
	public function getOldSuiteId(): string {
		return $this->oldSuiteId;
	}//end getOldSuiteId()

	/**
	 * Get the replacement suite ID.
	 *
	 * @return string
	 */
	public function getNewSuiteId(): string {
		return $this->newSuiteId;
	}//end getNewSuiteId()

	/**
	 * Get the migration record ID.
	 *
	 * @return string
	 */
	public function getMigrationId(): string {
		return $this->migrationId;
	}//end getMigrationId()
}//end class
