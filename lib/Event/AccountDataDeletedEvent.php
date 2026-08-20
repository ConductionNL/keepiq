<?php

/**
 * Doriath AccountDataDeletedEvent
 *
 * Dispatched ONLY on a completed account-deletion cascade run
 * (secret-export-gdpr D4/D5), by either trigger (in-app re-auth flow or the
 * UserDeletedEvent listener). The payload carries the trigger and per-entity
 * counts — NEVER secret names, values, or ciphertext.
 *
 * The audit-trail change consumes this via its AuditListener, which records a
 * vault.account_deleted row (whitelist: trigger, secretCount, shareCount,
 * requestCount, suiteCount) and then anonymizes the departed user out of the
 * trail.
 *
 * @category Event
 * @package  OCA\Doriath\Event
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

namespace OCA\Doriath\Event;

use OCA\Doriath\Service\DeletionReport;
use OCP\EventDispatcher\Event;

/**
 * Fired when an account-deletion cascade completes.
 */
class AccountDataDeletedEvent extends Event {
	/**
	 * Constructor for AccountDataDeletedEvent.
	 *
	 * @param string $userId The user whose data was deleted
	 * @param string $trigger The trigger (in-app|user-deleted)
	 * @param DeletionReport $report The per-entity deletion counts
	 *
	 * @return void
	 */
	public function __construct(
		private string $userId,
		private string $trigger,
		private DeletionReport $report,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Get the deleted user's ID.
	 *
	 * @return string
	 */
	public function getUserId(): string {
		return $this->userId;
	}//end getUserId()

	/**
	 * Get the deletion trigger.
	 *
	 * @return string
	 */
	public function getTrigger(): string {
		return $this->trigger;
	}//end getTrigger()

	/**
	 * Get the deletion report.
	 *
	 * @return DeletionReport
	 */
	public function getReport(): DeletionReport {
		return $this->report;
	}//end getReport()

	/**
	 * Get the audit metadata payload — counts and trigger only, never secret
	 * material. Keys match the AuditEventTypes whitelist for
	 * vault.account_deleted.
	 *
	 * @return array<string,mixed>
	 */
	public function getMetadata(): array {
		return [
			'trigger' => $this->trigger,
			'secretCount' => ($this->report->secretsDeleted + $this->report->secretsTransferred),
			'shareCount' => ($this->report->sharesDetached + $this->report->sharesRemoved),
			'requestCount' => $this->report->requestsDeleted,
			'suiteCount' => $this->report->suitesDeleted,
		];
	}//end getMetadata()
}//end class
