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
 * revocable user share, and a planted row is a reversible problem. Instead the
 * step QUARANTINES a suspect row (sets `quarantined_at`), which every runtime
 * path skips, and writes an admin report. Clearing `quarantined_at` restores
 * the row. Only exact duplicates are deleted, and only so the unique index can
 * be added; no secret is ever deleted.
 *
 * Provenance decides a quarantine, not source membership: a server-minted
 * recipient copy is created in the SAME request as its share row, so the copy
 * and the row share a `created_at`. A planted row points at a secret that
 * PREDATES it. A row whose copy was created clearly before the row — by more
 * than `MINT_WINDOW_SECONDS`, sized for the legacy two-step client flow — is
 * quarantined. A legitimate onward-share row (a recipient's copy that is itself
 * a share source) was minted with its row, so it is kept.
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
use DateTime;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Deduplicates `keepiq_share_targets.secret_id`, makes it unique, and
 * quarantines (never deletes) rows whose copy predates the row.
 *
 * @psalm-suppress UnusedClass Loaded by the Nextcloud migration framework.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-revoke-share
 */
class Version001015Date20261021120000 extends SimpleMigrationStep {

	/**
	 * The share targets table.
	 *
	 * @var string
	 */
	private const TABLE = 'keepiq_share_targets';

	/**
	 * The secrets table, joined to read a copy's creation time.
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
	 * How far a recipient copy's `created_at` may trail its share row's and
	 * still count as minted with it. Sized for the legacy two-step client flow;
	 * a copy older than this is treated as one the server did not mint for the
	 * row (a planted victim secret).
	 *
	 * @var int
	 */
	public const MINT_WINDOW_SECONDS = 600;

	/**
	 * The reason recorded on a quarantined row.
	 *
	 * @var string
	 */
	private const QUARANTINE_REASON = 'GHSA-m5p9-6pw7-pf3g: recipient copy predates its share row';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $connection The database connection
	 * @param LoggerInterface|null $logger The logger (durable record of quarantined rows)
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private IDBConnection $connection,
		private ?LoggerInterface $logger = null,
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
	 * Quarantine suspect rows now that the columns exist. Non-fatal: any failure
	 * is logged and the upgrade continues, so a detection error can never brick
	 * `occ upgrade`. The schema changes above are deliberately NOT in this guard.
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
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$schema = $schemaClosure();
		if ($schema->hasTable(self::TABLE) === false
			|| $schema->getTable(self::TABLE)->hasColumn(self::COL_QUARANTINED_AT) === false
		) {
			return;
		}

		try {
			$this->quarantineSuspectRows(output: $output);
		} catch (Throwable $exception) {
			$message = 'GHSA-m5p9-6pw7-pf3g: quarantine scan failed; the upgrade continued'
				.' and no row was quarantined ('.$exception->getMessage().')';
			$output->warning($message);
			$this->logger?->warning($message, ['app' => 'keepiq', 'exception' => $exception]);
		}
	}//end postSchemaChange()

	/**
	 * Stream the candidate rows — those whose recipient copy was created before
	 * the row (a filtered join to `keepiq_secrets`, never the whole table) — and
	 * quarantine any whose copy predates the row by more than the mint window.
	 *
	 * @param IOutput $output The migration output
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-revoke-share
	 */
	private function quarantineSuspectRows(IOutput $output): void {
		$result = $this->candidateQuery()->executeQuery();
		while (($row = $result->fetch()) !== false) {
			$rowCreatedAt  = $this->toTimestamp((string)$row['row_created_at']);
			$copyCreatedAt = $this->toTimestamp((string)$row['copy_created_at']);
			if ($rowCreatedAt === null || $copyCreatedAt === null) {
				continue;
			}

			if (($rowCreatedAt - $copyCreatedAt) <= self::MINT_WINDOW_SECONDS) {
				continue;
			}

			$this->quarantineRow(id: (string)$row['id']);
			$this->reportQuarantine(
				output: $output,
				row: [
					'id' => (string)$row['id'],
					'sourceSecretId' => (string)$row['source_secret_id'],
					'targetUserId' => (string)$row['target_user_id'],
					'secretId' => (string)$row['secret_id'],
				],
				rowCreatedAt: (string)$row['row_created_at'],
				copyCreatedAt: (string)$row['copy_created_at']
			);
		}

		$result->closeCursor();
	}//end quarantineSuspectRows()

	/**
	 * The filtered candidate query: active (not already quarantined) share rows
	 * joined to their recipient copy, keeping only rows whose copy was created
	 * BEFORE the row. SQL does the filtering, so only a provenance-suspect subset
	 * crosses to PHP — never the whole `share_targets` table. The exact window is
	 * then applied per streamed row (portable datetime math).
	 *
	 * @return IQueryBuilder
	 */
	private function candidateQuery(): IQueryBuilder {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('st.id', 'st.source_secret_id', 'st.target_user_id', 'st.secret_id')
			->selectAlias('st.created_at', 'row_created_at')
			->selectAlias('s.created_at', 'copy_created_at')
			->from(self::TABLE, 'st')
			->innerJoin('st', self::SECRETS_TABLE, 's', $qb->expr()->eq('s.id', 'st.secret_id'))
			->where($qb->expr()->isNull('st.'.self::COL_QUARANTINED_AT))
			->andWhere($qb->expr()->lt('s.created_at', 'st.created_at'));

		return $qb;
	}//end candidateQuery()

	/**
	 * Mark one row quarantined (reversible: clearing quarantined_at restores it).
	 *
	 * @param string $id The share-target row id
	 *
	 * @return void
	 */
	private function quarantineRow(string $id): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->update(self::TABLE)
			->set(key: self::COL_QUARANTINED_AT, value: $qb->createNamedParameter((new DateTime())->format('Y-m-d H:i:s')))
			->set(key: self::COL_QUARANTINE_REASON, value: $qb->createNamedParameter(self::QUARANTINE_REASON))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		$qb->executeStatement();
	}//end quarantineRow()

	/**
	 * Record one quarantined row to the migration output and the log, so admins
	 * can find which rows were held back and why (id, source, target, secret_id,
	 * copy vs row created_at). The secret is never touched.
	 *
	 * @param IOutput                                                                    $output        The migration output
	 * @param array{id:string,sourceSecretId:string,targetUserId:string,secretId:string} $row           The quarantined row
	 * @param string                                                                     $rowCreatedAt  The row's created_at
	 * @param string                                                                     $copyCreatedAt The copy's created_at
	 *
	 * @return void
	 */
	private function reportQuarantine(IOutput $output, array $row, string $rowCreatedAt, string $copyCreatedAt): void {
		$message = 'GHSA-m5p9-6pw7-pf3g: quarantined keepiq_share_targets row '.$row['id']
			.' (source '.$row['sourceSecretId'].', target '.$row['targetUserId']
			.', secret_id '.$row['secretId'].'): copy created_at '.$copyCreatedAt
			.' predates row created_at '.$rowCreatedAt.'; its secret was left untouched and the row is reversible';
		$output->warning($message);
		$this->logger?->warning(
			$message,
			[
				'app' => 'keepiq',
				'id' => $row['id'],
				'source' => $row['sourceSecretId'],
				'target' => $row['targetUserId'],
				'secretId' => $row['secretId'],
				'copyCreatedAt' => $copyCreatedAt,
				'rowCreatedAt' => $rowCreatedAt,
			]
		);
	}//end reportQuarantine()

	/**
	 * Parse a stored datetime into a Unix timestamp, or null when it cannot be
	 * read (so an unparsable value never triggers a quarantine).
	 *
	 * @param string $value The stored datetime
	 *
	 * @return int|null
	 */
	private function toTimestamp(string $value): ?int {
		if ($value === '') {
			return null;
		}

		$parsed = strtotime($value);
		if ($parsed === false) {
			return null;
		}

		return $parsed;
	}//end toTimestamp()

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
