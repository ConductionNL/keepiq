<?php

/**
 * Unit tests for the legacy OpenRegister row cleanup.
 *
 * The step talks to OpenRegister's tables through IDBConnection. These tests
 * back it with a small in-memory model of current OpenRegister storage:
 * registers, schemas and configurations with their `application`, the
 * per-schema data tables found by name in the database catalogue, no
 * `openregister_objects` table unless a test adds it, and transactions that
 * roll back. Each scenario asserts the resulting table state.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Repair
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

namespace OCA\Keepiq\Tests\Unit\Repair;

use OCA\Keepiq\Repair\RemoveLegacyRegisterRows;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for RemoveLegacyRegisterRows.
 *
 * @covers \OCA\Keepiq\Repair\RemoveLegacyRegisterRows
 * @covers \OCA\Keepiq\Repair\LegacyRegisterPlanner
 */
final class RemoveLegacyRegisterRowsTest extends TestCase {
	/**
	 * Registers, id => [slug, application, schemas as stored].
	 *
	 * @var array<int,array{slug:string,application:string|null,schemas:array<int,mixed>}>
	 */
	private array $registers = [];

	/**
	 * Schemas, id => [slug, application].
	 *
	 * @var array<int,array{slug:string,application:string}>
	 */
	private array $schemas = [];

	/**
	 * Configurations, id => [app, registers, schemas].
	 *
	 * @var array<int,array{app:string,registers:array<int,mixed>,schemas:array<int,mixed>|null}>
	 */
	private array $configurations = [];

	/**
	 * Per-schema data tables, unprefixed name => row count.
	 *
	 * @var array<string,int>
	 */
	private array $dataTables = [];

	/**
	 * Rows of the legacy shared object table as [register, schema], or null
	 * when the table no longer exists (current OpenRegister).
	 *
	 * @var array<int,array{0:int,1:int}>|null
	 */
	private ?array $objects = null;

	/**
	 * Whether OpenRegister's tables exist at all.
	 *
	 * @var bool
	 */
	private bool $openRegisterTables = true;

	/**
	 * The database provider the fake reports.
	 *
	 * @var string
	 */
	private string $provider = IDBConnection::PLATFORM_POSTGRES;

	/**
	 * A write that throws once, as "<kind> <table>", or null.
	 *
	 * @var string|null
	 */
	private ?string $failOnce = null;

	/**
	 * Run after the transaction commits, to simulate concurrent writes.
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $afterCommit = null;

	/**
	 * The state when the transaction began, for rollBack().
	 *
	 * @var array|null
	 */
	private ?array $snapshot = null;

	/**
	 * Every statement that touched an openregister_* table, in order.
	 *
	 * @var string[]
	 */
	private array $touched = [];

	/**
	 * The dev instance's real leftovers (measured 2026-10-05), plus another
	 * app's `example` schema in its own register.
	 *
	 * @return void
	 */
	private function seedDevInstance(): void {
		$this->registers = [
			20 => ['slug' => 'doriath', 'application' => 'doriath', 'schemas' => [66]],
			30 => ['slug' => 'keepiq', 'application' => 'keepiq', 'schemas' => []],
			40 => ['slug' => 'shillinq', 'application' => 'shillinq', 'schemas' => [70]],
		];
		$this->schemas = [
			66 => ['slug' => 'example', 'application' => 'keepiq'],
			70 => ['slug' => 'example', 'application' => 'shillinq'],
		];
		$this->configurations = [
			9 => ['app' => 'doriath', 'registers' => [20], 'schemas' => [66]],
			14 => ['app' => 'keepiq', 'registers' => [30], 'schemas' => null],
			15 => ['app' => 'shillinq', 'registers' => [40], 'schemas' => [70]],
		];
		$this->dataTables = ['openregister_table_20_66' => 0, 'openregister_table_40_70' => 0];
	}//end seedDevInstance()

	/**
	 * The current state, for comparisons.
	 *
	 * @return array
	 */
	private function state(): array {
		return [$this->registers, $this->schemas, $this->configurations, $this->dataTables];
	}//end state()

	/**
	 * Empty Keepiq leftovers are removed; another app's same-slug schema is not.
	 *
	 * @return void
	 */
	public function testRemovesEmptyLeftoversAndNothingElse(): void {
		$this->seedDevInstance();

		$this->step()->run($this->createMock(IOutput::class));

		self::assertSame([40], array_keys($this->registers), 'both Keepiq registers go, shillinq stays');
		self::assertSame([70], array_keys($this->schemas), 'only the keepiq-keyed schema goes');
		self::assertSame([15], array_keys($this->configurations), 'both Keepiq configurations go, shillinq stays');
		self::assertSame(['openregister_table_40_70'], array_keys($this->dataTables), 'the empty Keepiq data table is dropped');
	}//end testRemovesEmptyLeftoversAndNothingElse()

	/**
	 * Rows change inside one transaction; the table is dropped after the commit.
	 *
	 * @return void
	 */
	public function testDropsTheDataTableAfterTheCommit(): void {
		$this->seedDevInstance();

		$this->step()->run($this->createMock(IOutput::class));

		$writes = array_values(array_filter($this->touched, static fn (string $t): bool => str_starts_with($t, 'select') === false));
		self::assertSame('begin', $writes[0]);
		$commit = array_search('commit', $writes, true);
		self::assertIsInt($commit);
		self::assertGreaterThan($commit, array_search('drop openregister_table_20_66', $writes, true));
	}//end testDropsTheDataTableAfterTheCommit()

	/**
	 * Another app's register keeps every entry this step does not remove.
	 *
	 * @return void
	 */
	public function testAForeignRegisterKeepsItsOtherEntriesAsStored(): void {
		$this->seedDevInstance();
		$this->registers[40]['schemas'] = ['00000000-0000-0000-0000-000000000000', 'invoice', 70, 66];

		$this->step()->run($this->createMock(IOutput::class));

		self::assertSame(['00000000-0000-0000-0000-000000000000', 'invoice', 70], $this->registers[40]['schemas']);
	}//end testAForeignRegisterKeepsItsOtherEntriesAsStored()

	/**
	 * A schema with rows, in its data table or the legacy shared table, is kept.
	 *
	 * @return void
	 */
	public function testKeepsASchemaThatHoldsObjects(): void {
		foreach (['legacy shared table' => true, 'data table' => false] as $case => $shared) {
			$this->seedDevInstance();
			$this->objects = null;
			if ($shared === true) {
				$this->objects = [[20, 66], [20, 66]];
			} else {
				$this->dataTables['openregister_table_20_66'] = 3;
			}

			$this->step()->run($this->createMock(IOutput::class));

			self::assertArrayHasKey(66, $this->schemas, $case . ': schema kept');
			self::assertArrayHasKey('openregister_table_20_66', $this->dataTables, $case . ': data table kept');
			self::assertArrayHasKey(20, $this->registers, $case . ': its register kept');
			self::assertSame([66], $this->registers[20]['schemas'], $case . ': register still lists it');
			self::assertArrayHasKey(9, $this->configurations, $case . ': its configuration kept');
		}
	}//end testKeepsASchemaThatHoldsObjects()

	/**
	 * (a) A data table is found by name even when its register no longer lists
	 * the schema, so a schema with rows there is kept.
	 *
	 * @return void
	 */
	public function testFindsDataTablesTheRegisterNoLongerLists(): void {
		$this->seedDevInstance();
		$this->registers[20]['schemas'] = [];
		$this->dataTables['openregister_table_20_66'] = 5;

		$this->step()->run($this->createMock(IOutput::class));

		self::assertArrayHasKey(66, $this->schemas);
		self::assertSame(5, $this->dataTables['openregister_table_20_66']);
		self::assertArrayHasKey(20, $this->registers, 'its register holds rows, so it stays');
	}//end testFindsDataTablesTheRegisterNoLongerLists()

	/**
	 * (b) A Keepiq register with an empty list but populated data tables is kept.
	 *
	 * @return void
	 */
	public function testKeepsAKeepiqRegisterWhoseTablesHoldRows(): void {
		$this->seedDevInstance();
		$this->dataTables['openregister_table_30_77'] = 2;

		$this->step()->run($this->createMock(IOutput::class));

		self::assertArrayHasKey(30, $this->registers);
		self::assertSame(2, $this->dataTables['openregister_table_30_77']);
		self::assertArrayHasKey(14, $this->configurations, 'its configuration still names it');
	}//end testKeepsAKeepiqRegisterWhoseTablesHoldRows()

	/**
	 * (c) A register with a Keepiq slug owned by another application stays.
	 *
	 * @return void
	 */
	public function testLeavesAnotherApplicationsKeepiqSlugRegister(): void {
		$this->seedDevInstance();
		$this->registers[50] = ['slug' => 'keepiq', 'application' => 'other', 'schemas' => []];
		$this->registers[51] = ['slug' => 'doriath', 'application' => null, 'schemas' => []];

		$this->step()->run($this->createMock(IOutput::class));

		self::assertArrayHasKey(50, $this->registers);
		self::assertArrayHasKey(51, $this->registers, 'a register without an application is never deleted');
	}//end testLeavesAnotherApplicationsKeepiqSlugRegister()

	/**
	 * (d) A failure after the first delete rolls everything back, and the next
	 * run removes it all.
	 *
	 * @return void
	 */
	public function testAFailedRunRollsBackAndTheNextRunConverges(): void {
		$this->seedDevInstance();
		$before = $this->state();
		$this->failOnce = 'delete openregister_configurations';

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('warning');
		$this->step()->run($output);

		self::assertSame($before, $this->state(), 'nothing changed: the transaction rolled back and no table was dropped');

		$this->step()->run($this->createMock(IOutput::class));

		self::assertSame([40], array_keys($this->registers));
		self::assertSame([70], array_keys($this->schemas));
		self::assertSame([15], array_keys($this->configurations));
	}//end testAFailedRunRollsBackAndTheNextRunConverges()

	/**
	 * A row written after the commit keeps its table: the drop counts again.
	 *
	 * @return void
	 */
	public function testRechecksATableRightBeforeDroppingIt(): void {
		$this->seedDevInstance();
		$this->afterCommit = function (): void {
			$this->dataTables['openregister_table_20_66'] = 1;
		};

		$this->step()->run($this->createMock(IOutput::class));

		self::assertSame(1, $this->dataTables['openregister_table_20_66']);
	}//end testRechecksATableRightBeforeDroppingIt()

	/**
	 * An instance cleaned up by an earlier version of this step loses the
	 * stale configurations.
	 *
	 * @return void
	 */
	public function testRemovesConfigurationsLeftByAnEarlierCleanup(): void {
		$this->seedDevInstance();
		unset($this->registers[20], $this->registers[30], $this->schemas[66], $this->dataTables['openregister_table_20_66']);

		$this->step()->run($this->createMock(IOutput::class));

		self::assertSame([15], array_keys($this->configurations));
		self::assertSame([40], array_keys($this->registers));
	}//end testRemovesConfigurationsLeftByAnEarlierCleanup()

	/**
	 * When the data tables cannot be listed, nothing is removed.
	 *
	 * @return void
	 */
	public function testRemovesNothingWhenTheTablesCannotBeListed(): void {
		$this->seedDevInstance();
		$before = $this->state();
		$this->provider = IDBConnection::PLATFORM_ORACLE;

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('warning');
		$this->step()->run($output);

		self::assertSame($before, $this->state());
	}//end testRemovesNothingWhenTheTablesCannotBeListed()

	/**
	 * Without OpenRegister's tables the step touches none of them.
	 *
	 * @return void
	 */
	public function testDoesNothingWithoutOpenRegisterTables(): void {
		$this->openRegisterTables = false;

		$output = $this->createMock(IOutput::class);
		$output->expects(self::never())->method('warning');

		$this->step()->run($output);

		self::assertSame([], $this->touched);
	}//end testDoesNothingWithoutOpenRegisterTables()

	/**
	 * A second run deletes and drops nothing.
	 *
	 * @return void
	 */
	public function testSecondRunIsANoop(): void {
		$this->seedDevInstance();
		$this->step()->run($this->createMock(IOutput::class));
		$after = $this->state();
		$this->touched = [];

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with(self::stringContains('0 schema(s), 0 register(s) and 0 configuration(s) removed'));
		$this->step()->run($output);

		self::assertSame($after, $this->state());
		self::assertSame([], array_filter($this->touched, static fn (string $t): bool => str_starts_with($t, 'select') === false));
	}//end testSecondRunIsANoop()

	/**
	 * Every removal is logged before it happens, with what and why.
	 *
	 * @return void
	 */
	public function testLogsEveryRemoval(): void {
		$this->seedDevInstance();
		$messages = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(function (string $message, array $context = []) use (&$messages): void {
			$messages[] = strtr($message, array_combine(array_map(static fn ($k): string => '{' . $k . '}', array_keys($context)), array_map('strval', $context)));
		});

		$this->step(logger: $logger)->run($this->createMock(IOutput::class));

		$log = implode("\n", $messages);
		self::assertStringContainsString('removing schema "example" (66, application keepiq); 0 rows in openregister_table_20_66', $log);
		self::assertStringContainsString('removing register "doriath" (20, application doriath)', $log);
		self::assertStringContainsString('removing register "keepiq" (30, application keepiq)', $log);
		self::assertStringContainsString('removing configuration 9 (app doriath)', $log);
		self::assertStringContainsString('dropping empty table openregister_table_20_66', $log);
	}//end testLogsEveryRemoval()

	/**
	 * A database failure is logged, reported and never thrown.
	 *
	 * @return void
	 */
	public function testNeverThrows(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_POSTGRES);
		$db->method('executeQuery')->willThrowException(new RuntimeException('connection lost'));

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('warning');

		(new RemoveLegacyRegisterRows($db, $this->config(), $this->createMock(LoggerInterface::class)))->run($output);
	}//end testNeverThrows()

	/**
	 * A config reporting the `oc_` table prefix.
	 *
	 * @return IConfig
	 */
	private function config(): IConfig {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($key === 'dbtableprefix') ? 'oc_' : $default
		);
		return $config;
	}//end config()

	/**
	 * The step under test, over the in-memory tables.
	 *
	 * @param LoggerInterface|null $logger The logger
	 *
	 * @return RemoveLegacyRegisterRows
	 */
	private function step(?LoggerInterface $logger = null): RemoveLegacyRegisterRows {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabaseProvider')->willReturnCallback(fn (): string => $this->provider);
		$db->method('tableExists')->willReturnCallback(
			fn (string $table): bool => $this->openRegisterTables === true
				&& (in_array($table, ['openregister_registers', 'openregister_schemas', 'openregister_configurations'], true) === true
					|| ($table === 'openregister_objects' && $this->objects !== null)
					|| array_key_exists($table, $this->dataTables) === true)
		);
		// The catalogue listing: every data table, by its prefixed name.
		$db->method('executeQuery')->willReturnCallback(function (string $sql): IResult {
			$this->touched[] = 'select catalogue';
			$rows = array_map(static fn (string $t): array => ['name' => 'oc_' . $t], array_keys($this->dataTables));
			$result = $this->createMock(IResult::class);
			$result->method('fetch')->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));
			return $result;
		});
		$db->method('beginTransaction')->willReturnCallback(function (): void {
			$this->touched[] = 'begin';
			$this->snapshot = $this->state();
		});
		$db->method('commit')->willReturnCallback(function (): void {
			$this->touched[] = 'commit';
			$this->snapshot = null;
			if ($this->afterCommit !== null) {
				($this->afterCommit)();
			}
		});
		$db->method('rollBack')->willReturnCallback(function (): void {
			$this->touched[] = 'rollback';
			[$this->registers, $this->schemas, $this->configurations, $this->dataTables] = $this->snapshot;
			$this->snapshot = null;
		});
		$db->method('dropTable')->willReturnCallback(function (string $table): void {
			$this->touched[] = 'drop ' . $table;
			unset($this->dataTables[$table]);
		});
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->queryBuilder());

		return new RemoveLegacyRegisterRows($db, $this->config(), $logger ?? $this->createMock(LoggerInterface::class));
	}//end step()

	/**
	 * A query builder that records its table, filter columns and parameters
	 * and answers from the in-memory tables.
	 *
	 * @return IQueryBuilder
	 */
	private function queryBuilder(): IQueryBuilder {
		$state = (object)['kind' => 'select', 'table' => '', 'params' => [], 'columns' => []];
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'where', 'andWhere', 'set', 'setMaxResults'] as $fluent) {
			$qb->method($fluent)->willReturnSelf();
		}

		foreach (['from' => 'select', 'delete' => 'delete', 'update' => 'update'] as $method => $kind) {
			$qb->method($method)->willReturnCallback(function (string $table) use ($qb, $state, $kind) {
				$state->kind = $kind;
				$state->table = $table;
				return $qb;
			});
		}

		$qb->method('createNamedParameter')->willReturnCallback(function (mixed $value) use ($state): string {
			$state->params[] = $value;
			return ':p' . count($state->params);
		});
		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['in', 'eq'] as $operator) {
			$expr->method($operator)->willReturnCallback(function (string $column) use ($state, $operator): string {
				$state->columns[] = $column;
				return $operator;
			});
		}

		$qb->method('expr')->willReturn($expr);
		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('count')->willReturn($this->createMock(IQueryFunction::class));
		$qb->method('func')->willReturn($func);

		$qb->method('executeQuery')->willReturnCallback(fn (): IResult => $this->answer(state: $state));
		$qb->method('executeStatement')->willReturnCallback(fn (): int => $this->write(state: $state));

		return $qb;
	}//end queryBuilder()

	/**
	 * Answer a SELECT from the in-memory tables.
	 *
	 * @param object $state The builder's recorded table, columns and parameters
	 *
	 * @return IResult
	 */
	private function answer(object $state): IResult {
		$this->touched[] = 'select ' . $state->table;
		$rows = [];
		$count = null;
		$filter = $state->columns[0] ?? null;
		if ($state->table === 'openregister_registers') {
			foreach ($this->registers as $id => $register) {
				$rows[] = ['id' => (string)$id, 'slug' => $register['slug'], 'application' => $register['application'], 'schemas' => json_encode($register['schemas'])];
			}
		} elseif ($state->table === 'openregister_schemas' && $filter === 'id') {
			foreach (array_intersect(array_keys($this->schemas), array_map('intval', $state->params[0])) as $id) {
				$rows[] = ['id' => (string)$id];
			}
		} elseif ($state->table === 'openregister_schemas') {
			foreach ($this->schemas as $id => $schema) {
				if (in_array(($schema[$filter] ?? null), $state->params[0], true) === true) {
					$rows[] = ['id' => (string)$id, 'slug' => $schema['slug'], 'application' => $schema['application']];
				}
			}
		} elseif ($state->table === 'openregister_configurations') {
			foreach ($this->configurations as $id => $configuration) {
				if (in_array($configuration['app'], $state->params[0], true) === true) {
					$rows[] = [
						'id' => (string)$id,
						'app' => $configuration['app'],
						'registers' => json_encode($configuration['registers']),
						'schemas' => ($configuration['schemas'] === null) ? null : json_encode($configuration['schemas']),
					];
				}
			}
		} elseif ($state->table === 'openregister_objects') {
			$index = ($filter === 'register') ? 0 : 1;
			$count = count(array_filter($this->objects ?? [], static fn (array $o): bool => $o[$index] === (int)$state->params[0]));
		} else {
			$count = ($this->dataTables[$state->table] ?? 0);
		}

		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($rows);
		$result->method('fetch')->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));
		$result->method('fetchOne')->willReturn($count);
		return $result;
	}//end answer()

	/**
	 * Apply a DELETE or UPDATE to the in-memory tables.
	 *
	 * @param object $state The builder's recorded table and parameters
	 *
	 * @return int Affected rows
	 *
	 * @throws RuntimeException When the write was set to fail once
	 */
	private function write(object $state): int {
		$name = $state->kind . ' ' . $state->table;
		$this->touched[] = $name;
		if ($this->failOnce === $name) {
			$this->failOnce = null;
			throw new RuntimeException('lock timeout');
		}

		if ($state->kind === 'delete' && $state->table === 'openregister_schemas') {
			unset($this->schemas[(int)$state->params[0]]);
		} elseif ($state->kind === 'delete' && $state->table === 'openregister_registers') {
			unset($this->registers[(int)$state->params[0]]);
		} elseif ($state->kind === 'delete' && $state->table === 'openregister_configurations') {
			unset($this->configurations[(int)$state->params[0]]);
		} elseif ($state->kind === 'update' && $state->table === 'openregister_registers') {
			$this->registers[(int)$state->params[1]]['schemas'] = json_decode($state->params[0], true);
		}

		return 1;
	}//end write()
}//end class
