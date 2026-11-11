<?php

/**
 * Keepiq Termination Notices
 *
 * The log lines and events that announce how a migration termination ended.
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

use OCA\Keepiq\Db\SuiteMigration;
use OCA\Keepiq\Event\SuiteMigrationAbortedEvent;
use OCA\Keepiq\Event\SuiteMigrationCompletedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * Announces a completed, aborted or ignored termination of a migration;
 * built by MigrationService for each termination.
 *
 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-migration-always-has-a-way-to-terminate
 */
final class TerminationNotices {
	/**
	 * Constructor for TerminationNotices.
	 *
	 * @param LoggerInterface $logger The logger
	 * @param IEventDispatcher|null $eventDispatcher The optional event dispatcher
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ?IEventDispatcher $eventDispatcher,
	) {
	}//end __construct()

	/**
	 * Answer a completion retried on an already-terminated migration, which
	 * changes nothing.
	 *
	 * @param SuiteMigration $migration The terminated migration
	 * @param string $migrationId The migration id the caller asked for
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-migration-always-has-a-way-to-terminate
	 */
	public function ignoredCompletion(SuiteMigration $migration, string $migrationId): array {
		$this->logger->info(
			'Keepiq: completeMigration called on an already-terminated migration; ignoring',
			['migrationId' => $migrationId, 'status' => $migration->getStatus()]
		);

		return [
			'status' => $migration->getStatus(),
			'droppedVersions' => 0,
			'unrecoverable' => [],
			'alreadyCompleted' => true,
		];
	}//end ignoredCompletion()

	/**
	 * Log the completion and tell the listeners.
	 *
	 * NOT re-implemented here: pending SecretRequests are unlocked and
	 * re-pointed by SuiteMigrationCompletedListener, and emergency-access
	 * envelopes are invalidated by EmergencyAccessSuiteRotationListener.
	 * Both already listen for the event dispatched here.
	 *
	 * @param TerminationRun $run The completed migration
	 * @param bool $hasErrors Whether the caller reported errors
	 *
	 * @return void
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-migration-always-has-a-way-to-terminate
	 */
	public function completed(TerminationRun $run, bool $hasErrors): void {
		$this->logger->info(
			"Keepiq: Compromise recovery completed for migration {$run->migrationId}",
			['hasErrors' => $hasErrors]
		);

		$this->eventDispatcher?->dispatchTyped(
			new SuiteMigrationCompletedEvent(
				oldSuiteId: $run->migration->getOldSuiteId(),
				newSuiteId: $run->migration->getNewSuiteId(),
				migrationId: $run->migration->getId(),
				hasErrors: $hasErrors,
			)
		);
	}//end completed()

	/**
	 * Answer an abort retried on an already-terminated migration, which
	 * changes nothing.
	 *
	 * @param SuiteMigration $migration The terminated migration
	 * @param string $migrationId The migration id the caller asked for
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-migration-always-has-a-way-to-terminate
	 */
	public function ignoredAbort(SuiteMigration $migration, string $migrationId): array {
		$this->logger->info(
			'Keepiq: abortMigration called on an already-terminated migration; ignoring',
			['migrationId' => $migrationId, 'status' => $migration->getStatus()]
		);

		return [
			'status' => $migration->getStatus(),
			'aborted' => false,
			'alreadyTerminated' => true,
		];
	}//end ignoredAbort()

	/**
	 * Tell the listeners and log that the vault is back on the old suite.
	 *
	 * NOT SuiteMigrationCompletedEvent: that event runs the terminal cascade
	 * (compromise-flagging, link-share revocation, emergency-access
	 * invalidation) which must never fire for an abort. The aborted event
	 * carries the single reaction abort needs — releasing the SecretRequests
	 * that SuiteMigrationStartedListener locked, keeping them on the old
	 * suite — handled by SuiteMigrationAbortedListener.
	 *
	 * @param TerminationRun $run The aborted migration
	 *
	 * @return void
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-migration-always-has-a-way-to-terminate
	 */
	public function aborted(TerminationRun $run): void {
		$this->eventDispatcher?->dispatchTyped(
			new SuiteMigrationAbortedEvent(
				oldSuiteId: $run->migration->getOldSuiteId(),
				newSuiteId: $run->migration->getNewSuiteId(),
				migrationId: $run->migration->getId(),
			)
		);

		$this->logger->info(
			"Keepiq: Compromise recovery aborted for migration {$run->migrationId}; vault returned to the old suite",
			['oldSuiteId' => $run->migration->getOldSuiteId()]
		);
	}//end aborted()
}//end class
