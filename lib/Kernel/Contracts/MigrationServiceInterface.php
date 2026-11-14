<?php

/**
 * Contract of MigrationService
 *
 * The public surface of MigrationService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Keepiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/keepiq
 *
 * @spec openspec/specs/app-shell/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Kernel\Contracts;

use OCA\Keepiq\Db\SuiteMigration;
use OCA\Keepiq\Exception\MigrationAbortRefusedException;
use OCA\Keepiq\Exception\SuiteMigrationInProgressException;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Contract of MigrationService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MigrationServiceInterface {

	/**
	 * Abort a compromise-recovery migration, returning the vault to the old suite.
	 *
	 * The abort route the `compromiseRecovery` refusal message already promises.
	 * It is the non-destructive terminal: completion carries the vault FORWARD to
	 * the new suite and marks the old one compromised; abort carries it BACK to
	 * the old suite, which stays `active` and readable.
	 *
	 * Abort is permitted ONLY while no record has been committed to the new
	 * suite. Once a record has moved, the two possible outcomes both lose data —
	 * discarding the successor strands what has moved, keeping it strands what has
	 * not — so the migration stays `in_progress` and the caller is pointed at
	 * resuming. This restriction is also exactly sufficient for the case abort
	 * exists to remedy: producing a valid re-encrypted record requires the
	 * plaintext, hence the master password, so a hostile session that never held
	 * it can never have committed a record and can always be aborted away.
	 *
	 * Idempotent by status, like completeMigration: a retried abort on an already
	 * terminal migration is a no-op, not a second teardown.
	 *
	 * @param string      $migrationId The migration to abort
	 * @param string|null $actorId     Who aborted it, for the audit trail; the owner when null
	 *
	 * @return array<string,mixed> The terminal migration plus an `aborted` flag
	 *
	 * @throws MigrationAbortRefusedException When a record has already been committed
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-migration-can-be-aborted-before-any-record-moves
	 */
	public function abortMigration(string $migrationId, ?string $actorId = null): array;

	/**
	 * Refuse when the suite is either end of a migration still in progress.
	 *
	 * Revoking the old end blocks the reads the owner's browser needs to
	 * re-encrypt; revoking the new end strands what was already re-encrypted.
	 * Either way the migration and the write lock stay `in_progress` with no
	 * way to finish (keepiq#803). Both revoke paths call this before touching
	 * anything. It is a check, not a lock: a migration started in the moment
	 * between this call and revokeSuite() is not caught.
	 *
	 * @param string $suiteId The suite about to be revoked
	 *
	 * @return void
	 *
	 * @throws SuiteMigrationInProgressException When a migration involving the suite is in progress
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-suite-in-an-in-progress-migration-cannot-be-revoked
	 */
	public function assertNoMigrationInProgress(string $suiteId): void;

	/**
	 * Complete a migration (with or without errors).
	 *
	 * @param string $migrationId The migration ID
	 * @param bool $hasErrors Whether the migration had errors
	 * @param int|null $acceptUnrecoverable How many records the caller accepts losing access to,
	 *                                      required when any row on the old suite has a recorded
	 *                                      failure. Null means "no loss accepted", which refuses.
	 *
	 * @return array<string,mixed> The terminal migration, dropped-version count and lost secrets
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $hasErrors is outcome DATA forwarded
	 *   from MigrationController::complete(), not a mode switch: the completion path is
	 *   identical either way and the flag only selects which terminal status string is
	 *   written to the row. The false default is the ordinary success call.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-4
	 */
	public function completeMigration(
		string $migrationId,
		bool $hasErrors = false,
		?int $acceptUnrecoverable = null,
	): array;

	/**
	 * Get in-progress migration for a given owner (via their old suite).
	 *
	 * @param string $ownerType The owner type
	 * @param string $ownerId The owner ID
	 *
	 * @return SuiteMigration
	 *
	 * @throws DoesNotExistException if no in-progress migration exists
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-4
	 */
	public function getInProgressMigration(string $ownerType, string $ownerId): SuiteMigration;

	/**
	 * Fetch a migration by its ID.
	 *
	 * @param string $migrationId The migration ID
	 *
	 * @return SuiteMigration
	 *
	 * @throws DoesNotExistException if no migration with that ID exists
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-4
	 */
	public function getMigration(string $migrationId): SuiteMigration;

	/**
	 * Initiate a compromise recovery migration.
	 *
	 * @param string $oldSuiteId The old suite ID
	 * @param string $newSuiteId The new suite ID
	 *
	 * @return SuiteMigration
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-4
	 */
	public function initiateCompromiseRecovery(string $oldSuiteId, string $newSuiteId): SuiteMigration;

	/**
	 * Check if an owner is write-locked due to an active migration.
	 *
	 * @param string $ownerType The owner type
	 * @param string $ownerId The owner ID
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-4
	 */
	public function isWriteLocked(string $ownerType, string $ownerId): bool;
}//end interface
