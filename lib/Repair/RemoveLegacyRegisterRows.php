<?php

/**
 * Keepiq Remove Legacy Register Rows Repair Step
 *
 * Earlier Keepiq versions imported an empty register scaffold into
 * OpenRegister: a configuration entry, a register (slug `keepiq`, or
 * `doriath` before the rename), an `example` schema keyed to the app, and
 * OpenRegister's per-schema data table for it. Keepiq imports nothing into
 * OpenRegister any more (ADR-006), so nothing reads those rows. This step
 * removes them, but only when nothing is stored in them.
 *
 * It reaches OpenRegister's tables through IDBConnection only and references
 * no OpenRegister class, so it runs, and does nothing, on an instance where
 * OpenRegister was never installed.
 *
 * Safety rules, each measured against OpenRegister's own storage (applied by
 * {@see LegacyRegisterPlanner}):
 *
 * - Rows are selected by `application`, never by slug alone: OpenRegister's
 *   uniqueness key is (organisation, application, slug), so another app or
 *   tenant may own a `keepiq` slug. A register without an application is
 *   never deleted.
 * - Data tables are found by their physical name
 *   (`openregister_table_<register>_<schema>`), as OpenRegister's own schema
 *   deletion does, not from a register's `schemas` list, which can lose
 *   entries. A schema or register counts as empty only when every one of its
 *   data tables, and the legacy `openregister_objects` table if it still
 *   exists, holds no row for it.
 * - When the data tables cannot be listed, nothing is removed.
 * - All row changes run in one transaction; the empty data tables are dropped
 *   after the commit (a DROP TABLE commits implicitly on MySQL), each after a
 *   last row count, so a failure never leaves a schema pointing at nothing.
 * - Every removal is logged with what it removed and why.
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
	 * The read-only planner.
	 *
	 * @var LegacyRegisterPlanner
	 */
	private LegacyRegisterPlanner $planner;

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
		IConfig $config,
		private readonly LoggerInterface $logger,
	) {
		$this->planner = new LegacyRegisterPlanner(db: $db, config: $config, logger: $logger);
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

			$plan = $this->planner->plan(dataTables: $this->planner->dataTables());
			$this->apply(plan: $plan);

			// After the commit: a failure from here on leaves only an empty orphan table.
			foreach ($plan['dropTables'] as $table) {
				$this->dropIfEmpty(table: $table);
			}

			$output->info(
				sprintf(
					'RemoveLegacyRegisterRows: %d schema(s), %d register(s) and %d configuration(s) removed.',
					count($plan['schemas']),
					count($plan['registers']),
					count($plan['configurations'])
				)
			);
		} catch (Throwable $e) {
			$this->logger->warning('RemoveLegacyRegisterRows failed; leftovers kept.', ['exception' => $e]);
			$output->warning('RemoveLegacyRegisterRows: could not remove the legacy rows; see the log.');
		}//end try
	}//end run()

	/**
	 * Apply the row changes of a plan in one transaction.
	 *
	 * @param array<string,array> $plan The plan from LegacyRegisterPlanner::plan()
	 *
	 * @return void
	 *
	 * @throws Throwable When a change fails; the transaction is rolled back first
	 */
	private function apply(array $plan): void {
		if ($plan['schemas'] === [] && $plan['registers'] === [] && $plan['rewrites'] === [] && $plan['configurations'] === []) {
			return;
		}

		$this->db->beginTransaction();
		try {
			$this->removeSchemas(schemas: $plan['schemas']);
			$this->removeRegisters(registers: $plan['registers']);
			$this->rewriteRegisterLists(rewrites: $plan['rewrites']);
			$this->removeConfigurations(configurations: $plan['configurations']);
			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}//end apply()

	/**
	 * Delete schema rows, logging each first.
	 *
	 * @param array<int,array<string,mixed>> $schemas Schema rows by id, with their `tables`
	 *
	 * @return void
	 */
	private function removeSchemas(array $schemas): void {
		foreach ($schemas as $schemaId => $schema) {
			$tables = 'no data table';
			if ($schema['tables'] !== []) {
				$tables = implode(', ', $schema['tables']);
			}

			$this->logger->info(
				'RemoveLegacyRegisterRows: removing schema "{slug}" ({id}, application {application}); 0 rows in {tables}.',
				['slug' => (string)$schema['slug'], 'id' => $schemaId, 'application' => (string)$schema['application'], 'tables' => $tables]
			);
			$this->deleteById(table: 'openregister_schemas', id: $schemaId);
		}
	}//end removeSchemas()

	/**
	 * Delete register rows, logging each first.
	 *
	 * @param array<int,array<string,mixed>> $registers Register rows by id
	 *
	 * @return void
	 */
	private function removeRegisters(array $registers): void {
		foreach ($registers as $registerId => $register) {
			$this->logger->info(
				'RemoveLegacyRegisterRows: removing register "{slug}" ({id}, application {application}); it lists no schema and holds no row.',
				['slug' => $register['slug'], 'id' => $registerId, 'application' => (string)$register['application']]
			);
			$this->deleteById(table: 'openregister_registers', id: $registerId);
		}
	}//end removeRegisters()

	/**
	 * Store the shortened schema lists of registers that listed removed schemas.
	 *
	 * @param array<int,array<int,mixed>> $rewrites Register id => remaining list
	 *
	 * @return void
	 */
	private function rewriteRegisterLists(array $rewrites): void {
		foreach ($rewrites as $registerId => $remaining) {
			$this->logger->info(
				'RemoveLegacyRegisterRows: removing deleted Keepiq schemas from the list of register {id}.',
				['id' => $registerId]
			);
			$qb = $this->db->getQueryBuilder();
			$qb->update('openregister_registers')
				->set('schemas', $qb->createNamedParameter(json_encode($remaining)))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_INT)));
			$qb->executeStatement();
		}
	}//end rewriteRegisterLists()

	/**
	 * Delete configuration rows, logging each first.
	 *
	 * @param array<int,array<string,mixed>> $configurations Configuration rows by id
	 *
	 * @return void
	 */
	private function removeConfigurations(array $configurations): void {
		foreach ($configurations as $configurationId => $configuration) {
			$this->logger->info(
				'RemoveLegacyRegisterRows: removing configuration {id} (app {app}); nothing it lists exists any more.',
				['id' => $configurationId, 'app' => (string)$configuration['app']]
			);
			$this->deleteById(table: 'openregister_configurations', id: $configurationId);
		}
	}//end removeConfigurations()

	/**
	 * Drop a data table after a last check that it is still empty.
	 *
	 * @param string $table Unprefixed table name
	 *
	 * @return void
	 */
	private function dropIfEmpty(string $table): void {
		$rows = $this->planner->countRows(table: $table);
		if ($rows > 0) {
			$this->logger->warning(
				'RemoveLegacyRegisterRows: table {table} received {count} row(s) meanwhile; kept.',
				['table' => $table, 'count' => $rows]
			);
			return;
		}

		$this->logger->info('RemoveLegacyRegisterRows: dropping empty table {table}.', ['table' => $table]);
		$this->db->dropTable($table);
	}//end dropIfEmpty()

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
