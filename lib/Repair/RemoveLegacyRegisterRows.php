<?php

/**
 * Keepiq Remove Legacy Register Rows Repair Step
 *
 * Earlier Keepiq versions imported an empty register scaffold into
 * OpenRegister: a register (slug `keepiq`, or `doriath` before the rename),
 * an `example` schema keyed to the app, and OpenRegister's per-schema data
 * table for it. Keepiq imports nothing into OpenRegister any more
 * (ADR-006), so nothing reads those rows. This step removes them, but only
 * when they hold no object.
 *
 * It reaches OpenRegister's tables through IDBConnection only and references
 * no OpenRegister class, so it runs, and does nothing, on an instance where
 * OpenRegister was never installed.
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
	 * The application ids Keepiq's schemas were written under.
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
			$removed = [];
			foreach ($this->legacySchemas() as $schema) {
				if ($this->removeSchemaWhenEmpty(schema: $schema, registers: $registers) === true) {
					$removed[] = (int)$schema['id'];
				}
			}

			$deletedRegisters = $this->tidyRegisters(registers: $registers, removed: $removed);
			$output->info(
				sprintf(
					'RemoveLegacyRegisterRows: %d schema(s) and %d register(s) removed.',
					count($removed),
					$deletedRegisters
				)
			);
		} catch (Throwable $e) {
			$this->logger->warning('RemoveLegacyRegisterRows failed; leftovers kept.', ['exception' => $e]);
			$output->warning('RemoveLegacyRegisterRows: could not remove the legacy rows; see the log.');
		}//end try
	}//end run()

	/**
	 * Every register, as id => {slug, schemas}.
	 *
	 * @return array<int,array{slug:string,schemas:int[]}>
	 */
	private function registers(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'slug', 'schemas')->from('openregister_registers');
		$result = $qb->executeQuery();
		$registers = [];
		while (($row = $result->fetch()) !== false) {
			$schemas = json_decode((string)($row['schemas'] ?? '[]'), true);
			if (is_array($schemas) === false) {
				$schemas = [];
			}

			$registers[(int)$row['id']] = [
				'slug'    => (string)$row['slug'],
				'schemas' => array_map('intval', $schemas),
			];
		}

		$result->closeCursor();
		return $registers;
	}//end registers()

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
	 * Delete one schema and its data tables, when no object is stored for it.
	 *
	 * @param array{id:int|string,slug:string}                $schema    The schema row
	 * @param array<int,array{slug:string,schemas:int[]}> $registers Every register
	 *
	 * @return bool Whether the schema was removed
	 */
	private function removeSchemaWhenEmpty(array $schema, array $registers): bool {
		$schemaId = (int)$schema['id'];
		$tables = $this->dataTables(schemaId: $schemaId, registers: $registers);

		$objects = $this->countObjects(schemaId: $schemaId);
		foreach ($tables as $table) {
			$objects += $this->countRows(table: $table);
		}

		if ($objects > 0) {
			$this->logger->warning(
				'RemoveLegacyRegisterRows: schema "{slug}" still holds {count} object(s); kept.',
				['slug' => (string)$schema['slug'], 'count' => $objects]
			);
			return false;
		}

		foreach ($tables as $table) {
			$this->db->dropTable($table);
		}

		$qb = $this->db->getQueryBuilder();
		$qb->delete('openregister_schemas')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($schemaId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		return true;
	}//end removeSchemaWhenEmpty()

	/**
	 * The existing per-schema data tables of a schema, one per register that
	 * lists it.
	 *
	 * @param int                                             $schemaId  The schema id
	 * @param array<int,array{slug:string,schemas:int[]}> $registers Every register
	 *
	 * @return string[] Unprefixed table names
	 */
	private function dataTables(int $schemaId, array $registers): array {
		$tables = [];
		foreach ($registers as $registerId => $register) {
			$table = self::DATA_TABLE_PREFIX . $registerId . '_' . $schemaId;
			if (in_array($schemaId, $register['schemas'], true) === true && $this->db->tableExists($table) === true) {
				$tables[] = $table;
			}
		}

		return $tables;
	}//end dataTables()

	/**
	 * Objects stored for a schema in OpenRegister's shared object table.
	 *
	 * @param int $schemaId The schema id
	 *
	 * @return int The number of objects
	 */
	private function countObjects(int $schemaId): int {
		if ($this->db->tableExists('openregister_objects') === false) {
			return 0;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total'))
			->from('openregister_objects')
			->where($qb->expr()->eq('schema', $qb->createNamedParameter((string)$schemaId)));
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
	 * Drop removed schemas from every register's list, and delete the Keepiq
	 * registers that are left empty.
	 *
	 * A Keepiq register that still lists a schema this step did not remove is
	 * kept, because something else is in it.
	 *
	 * @param array<int,array{slug:string,schemas:int[]}> $registers Every register
	 * @param int[]                                       $removed   Removed schema ids
	 *
	 * @return int The number of registers deleted
	 */
	private function tidyRegisters(array $registers, array $removed): int {
		$deleted = 0;
		foreach ($registers as $registerId => $register) {
			$remaining = array_values(array_diff($register['schemas'], $removed));
			if (in_array($register['slug'], self::REGISTER_SLUGS, true) === true && $remaining === []) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete('openregister_registers')
					->where($qb->expr()->eq('id', $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_INT)));
				$qb->executeStatement();
				$deleted++;
				continue;
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
}//end class
