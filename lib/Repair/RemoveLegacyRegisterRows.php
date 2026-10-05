<?php

/**
 * Keepiq Remove Legacy Register Rows Repair Step
 *
 * Earlier Keepiq versions imported an empty register scaffold into
 * OpenRegister: a configuration entry, a register (slug `keepiq`, or
 * `doriath` before the rename), an `example` schema keyed to the app, and
 * OpenRegister's per-schema data table for it. Keepiq imports nothing into
 * OpenRegister any more (ADR-006), so nothing reads those rows. This step
 * removes them, but only when they hold no object.
 *
 * It reaches OpenRegister's tables through IDBConnection only and references
 * no OpenRegister class, so it runs, and does nothing, on an instance where
 * OpenRegister was never installed.
 *
 * Order matters for a step that cannot run in one transaction (a DROP TABLE
 * commits implicitly on MySQL): rows are deleted first and empty data tables
 * dropped last, so a failure partway leaves at worst an empty orphan table,
 * never a schema or register that points at nothing.
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
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Removes the empty register scaffold earlier Keepiq versions left in
 * OpenRegister.
 *
 * @spec openspec/specs/app-shell/spec.md#requirement-legacy-openregister-rows-are-removed-when-empty
 */
class RemoveLegacyRegisterRows implements IRepairStep {
	/**
	 * The application ids Keepiq's schemas and configurations were written under.
	 *
	 * @var string[]
	 */
	public const APP_IDS = ['keepiq', 'doriath'];

	/**
	 * The slugs of the registers Keepiq created.
	 *
	 * @var string[]
	 */
	public const REGISTER_SLUGS = ['keepiq', 'doriath'];

	/**
	 * OpenRegister's per-schema data table prefix, `<prefix><register>_<schema>`.
	 *
	 * @var string
	 */
	public const DATA_TABLE_PREFIX = 'openregister_table_';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection   $db     Database connection
	 * @param LoggerInterface $logger Logger
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Step name shown by `occ maintenance:repair`.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/app-shell/spec.md#requirement-legacy-openregister-rows-are-removed-when-empty
	 */
	public function getName(): string {
		return 'Remove the empty register Keepiq used to create in OpenRegister';
	}//end getName()

	/**
	 * Remove the empty leftovers. Never throws.
	 *
	 * @param IOutput $output Repair output
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-shell/spec.md#requirement-legacy-openregister-rows-are-removed-when-empty
	 */
	public function run(IOutput $output): void {
		try {
			if ($this->db->tableExists('openregister_schemas') === false
				|| $this->db->tableExists('openregister_registers') === false
			) {
				$output->info('RemoveLegacyRegisterRows: OpenRegister tables not present; nothing to do.');
				return;
			}

			$registers = $this->registers();
			$removedSchemas = [];
			$emptyTables = [];
			foreach ($this->legacySchemas() as $schema) {
				$tables = $this->removeSchemaWhenEmpty(schema: $schema, registers: $registers);
				if ($tables !== null) {
					$removedSchemas[] = (int)$schema['id'];
					$emptyTables = array_merge($emptyTables, $tables);
				}
			}

			$removedRegisters = $this->tidyRegisters(registers: $registers, removedSchemas: $removedSchemas);
			$configsRemoved = $this->removeConfigurations(
				existingRegisters: array_diff(array_keys($registers), $removedRegisters)
			);

			// Last: a failure from here on leaves only an empty orphan table.
			foreach (array_unique($emptyTables) as $table) {
				$this->db->dropTable($table);
			}

			$output->info(
				sprintf(
					'RemoveLegacyRegisterRows: %d schema(s), %d register(s) and %d configuration(s) removed.',
					count($removedSchemas),
					count($removedRegisters),
					$configsRemoved
				)
			);
		} catch (Throwable $e) {
			$this->logger->warning('RemoveLegacyRegisterRows failed; leftovers kept.', ['exception' => $e]);
			$output->warning('RemoveLegacyRegisterRows: could not remove the legacy rows; see the log.');
		}//end try
	}//end run()

	/**
	 * Every register, as id => {slug, schemas as stored, numeric schema ids}.
	 *
	 * The stored list is kept as decoded so a register that is rewritten keeps
	 * every entry this step does not remove exactly as it was.
	 *
	 * @return array<int,array{slug:string,schemas:array<int,mixed>,ids:int[]}>
	 */
	private function registers(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'slug', 'schemas')->from('openregister_registers');
		$result = $qb->executeQuery();
		$registers = [];
		while (($row = $result->fetch()) !== false) {
			$registers[(int)$row['id']] = $this->listEntry(slug: (string)$row['slug'], stored: $row['schemas'] ?? null);
		}

		$result->closeCursor();
		return $registers;
	}//end registers()

	/**
	 * Decode a stored id list, and the numeric ids in it.
	 *
	 * @param string $slug   The row's slug
	 * @param mixed  $stored The stored JSON list, or null
	 *
	 * @return array{slug:string,schemas:array<int,mixed>,ids:int[]}
	 */
	private function listEntry(string $slug, mixed $stored): array {
		$list = json_decode((string)($stored ?? '[]'), true);
		if (is_array($list) === false) {
			$list = [];
		}

		$list = array_values($list);
		$ids = [];
		foreach ($list as $entry) {
			if (is_int($entry) === true || (is_string($entry) === true && ctype_digit($entry) === true)) {
				$ids[] = (int)$entry;
			}
		}

		return ['slug' => $slug, 'schemas' => $list, 'ids' => $ids];
	}//end listEntry()

	/**
	 * The schema rows keyed to a Keepiq application id.
	 *
	 * Selected by application, never by slug: slugs such as `example` are
	 * shared between apps on one OpenRegister.
	 *
	 * @return array<int,array{id:int|string,slug:string}>
	 */
	private function legacySchemas(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'slug')
			->from('openregister_schemas')
			->where($qb->expr()->in('application', $qb->createNamedParameter(self::APP_IDS, IQueryBuilder::PARAM_STR_ARRAY)));
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
	}//end legacySchemas()

	/**
	 * Delete one schema row when no object is stored for it.
	 *
	 * @param array{id:int|string,slug:string}                                  $schema    The schema row
	 * @param array<int,array{slug:string,schemas:array<int,mixed>,ids:int[]}> $registers Every register
	 *
	 * @return string[]|null The schema's now-empty data tables, to drop last; null when the schema was kept
	 */
	private function removeSchemaWhenEmpty(array $schema, array $registers): ?array {
		$schemaId = (int)$schema['id'];
		$tables = $this->dataTables(schemaId: $schemaId, registers: $registers);

		$objects = $this->countObjects(column: 'schema', id: $schemaId);
		foreach ($tables as $table) {
			$objects += $this->countRows(table: $table);
		}

		if ($objects > 0) {
			$this->logger->warning(
				'RemoveLegacyRegisterRows: schema "{slug}" still holds {count} object(s); kept.',
				['slug' => (string)$schema['slug'], 'count' => $objects]
			);
			return null;
		}

		$this->deleteById(table: 'openregister_schemas', id: $schemaId);
		return $tables;
	}//end removeSchemaWhenEmpty()

	/**
	 * The existing per-schema data tables of a schema, one per register that
	 * lists it.
	 *
	 * @param int                                                              $schemaId  The schema id
	 * @param array<int,array{slug:string,schemas:array<int,mixed>,ids:int[]}> $registers Every register
	 *
	 * @return string[] Unprefixed table names
	 */
	private function dataTables(int $schemaId, array $registers): array {
		$tables = [];
		foreach ($registers as $registerId => $register) {
			$table = self::DATA_TABLE_PREFIX . $registerId . '_' . $schemaId;
			if (in_array($schemaId, $register['ids'], true) === true && $this->db->tableExists($table) === true) {
				$tables[] = $table;
			}
		}

		return $tables;
	}//end dataTables()

	/**
	 * Objects stored in OpenRegister's shared object table for one register
	 * or schema.
	 *
	 * @param string $column `schema` or `register`
	 * @param int    $id     The register or schema id
	 *
	 * @return int The number of objects
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
	 * Rows in one table.
	 *
	 * @param string $table Unprefixed table name
	 *
	 * @return int The number of rows
	 */
	private function countRows(string $table): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total'))->from($table);
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}//end countRows()

	/**
	 * Drop removed schemas from the registers that list them, and delete the
	 * Keepiq registers that are left empty.
	 *
	 * Only registers that list a removed schema are rewritten, and only those
	 * entries leave the list: every other entry stays exactly as stored. A
	 * Keepiq register is kept while it still lists another schema or while
	 * objects are stored under it.
	 *
	 * @param array<int,array{slug:string,schemas:array<int,mixed>,ids:int[]}> $registers      Every register
	 * @param int[]                                                            $removedSchemas Removed schema ids
	 *
	 * @return int[] The ids of the registers deleted
	 */
	private function tidyRegisters(array $registers, array $removedSchemas): array {
		$deleted = [];
		foreach ($registers as $registerId => $register) {
			$remaining = $this->withoutIds(list: $register['schemas'], ids: $removedSchemas);
			$isKeepiq = in_array($register['slug'], self::REGISTER_SLUGS, true);

			if ($isKeepiq === true && $remaining === []) {
				$objects = $this->countObjects(column: 'register', id: $registerId);
				if ($objects === 0) {
					$this->deleteById(table: 'openregister_registers', id: $registerId);
					$deleted[] = $registerId;
					continue;
				}

				$this->logger->warning(
					'RemoveLegacyRegisterRows: register "{slug}" still holds {count} object(s); kept.',
					['slug' => $register['slug'], 'count' => $objects]
				);
			}

			if (count($remaining) !== count($register['schemas'])) {
				$qb = $this->db->getQueryBuilder();
				$qb->update('openregister_registers')
					->set('schemas', $qb->createNamedParameter(json_encode($remaining)))
					->where($qb->expr()->eq('id', $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_INT)));
				$qb->executeStatement();
			}
		}//end foreach

		return $deleted;
	}//end tidyRegisters()

	/**
	 * Delete the Keepiq configuration entries that point at nothing any more.
	 *
	 * An entry qualifies when none of the registers and schemas it lists still
	 * exists, whether this run removed them or an earlier one did, so an
	 * instance that was partly cleaned up before still loses its stale entries.
	 *
	 * @param int[] $existingRegisters The ids of the registers that still exist
	 *
	 * @return int The number of configurations deleted
	 */
	private function removeConfigurations(array $existingRegisters): int {
		if ($this->db->tableExists('openregister_configurations') === false) {
			return 0;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'app', 'registers', 'schemas')
			->from('openregister_configurations')
			->where($qb->expr()->in('app', $qb->createNamedParameter(self::APP_IDS, IQueryBuilder::PARAM_STR_ARRAY)));
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		$deleted = 0;
		foreach ($rows as $row) {
			$registers = $this->listEntry(slug: (string)$row['app'], stored: $row['registers'] ?? null);
			$schemas = $this->listEntry(slug: (string)$row['app'], stored: $row['schemas'] ?? null);
			// A non-numeric entry cannot be resolved here, so it counts as existing.
			if (count($registers['ids']) !== count($registers['schemas'])
				|| count($schemas['ids']) !== count($schemas['schemas'])
				|| array_intersect($registers['ids'], $existingRegisters) !== []
				|| $this->anySchemaExists(ids: $schemas['ids']) === true
			) {
				continue;
			}

			$this->deleteById(table: 'openregister_configurations', id: (int)$row['id']);
			$deleted++;
		}

		return $deleted;
	}//end removeConfigurations()

	/**
	 * Whether any of the given schema ids still has a row.
	 *
	 * @param int[] $ids Schema ids
	 *
	 * @return bool True when at least one exists
	 */
	private function anySchemaExists(array $ids): bool {
		if ($ids === []) {
			return false;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total'))
			->from('openregister_schemas')
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count > 0;
	}//end anySchemaExists()

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
				static fn (mixed $entry): bool => (is_int($entry) === true || (is_string($entry) === true && ctype_digit($entry) === true)) === false
					|| in_array((int)$entry, $ids, true) === false
			)
		);
	}//end withoutIds()

	/**
	 * Delete one row by id.
	 *
	 * @param string $table Unprefixed table name
	 * @param int    $id    The row id
	 *
	 * @return void
	 */
	private function deleteById(string $table, int $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($table)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}//end deleteById()
}//end class
