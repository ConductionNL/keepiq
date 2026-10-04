<?php

/**
 * Keepiq Backup Table Store
 *
 * The database side of a backup and a restore, table by table: stream rows
 * out, count them, and replace a table's rows (admin-scheduled-vault-backups
 * D1 and D4). Kept apart from the archive logic so both can be tested
 * without a database.
 *
 * @category Backup
 * @package  OCA\Keepiq\Backup
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

namespace OCA\Keepiq\Backup;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Reads and replaces Keepiq table rows.
 */
class TableStore {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private IDBConnection $db,
	) {
	}//end __construct()

	/**
	 * Stream the stored rows of a table.
	 *
	 * @param string $table The unprefixed table
	 *
	 * @return iterable<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/admin-scheduled-vault-backups/tasks.md#1.2
	 */
	public function rows(string $table): iterable {
		$result = $this->db->getQueryBuilder()->select('*')->from(BackupTableRegistry::PREFIX . $table)->executeQuery();
		while (($row = $result->fetch()) !== false) {
			yield $row;
		}

		$result->closeCursor();
	}//end rows()

	/**
	 * The number of rows in a table.
	 *
	 * @param string $table The unprefixed table
	 *
	 * @return int
	 *
	 * @spec openspec/changes/admin-scheduled-vault-backups/tasks.md#3.3
	 */
	public function count(string $table): int {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select($qb->func()->count('*', 'row_count'))->from(BackupTableRegistry::PREFIX . $table)->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}//end count()

	/**
	 * The newest audit entry time, or null for an empty log.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/admin-scheduled-vault-backups/tasks.md#3.3
	 */
	public function newestAuditEntry(): ?string {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select($qb->func()->max('occurred_at'))->from(BackupTableRegistry::PREFIX . 'audit_log')->executeQuery();
		$value = $result->fetchOne();
		$result->closeCursor();

		if ($value === false || $value === null) {
			return null;
		}

		return (string)$value;
	}//end newestAuditEntry()

	/**
	 * Replace all rows of every table inside ONE transaction: either every
	 * table holds the archive rows afterwards, or none changed.
	 *
	 * @param callable(string):iterable<int,array<string,mixed>> $rowsFor Rows per unprefixed table
	 *
	 * @return array<string,int> Rows written per table
	 *
	 * @spec openspec/changes/admin-scheduled-vault-backups/tasks.md#3.3
	 */
	public function replaceAll(callable $rowsFor): array {
		$written = [];
		$this->db->beginTransaction();
		try {
			foreach (BackupTableRegistry::TABLES as $table) {
				$this->db->getQueryBuilder()->delete(BackupTableRegistry::PREFIX . $table)->executeStatement();
				$written[$table] = 0;
				foreach ($rowsFor($table) as $row) {
					$qb = $this->db->getQueryBuilder();
					$values = [];
					foreach ($row as $column => $value) {
						$values[(string)$column] = $qb->createNamedParameter($value, self::parameterType(value: $value));
					}

					$qb->insert(BackupTableRegistry::PREFIX . $table)->values($values)->executeStatement();
					$written[$table]++;
				}
			}

			$this->db->commit();
		} catch (\Throwable $exception) {
			$this->db->rollBack();
			throw $exception;
		}//end try

		$this->resetSequences();

		return $written;
	}//end replaceAll()

	/**
	 * The binding type for one archived value.
	 *
	 * PostgreSQL returns boolean columns as PHP booleans and the archive keeps
	 * them. Bound as a string, `false` reaches PostgreSQL as '' and the insert
	 * is refused, so each value is bound with the type it carries.
	 *
	 * @param mixed $value The archived value
	 *
	 * @return mixed The IQueryBuilder parameter type
	 *
	 * @spec openspec/changes/admin-scheduled-vault-backups/tasks.md#4.2
	 */
	private static function parameterType(mixed $value): mixed {
		if (is_bool($value) === true) {
			return IQueryBuilder::PARAM_BOOL;
		}

		if (is_int($value) === true) {
			return IQueryBuilder::PARAM_INT;
		}

		if ($value === null) {
			return IQueryBuilder::PARAM_NULL;
		}

		return IQueryBuilder::PARAM_STR;
	}//end parameterType()

	/**
	 * On PostgreSQL an explicit id insert leaves the sequence behind; move it
	 * past the restored maximum so the next insert does not collide.
	 *
	 * @return void
	 */
	private function resetSequences(): void {
		if ($this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_POSTGRES) {
			return;
		}

		foreach (BackupTableRegistry::AUTOINCREMENT as $table) {
			$name = '*PREFIX*' . BackupTableRegistry::PREFIX . $table;
			$this->db->executeQuery(
				"SELECT setval(pg_get_serial_sequence('" . $name . "', 'id'), COALESCE((SELECT MAX(id) FROM " . $name . '), 0) + 1, false)'
			);
		}
	}//end resetSequences()
}//end class
