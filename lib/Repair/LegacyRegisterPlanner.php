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
	 * Reads the stored JSON id lists of registers and configurations.
	 *
	 * @var StoredIdList
	 */
	private readonly StoredIdList $idLists;

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
		$this->idLists = new StoredIdList();
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
		$schemas = $this->removableSchemas(tablesBySchema: $this->tablesBy(dataTables: $dataTables, column: 1));
		$sweep = new LegacyRegisterSweep(
			removedSchemas: array_keys($schemas),
			tablesByRegister: $this->tablesBy(dataTables: $dataTables, column: 0)
		);
		$sweep->registers = $this->allRegisters();
		foreach (array_keys($sweep->registers) as $registerId) {
			$this->sweepRegister(sweep: $sweep, registerId: $registerId);
		}

		return [
			'schemas'        => $schemas,
			'registers'      => $sweep->removed,
			'rewrites'       => $sweep->rewrites,
			'configurations' => $this->staleConfigurations(
				keptRegisters: $sweep->keptRegisters(),
				removedSchemas: $sweep->removedSchemas
			),
			'dropTables'     => array_values(
				array_unique(array_merge(array_merge([], ...array_column($schemas, 'tables')), $sweep->removedTables()))
			),
		];
	}//end plan()

	/**
	 * Data table names grouped by their register or schema id.
	 *
	 * @param array<int,array{0:int,1:int,2:string}> $dataTables Every data table
	 * @param int                                    $column     0 for the register id, 1 for the schema id
	 *
	 * @return array<int,array<int,string>> Id => unprefixed table names, in catalogue order
	 */
	private function tablesBy(array $dataTables, int $column): array {
		$tables = [];
		foreach ($dataTables as $dataTable) {
			$tables[$dataTable[$column]][] = $dataTable[2];
		}

		return $tables;
	}//end tablesBy()

	/**
	 * The Keepiq schemas that hold no rows, each with its data tables.
	 *
	 * @param array<int,array<int,string>> $tablesBySchema Schema id => its data tables
	 *
	 * @return array<int,array<string,mixed>> Schema id => schema row plus `tables`
	 */
	private function removableSchemas(array $tablesBySchema): array {
		$schemas = [];
		foreach ($this->rowsByApplication(table: 'openregister_schemas', columns: ['id', 'slug', 'application']) as $schema) {
			$tables = $tablesBySchema[(int)$schema['id']] ?? [];
			if ($this->schemaHeld(schema: $schema, tables: $tables) === false) {
				$schemas[(int)$schema['id']] = $schema + ['tables' => $tables];
			}
		}

		return $schemas;
	}//end removableSchemas()

	/**
	 * Whether a schema still holds rows; logs the schema it keeps.
	 *
	 * @param array<string,mixed> $schema The schema row
	 * @param string[]            $tables Its data tables
	 *
	 * @return bool True when the schema is kept
	 */
	private function schemaHeld(array $schema, array $tables): bool {
		$schemaId = (int)$schema['id'];
		$rows = $this->countObjects(column: 'schema', id: $schemaId) + $this->sumRows(tables: $tables);
		if ($rows > 0) {
			$this->logger->warning(
				'RemoveLegacyRegisterRows: schema "{slug}" ({id}) still holds {count} row(s); kept.',
				['slug' => (string)$schema['slug'], 'id' => $schemaId, 'count' => $rows]
			);
			return true;
		}

		return false;
	}//end schemaHeld()

	/**
	 * Decide one register: remove it, rewrite its list, or leave it.
	 *
	 * An empty Keepiq register goes when it holds no rows; any register loses
	 * the entries of the schemas this plan removes.
	 *
	 * @param LegacyRegisterSweep $sweep      The sweep in progress
	 * @param int                 $registerId The register id
	 *
	 * @return void
	 */
	private function sweepRegister(LegacyRegisterSweep $sweep, int $registerId): void {
		$register = $sweep->registers[$registerId];
		$remaining = $this->idLists->without(list: $register['list'], ids: $this->droppedSchemaIds(sweep: $sweep, register: $register));
		if ($this->isKeepiq(register: $register) === true && $remaining === []
			&& $this->registerHeld(sweep: $sweep, registerId: $registerId) === false
		) {
			$sweep->removed[$registerId] = $register;
			return;
		}

		if (count($remaining) !== count($register['list'])) {
			$sweep->rewrites[$registerId] = $remaining;
		}
	}//end sweepRegister()

	/**
	 * The schema ids a register's list loses.
	 *
	 * A Keepiq register also loses entries whose schema row no longer exists,
	 * so an interrupted earlier run still converges.
	 *
	 * @param LegacyRegisterSweep  $sweep    The sweep in progress
	 * @param array<string,mixed>  $register The register
	 *
	 * @return int[]
	 */
	private function droppedSchemaIds(LegacyRegisterSweep $sweep, array $register): array {
		if ($this->isKeepiq(register: $register) === true) {
			return array_merge($sweep->removedSchemas, $this->missingSchemaIds(ids: $register['ids']));
		}

		return $sweep->removedSchemas;
	}//end droppedSchemaIds()

	/**
	 * Whether a register was written under a Keepiq application id.
	 *
	 * @param array<string,mixed> $register The register
	 *
	 * @return bool
	 */
	private function isKeepiq(array $register): bool {
		return in_array($register['application'], self::APP_IDS, true);
	}//end isKeepiq()

	/**
	 * Whether an emptied Keepiq register still holds rows; logs the register it keeps.
	 *
	 * @param LegacyRegisterSweep $sweep      The sweep in progress
	 * @param int                 $registerId The register id
	 *
	 * @return bool True when the register is kept
	 */
	private function registerHeld(LegacyRegisterSweep $sweep, int $registerId): bool {
		$rows = $this->countObjects(column: 'register', id: $registerId) + $this->sumRows(tables: $sweep->tablesOf(registerId: $registerId));
		if ($rows === 0) {
			return false;
		}

		$this->logger->warning(
			'RemoveLegacyRegisterRows: register "{slug}" ({id}) still holds {count} row(s); kept.',
			['slug' => $sweep->registers[$registerId]['slug'], 'id' => $registerId, 'count' => $rows]
		);
		return true;
	}//end registerHeld()

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
			$list = $this->idLists->decode(stored: $row['schemas'] ?? null);
			$application = null;
			if (($row['application'] ?? null) !== null) {
				$application = (string)$row['application'];
			}

			$registers[(int)$row['id']] = [
				'slug'        => (string)$row['slug'],
				'application' => $application,
				'list'        => $list,
				'ids'         => $this->idLists->numericIds(list: $list),
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
			$registers = $this->idLists->decode(stored: $row['registers'] ?? null);
			$schemas = $this->idLists->decode(stored: $row['schemas'] ?? null);
			$registerIds = $this->idLists->numericIds(list: $registers);
			$schemaIds = $this->idLists->numericIds(list: $schemas);
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
}//end class
