<?php

/**
 * Keepiq SuiteMigrationEvent
 *
 * What every event of a compromise-recovery migration carries: the suite the
 * migration moves away from, the suite it moves to, and the migration itself.
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
 * A step in the life of one suite migration: started, completed or aborted.
 */
abstract class SuiteMigrationEvent extends Event {
	/**
	 * Constructor.
	 *
	 * @param string $oldSuiteId  The suite the migration moves away from
	 * @param string $newSuiteId  The suite the migration moves to
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
	 * Get the suite the migration moves away from: the compromised one, or for
	 * an abort the one the vault returns to.
	 *
	 * @return string
	 */
	public function getOldSuiteId(): string {
		return $this->oldSuiteId;
	}//end getOldSuiteId()

	/**
	 * Get the suite the migration moves to: the replacement, or for an abort
	 * the discarded successor.
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
