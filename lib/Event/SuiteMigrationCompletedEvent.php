<?php

/**
 * Keepiq SuiteMigrationCompletedEvent
 *
 * Dispatched when compromise recovery for an EncryptionSuite completes.
 * Listeners typically unlock dependent resources and re-point them at the
 * new suite (e.g. SecretRequest re-encryption pivot).
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
 * Fired when compromise recovery for an EncryptionSuite completes.
 */
class SuiteMigrationCompletedEvent extends Event {
	/**
	 * Constructor for SuiteMigrationCompletedEvent.
	 *
	 * @param string $oldSuiteId The compromised suite ID
	 * @param string $newSuiteId The replacement suite ID
	 * @param string $migrationId The migration record ID
	 * @param bool $hasErrors Whether the migration completed with errors
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $hasErrors is event payload, not a
	 *   flag argument: the constructor does not branch on it, it is stored and read
	 *   back by listeners through hasErrors(). The value originates as a field of the
	 *   POST body on MigrationController::complete().
	 */
	public function __construct(
		private string $oldSuiteId,
		private string $newSuiteId,
		private string $migrationId,
		private bool $hasErrors = false,
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

	/**
	 * Whether the migration completed with errors.
	 *
	 * @return bool
	 */
	public function hasErrors(): bool {
		return $this->hasErrors;
	}//end hasErrors()
}//end class
