<?php

/**
 * Keepiq migration: one share target per recipient copy (GHSA-m5p9-6pw7-pf3g)
 *
 * Makes `keepiq_share_targets.secret_id` unique. The client-supplied-id
 * share-create endpoints are retired, so new rows always point at a copy the
 * server minted for the recipient; this index stops a second row ever naming
 * the same copy again.
 *
 * The old endpoints could plant a row whose `secret_id` names a secret the
 * attacker chose — including a victim's own, pre-existing secret — so a revoke
 * or sync from the attacker's source would reach that secret. Cleanup never
 * deletes such a row: a false positive would destroy a legitimate, still
 * revocable user share. Once the quarantine columns exist, the step runs
 * ShareQuarantineScanner, which QUARANTINES suspect rows. When that pass does
 * not complete, ShareQuarantineScanJob runs it again from cron. Only exact
 * duplicates are deleted, and only so the unique index can be added; no
 * secret is ever deleted.
 *
 * @category Migration
 * @package  OCA\Keepiq\Migration
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Migration;

use Closure;
use OCA\Keepiq\Service\ShareQuarantineScanner;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Psr\Log\LoggerInterface;

/**
 * Deduplicates `keepiq_share_targets.secret_id`, makes it unique, and
 * quarantines (never deletes) planted rows.
 *
 * @psalm-suppress UnusedClass Loaded by the Nextcloud migration framework.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-revoke-share
 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
 */
class Version001015Date20261021120000 extends SimpleMigrationStep {

	/**
	 * The share targets table.
	 *
	 * @var string
	 */
	private const TABLE = 'keepiq_share_targets';

	/**
	 * The secrets table, read for a duplicated copy's owner.
	 *
	 * @var string
	 */
	private const SECRETS_TABLE = 'keepiq_secrets';

	/**
	 * The old non-unique index on secret_id.
	 *
	 * @var string
	 */
	public const OLD_INDEX = 'keepiq_st_copy_idx';

	/**
	 * The new unique index on secret_id.
	 *
	 * @var string
	 */
	public const UNIQUE_INDEX = 'keepiq_st_copy_uniq';

	/**
	 * The quarantine timestamp column: null = active, a datetime = quarantined.
	 *
	 * @var string
	 */
	public const COL_QUARANTINED_AT = 'quarantined_at';

	/**
	 * The quarantine reason column (nullable).
	 *
	 * @var string
	 */
	public const COL_QUARANTINE_REASON = 'quarantine_reason';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $connection The database connection
	 * @param LoggerInterface|null $logger The logger (durable record of deleted duplicates)
	 * @param ShareQuarantineScanner|null $scanner Quarantines planted rows (left to the background job when null)
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private IDBConnection $connection,
		private ?LoggerInterface $logger = null,
		private ?ShareQuarantineScanner $scanner = null,
	) {
	}//end __construct()

	/**
	 * Remove exact duplicates before the unique index is added, so adding it
	 * cannot fail on existing rows. Only duplicate link rows are deleted (the
	 * row whose copy its target holds is kept); no secret is deleted, and no
	 * suspect row is deleted — those are quarantined later, after the quarantine
	 * columns exist.
	 *
	 * @param IOutput $output        The migration output
	 * @param Closure $schemaClosure Returns the current schema
	 * @param array   $options       Migration options
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Signature fixed by SimpleMigrationStep.
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-revoke-share
	 */
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$schema = $schemaClosure();
		if ($schema->hasTable(self::TABLE) === false) {
			return;
		}

		$this->removeDuplicateRows(output: $output);
	}//end preSchemaChange()

	/**
	 * Add the quarantine columns, then swap the non-unique secret_id index for a
	 * unique one. A quarantined non-duplicate row keeps a unique `secret_id`, so
	 * it never blocks the index. All guarded so the step is idempotent.
	 *
	 * @param IOutput $output        The migration output
	 * @param Closure $schemaClosure Returns the current schema
	 * @param array   $options       Migration options
	 *
	 * @return ISchemaWrapper|null
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Signature fixed by SimpleMigrationStep.
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-revoke-share
	 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable(self::TABLE) === false) {
			return null;
		}

		$table   = $schema->getTable(self::TABLE);
		$changed = false;
		if ($table->hasColumn(self::COL_QUARANTINED_AT) === false) {
			$table->addColumn(self::COL_QUARANTINED_AT, Types::DATETIME, ['notnull' => false]);
			$changed = true;
		}

		if ($table->hasColumn(self::COL_QUARANTINE_REASON) === false) {
			$table->addColumn(self::COL_QUARANTINE_REASON, Types::STRING, ['notnull' => false, 'length' => 255]);
			$changed = true;
		}

		if ($table->hasIndex(self::OLD_INDEX) === true) {
			$table->dropIndex(self::OLD_INDEX);
			$changed = true;
		}

		if ($table->hasIndex(self::UNIQUE_INDEX) === false) {
			$table->addUniqueIndex(['secret_id'], self::UNIQUE_INDEX);
			$changed = true;
		}

		if ($changed === false) {
			return null;
		}

		return $schema;
	}//end changeSchema()

	/**
	 * Quarantine planted rows now that the columns exist. The scanner never
	 * throws, so a detection error cannot brick `occ upgrade`; a pass that does
	 * not complete leaves its completion flag unset and ShareQuarantineScanJob
	 * runs it again.
	 *
	 * @param IOutput $output        The migration output
	 * @param Closure $schemaClosure Returns the current schema
	 * @param array   $options       Migration options
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Signature fixed by SimpleMigrationStep.
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$schema = $schemaClosure();
		if ($schema->hasTable(self::TABLE) === false
			|| $schema->getTable(self::TABLE)->hasColumn(self::COL_QUARANTINED_AT) === false
		) {
			return;
		}

		if ($this->scanner === null) {
			$output->warning(
				'GHSA-m5p9-6pw7-pf3g: the share quarantine scanner is not available here, '
					.'so planted share rows are quarantined by the ShareQuarantineScanJob background job'
			);
			return;
		}

		$this->scanner->run(output: $output);
	}//end postSchemaChange()

	/**
	 * Deduplicate `secret_id` groups, keeping the row whose copy is actually
	 * held by its target (otherwise the earliest). Only link rows are deleted.
	 *
	 * @param IOutput $output The migration output
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-revoke-share
	 */
	private function removeDuplicateRows(IOutput $output): void {
		foreach ($this->duplicatedCopyIds() as $secretId) {
			$owner = $this->copyOwner(secretId: $secretId);
			$rows  = $this->shareRowsFor(secretId: $secretId);
			$keep  = self::keeperId(
				rows: $rows,
				ownerType: ($owner['type'] ?? null),
				ownerId: ($owner['id'] ?? null)
			);

			foreach ($rows as $row) {
				if ($row['id'] === $keep) {
					continue;
				}

				$this->deleteShareTarget(id: $row['id']);
				$this->reportDeletion(output: $output, row: $row, reason: 'duplicate copy');
			}
		}//end foreach
	}//end removeDuplicateRows()

	/**
	 * Record one deleted duplicate row to the migration output and the log, so
	 * admins can find later which rows were removed and why.
	 *
	 * @param IOutput                                                                    $output The migration output
	 * @param array{id:string,sourceSecretId:string,targetUserId:string,secretId:string} $row    The deleted row
	 * @param string                                                                     $reason Why it was deleted
	 *
	 * @return void
	 */
	private function reportDeletion(IOutput $output, array $row, string $reason): void {
		$message = 'GHSA-m5p9-6pw7-pf3g: deleted keepiq_share_targets row '.$row['id']
			.' (source '.$row['sourceSecretId'].', target '.$row['targetUserId']
			.', secret_id '.$row['secretId'].'): '.$reason.'; its secret was left untouched';
		$output->warning($message);
		$this->logger?->warning(
			$message,
			[
				'app' => 'keepiq',
				'id' => $row['id'],
				'source' => $row['sourceSecretId'],
				'target' => $row['targetUserId'],
				'secretId' => $row['secretId'],
			]
		);
	}//end reportDeletion()

	/**
	 * The row to keep out of a `secret_id` group: the earliest row whose copy
	 * is actually held by its target, or, when none is, the earliest row. The
	 * rows arrive ordered oldest first.
	 *
	 * @param list<array{id:string,targetUserId:string,sourceSecretId?:string,secretId?:string}> $rows      The group's rows, oldest first
	 * @param string|null                                                                        $ownerType The copy's owner type
	 * @param string|null                                                                        $ownerId   The copy's owner id
	 *
	 * @return string The id of the row to keep ('' when the group is empty)
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-revoke-share
	 */
	public static function keeperId(array $rows, ?string $ownerType, ?string $ownerId): string {
		if ($rows === []) {
			return '';
		}

		if ($ownerType === 'user' && $ownerId !== null) {
			foreach ($rows as $row) {
				if ($row['targetUserId'] === $ownerId) {
					return $row['id'];
				}
			}
		}

		return $rows[0]['id'];
	}//end keeperId()

	/**
	 * The `secret_id` values shared by more than one row. The `> 1` filter runs
	 * in the database (HAVING), so only the duplicated ids cross to PHP.
	 *
	 * @return list<string>
	 */
	private function duplicatedCopyIds(): array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('secret_id')
			->from(self::TABLE)
			->groupBy('secret_id')
			->having(
				$qb->expr()->gt(
					$qb->func()->count('id'),
					$qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)
				)
			);

		$ids    = [];
		$result = $qb->executeQuery();
		while (($row = $result->fetch()) !== false) {
			$ids[] = (string)$row['secret_id'];
		}

		$result->closeCursor();

		return $ids;
	}//end duplicatedCopyIds()

	/**
	 * The owner of the copy a `secret_id` points at, or null when it is gone.
	 *
	 * @param string $secretId The recipient copy's secret id
	 *
	 * @return array{type:string,id:string}|null
	 */
	private function copyOwner(string $secretId): ?array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('owner_type', 'owner_id')
			->from(self::SECRETS_TABLE)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($secretId)))
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$row    = $result->fetch();
		$result->closeCursor();
		if ($row === false) {
			return null;
		}

		return ['type' => (string)$row['owner_type'], 'id' => (string)$row['owner_id']];
	}//end copyOwner()

	/**
	 * The share rows naming one `secret_id`, oldest first.
	 *
	 * @param string $secretId The recipient copy's secret id
	 *
	 * @return list<array{id:string,sourceSecretId:string,targetUserId:string,secretId:string}>
	 */
	private function shareRowsFor(string $secretId): array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('id', 'source_secret_id', 'target_user_id', 'secret_id')
			->from(self::TABLE)
			->where($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)))
			->orderBy('created_at', 'ASC')
			->addOrderBy('id', 'ASC');

		$rows   = [];
		$result = $qb->executeQuery();
		while (($row = $result->fetch()) !== false) {
			$rows[] = [
				'id' => (string)$row['id'],
				'sourceSecretId' => (string)$row['source_secret_id'],
				'targetUserId' => (string)$row['target_user_id'],
				'secretId' => (string)$row['secret_id'],
			];
		}

		$result->closeCursor();

		return $rows;
	}//end shareRowsFor()

	/**
	 * Delete one share-target row by id.
	 *
	 * @param string $id The share-target row id
	 *
	 * @return void
	 */
	private function deleteShareTarget(string $id): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		$qb->executeStatement();
	}//end deleteShareTarget()
}//end class
