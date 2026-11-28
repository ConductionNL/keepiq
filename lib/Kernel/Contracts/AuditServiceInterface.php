<?php

/**
 * Contract of AuditService
 *
 * The public surface of AuditService that other components call, so they depend
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

use OCA\Keepiq\Db\AuditEntry;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Exception\AuditForbiddenMetadataException;

/**
 * Contract of AuditService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AuditServiceInterface {

	/**
	 * Admin instance-wide filtered + paginated query with a total count.
	 *
	 * @param array<string,mixed> $filters Filters (eventType/actor/objectType/objectId/from/to)
	 * @param int $page 1-based page number
	 * @param int $limit Page size
	 *
	 * @return array{entries: AuditEntry[], total: int, page: int, limit: int}
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-2.3
	 */
	public function adminQuery(array $filters, int $page = 1, int $limit = 50): array;

	/**
	 * Anonymize a deleted user out of the log without deleting entries.
	 *
	 * Replaces the user as actor_id by the deleted-account marker and scrubs
	 * whitelisted user-referencing metadata fields (recipientId, delegatedTo, and
	 * the user ids inside a list such as skippedUserIds) by the same marker.
	 * Entries are retained so other users' accountability
	 * records survive (design D6). No user id, display name, or other personal
	 * data of the deleted user remains afterwards.
	 *
	 * @param string $userId The deleted user id
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-2.3
	 */
	public function anonymizeUser(string $userId): void;

	/**
	 * List the entries a given user actored, newest first.
	 *
	 * @param string $actorId The actor user id
	 * @param int $limit Maximum rows
	 * @param int $offset Row offset
	 *
	 * @return AuditEntry[]
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-2.3
	 */
	public function listForActor(string $actorId, int $limit = 50, int $offset = 0): array;

	/**
	 * List audit entries for a single object (e.g. a secret), newest first.
	 *
	 * @param string $objectType The object type
	 * @param string $objectId The object id
	 * @param int $limit Maximum rows
	 * @param int $offset Row offset
	 *
	 * @return AuditEntry[]
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-2.3
	 */
	public function listForObject(string $objectType, string $objectId, int $limit = 50, int $offset = 0): array;

	/**
	 * Purge entries older than the retention window in bounded batches.
	 *
	 * One of only two permitted mutations of the log. Loops batch-by-batch so a
	 * single run can clear a large backlog without an unbounded statement.
	 *
	 * @param int $retentionDays The retention window in days
	 * @param int $batchSize Rows deleted per batch
	 *
	 * @return int Total rows deleted
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-2.3
	 */
	public function purge(int $retentionDays, int $batchSize = 1000): int;

	/**
	 * Record one audit entry from a typed event — the single insert path.
	 *
	 * Validates the event metadata against the per-event-type whitelist
	 * (unknown keys dropped) and rejects forbidden secret-material keys with an
	 * exception (design D3), then persists an immutable row.
	 *
	 * @param AuditEvent $event The audited operation
	 *
	 * @return AuditEntry The persisted entry
	 *
	 * @throws AuditForbiddenMetadataException When metadata contains a forbidden key
	 *
	 * @spec openspec/changes/add-secret-audit-trail/specs/secret-audit-trail/spec.md
	 */
	public function record(AuditEvent $event): AuditEntry;
}//end interface
