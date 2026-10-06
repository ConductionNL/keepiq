<?php

/**
 * Keepiq Legacy Register Planner
 *
 * Decides, reading only, which of the rows earlier Keepiq versions left in
 * OpenRegister may be removed, for {@see RemoveLegacyRegisterRows}. The
 * safety rules are documented there; this class applies them.
 *
 * @category Repair
 * @package  OCA\Keepiq\Repair
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

namespace OCA\Keepiq\Repair;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Read-only planner for the legacy OpenRegister cleanup.
 *
 * @spec openspec/specs/app-shell/spec.md#requirement-legacy-openregister-rows-are-removed-when-empty
 */
class LegacyRegisterPlanner {
	/**
	 * The application ids Keepiq wrote its schemas, registers and
	 * configurations under.
	 *
	 * @var string[]
	 */
	public const APP_IDS = ['keepiq', 'doriath'];

	/**
	 * OpenRegister's per-schema data table name, `<prefix><register>_<schema>`.
	 *
	 * @var string
	 */
	public const DATA_TABLE_PREFIX = 'openregister_table_';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection   $db     Database connection
	 * @param IConfig         $config System config (table prefix)
	 * @param LoggerInterface $logger Logger
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly IConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Every OpenRegister data table, as [registerId, schemaId, table].
	 *
	 * Listed from the database catalogue by name, the way OpenRegister itself
	 * finds them.
	 *
	 * @return array<int,array{0:int,1:int,2:string}>
	 *
	 * @throws RuntimeException When this database cannot list its tables
	 *
	 * @spec openspec/specs/app-shell/spec.md#requirement-legacy-openregister-rows-are-removed-when-empty
	 */
	public function dataTables(): array {
		$prefix = $this->config->getSystemValueString('dbtableprefix', 'oc_');
		$like = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $prefix . self::DATA_TABLE_PREFIX) . '%';
		$sql = match ($this->db->getDatabaseProvider()) {
			IDBConnection::PLATFORM_POSTGRES => 'SELECT table_name AS name FROM information_schema.tables'
				. ' WHERE table_schema = current_schema() AND table_name LIKE ?',
			IDBConnection::PLATFORM_MYSQL, IDBConnection::PLATFORM_MARIADB => 'SELECT table_name AS name FROM information_schema.tables'
				. ' WHERE table_schema = DATABASE() AND table_name LIKE ?',
			IDBConnection::PLATFORM_SQLITE => "SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE ? ESCAPE '\\'",
			default => throw new RuntimeException('cannot list OpenRegister data tables on this database'),
		};

		$result = $this->db->executeQuery($sql, [$like]);
		$tables = [];
		$pattern = '/^' . preg_quote($prefix . self::DATA_TABLE_PREFIX, '/') . '(\d+)_(\d+)$/';
		while (($row = $result->fetch()) !== false) {
			$name = (string)($row['name'] ?? $row['NAME'] ?? '');
			if (preg_match($pattern, $name, $matches) === 1) {
				$tables[] = [(int)$matches[1], (int)$matches[2], substr($name, strlen($prefix))];
			}
		}

		$result->closeCursor();
		return $tables;
	}//end dataTables()

	/**
	 * Decide what to remove, reading only.
	 *
	 * @param array<int,array{0:int,1:int,2:string}> $dataTables Every data table
	 *
	 * @return array<string,array> Keys `schemas`, `registers`, `rewrites`
	 *                            (register id => remaining list),
	 *                            `configurations` and `dropTables`
	 *
	 * @spec openspec/specs/app-shell/spec.md#requirement-legacy-openregister-rows-are-removed-when-empty
	 */
	public function plan(array $dataTables): array {
		$plan = ['schemas' => [], 'registers' => [], 'rewrites' => [], 'configurations' => [], 'dropTables' => []];

		foreach ($this->rowsByApplication(table: 'openregister_schemas', columns: ['id', 'slug', 'application']) as $schema) {
			$schemaId = (int)$schema['id'];
			$tables = array_column(array_filter($dataTables, static fn (array $t): bool => $t[1] === $schemaId), 2);
			$rows = $this->countObjects(column: 'schema', id: $schemaId) + $this->sumRows(tables: $tables);
			if ($rows > 0) {
				$this->logger->warning(
					'RemoveLegacyRegisterRows: schema "{slug}" ({id}) still holds {count} row(s); kept.',
					['slug' => (string)$schema['slug'], 'id' => $schemaId, 'count' => $rows]
				);
				continue;
			}

			$plan['schemas'][$schemaId] = $schema + ['tables' => $tables];
			$plan['dropTables'] = array_merge($plan['dropTables'], $tables);
		}

		$removedSchemas = array_keys($plan['schemas']);
		$registers = $this->allRegisters();
		foreach ($registers as $registerId => $register) {
			$isKeepiq = in_array($register['application'], self::APP_IDS, true);
			// A Keepiq register also loses entries whose schema row no longer
			// exists, so an interrupted earlier run still converges.
			$drop = $removedSchemas;
			if ($isKeepiq === true) {
				$drop = array_merge($drop, $this->missingSchemaIds(ids: $register['ids']));
			}

			$remaining = $this->withoutIds(list: $register['list'], ids: $drop);
			if ($isKeepiq === true && $remaining === []) {
				$tables = array_column(array_filter($dataTables, static fn (array $t): bool => $t[0] === $registerId), 2);
				$rows = $this->countObjects(column: 'register', id: $registerId) + $this->sumRows(tables: $tables);
				if ($rows === 0) {
					$plan['registers'][$registerId] = $register;
					$plan['dropTables'] = array_merge($plan['dropTables'], $tables);
					continue;
				}

				$this->logger->warning(
					'RemoveLegacyRegisterRows: register "{slug}" ({id}) still holds {count} row(s); kept.',
					['slug' => $register['slug'], 'id' => $registerId, 'count' => $rows]
				);
			}

			if (count($remaining) !== count($register['list'])) {
				$plan['rewrites'][$registerId] = $remaining;
			}
		}//end foreach

		$plan['configurations'] = $this->staleConfigurations(
			keptRegisters: array_diff(array_keys($registers), array_keys($plan['registers'])),
			removedSchemas: $removedSchemas
		);
		$plan['dropTables'] = array_values(array_unique($plan['dropTables']));

		return $plan;
	}//end plan()

	/**
	 * Rows of an OpenRegister table whose `application` is a Keepiq id.
	 *
	 * @param string   $table   Unprefixed table name
	 * @param string[] $columns The columns to read
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function rowsByApplication(string $table, array $columns): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select(...$columns)
			->from($table)
			->where($qb->expr()->in('application', $qb->createNamedParameter(self::APP_IDS, IQueryBuilder::PARAM_STR_ARRAY)));
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
	}//end rowsByApplication()

	/**
	 * Every register, as id => {slug, application, list as stored, numeric ids}.
	 *
	 * @return array<int,array{slug:string,application:string|null,list:array<int,mixed>,ids:int[]}>
	 */
	private function allRegisters(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'slug', 'application', 'schemas')->from('openregister_registers');
		$result = $qb->executeQuery();
		$registers = [];
		while (($row = $result->fetch()) !== false) {
			$list = $this->decodeList(stored: $row['schemas'] ?? null);
			$application = null;
			if (($row['application'] ?? null) !== null) {
				$application = (string)$row['application'];
			}

			$registers[(int)$row['id']] = [
				'slug'        => (string)$row['slug'],
				'application' => $application,
				'list'        => $list,
				'ids'         => $this->numericIds(list: $list),
			];
		}

		$result->closeCursor();
		return $registers;
	}//end allRegisters()

	/**
	 * The Keepiq configuration entries that point at nothing any more.
	 *
	 * An entry is stale when none of the registers it lists is kept and none
	 * of the schemas it lists still exists or is kept, whether this run or an
	 * earlier one removed them. A non-numeric entry cannot be resolved here,
	 * so it counts as existing and keeps the entry.
	 *
	 * @param int[] $keptRegisters  The ids of the registers that remain
	 * @param int[] $removedSchemas The ids of the schemas this run removes
	 *
	 * @return array<int,array<string,mixed>> Stale configurations by id
	 */
	private function staleConfigurations(array $keptRegisters, array $removedSchemas): array {
		if ($this->db->tableExists('openregister_configurations') === false) {
			return [];
		}

		$stale = [];
		foreach ($this->configurationsByApp() as $row) {
			$registers = $this->decodeList(stored: $row['registers'] ?? null);
			$schemas = $this->decodeList(stored: $row['schemas'] ?? null);
			$registerIds = $this->numericIds(list: $registers);
			$schemaIds = $this->numericIds(list: $schemas);
			$liveSchemas = array_diff($schemaIds, $this->missingSchemaIds(ids: $schemaIds), $removedSchemas);
			if (count($registerIds) !== count($registers)
				|| count($schemaIds) !== count($schemas)
				|| array_intersect($registerIds, $keptRegisters) !== []
				|| $liveSchemas !== []
			) {
				continue;
			}

			$stale[(int)$row['id']] = $row;
		}

		return $stale;
	}//end staleConfigurations()

	/**
	 * OpenRegister configuration entries of a Keepiq app id (column `app`).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function configurationsByApp(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'app', 'registers', 'schemas')
			->from('openregister_configurations')
			->where($qb->expr()->in('app', $qb->createNamedParameter(self::APP_IDS, IQueryBuilder::PARAM_STR_ARRAY)));
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
	}//end configurationsByApp()

	/**
	 * The given schema ids that have no schema row.
	 *
	 * @param int[] $ids Schema ids
	 *
	 * @return int[] The ids without a row
	 */
	private function missingSchemaIds(array $ids): array {
		if ($ids === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('openregister_schemas')
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
		$result = $qb->executeQuery();
		$existing = array_map('intval', array_column($result->fetchAll(), 'id'));
		$result->closeCursor();
		return array_values(array_diff($ids, $existing));
	}//end missingSchemaIds()

	/**
	 * Rows for one register or schema in the legacy shared object table, which
	 * OpenRegister drops once its blob migration has completed.
	 *
	 * @param string $column `schema` or `register`
	 * @param int    $id     The register or schema id
	 *
	 * @return int The number of rows
	 */
	private function countObjects(string $column, int $id): int {
		if ($this->db->tableExists('openregister_objects') === false) {
			return 0;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total'))
			->from('openregister_objects')
			->where($qb->expr()->eq($column, $qb->createNamedParameter((string)$id)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}//end countObjects()

	/**
	 * Total rows across tables.
	 *
	 * @param string[] $tables Unprefixed table names
	 *
	 * @return int The number of rows
	 */
	private function sumRows(array $tables): int {
		$total = 0;
		foreach ($tables as $table) {
			$total += $this->countRows(table: $table);
		}

		return $total;
	}//end sumRows()

	/**
	 * Rows in one table.
	 *
	 * @param string $table Unprefixed table name
	 *
	 * @return int The number of rows
	 *
	 * @spec openspec/specs/app-shell/spec.md#requirement-legacy-openregister-rows-are-removed-when-empty
	 */
	public function countRows(string $table): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total'))->from($table);
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}//end countRows()

	/**
	 * Decode a stored JSON id list.
	 *
	 * @param mixed $stored The stored value, or null
	 *
	 * @return array<int,mixed> The list, as stored
	 */
	private function decodeList(mixed $stored): array {
		$list = json_decode((string)($stored ?? '[]'), true);
		if (is_array($list) === false) {
			return [];
		}

		return array_values($list);
	}//end decodeList()

	/**
	 * The numeric ids in a list.
	 *
	 * @param array<int,mixed> $list A stored id list
	 *
	 * @return int[] The numeric entries, as ints
	 */
	private function numericIds(array $list): array {
		$ids = [];
		foreach ($list as $entry) {
			if ($this->isNumericId(entry: $entry) === true) {
				$ids[] = (int)$entry;
			}
		}

		return $ids;
	}//end numericIds()

	/**
	 * Whether a list entry is a numeric id.
	 *
	 * @param mixed $entry A list entry
	 *
	 * @return bool True for an int or a digit string
	 */
	private function isNumericId(mixed $entry): bool {
		return is_int($entry) === true || (is_string($entry) === true && ctype_digit($entry) === true);
	}//end isNumericId()

	/**
	 * A stored id list without the given numeric ids; other entries untouched.
	 *
	 * @param array<int,mixed> $list The stored list
	 * @param int[]            $ids  The ids to remove
	 *
	 * @return array<int,mixed> The remaining entries, re-indexed
	 */
	private function withoutIds(array $list, array $ids): array {
		return array_values(
			array_filter(
				$list,
				fn (mixed $entry): bool => $this->isNumericId(entry: $entry) === false
					|| in_array((int)$entry, $ids, true) === false
			)
		);
	}//end withoutIds()
}//end class
