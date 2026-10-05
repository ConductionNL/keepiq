<?php

/**
 * Unit tests for the legacy OpenRegister row cleanup.
 *
 * The step talks to OpenRegister's tables through IDBConnection's query
 * builder. These tests back that builder with a small in-memory model of the
 * three tables it reads (registers, schemas, objects) and the per-schema data
 * tables, so each scenario asserts the resulting table state rather than a
 * call sequence.
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
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for RemoveLegacyRegisterRows.
 *
 * @covers \OCA\Keepiq\Repair\RemoveLegacyRegisterRows
 */
final class RemoveLegacyRegisterRowsTest extends TestCase {
	/**
	 * Registers, id => [slug, schemas].
	 *
	 * @var array<int,array{slug:string,schemas:int[]}>
	 */
	private array $registers = [];

	/**
	 * Schemas, id => [slug, application].
	 *
	 * @var array<int,array{slug:string,application:string}>
	 */
	private array $schemas = [];

	/**
	 * Rows in openregister_objects per schema id.
	 *
	 * @var array<int,int>
	 */
	private array $objects = [];

	/**
	 * Per-schema data tables, name => row count.
	 *
	 * @var array<string,int>
	 */
	private array $dataTables = [];

	/**
	 * Whether OpenRegister's tables exist at all.
	 *
	 * @var bool
	 */
	private bool $openRegisterTables = true;

	/**
	 * Every statement or query that touched an openregister_* table.
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
			20 => ['slug' => 'doriath', 'schemas' => [66]],
			30 => ['slug' => 'keepiq', 'schemas' => []],
			40 => ['slug' => 'shillinq', 'schemas' => [70]],
		];
		$this->schemas = [
			66 => ['slug' => 'example', 'application' => 'keepiq'],
			70 => ['slug' => 'example', 'application' => 'shillinq'],
		];
		$this->dataTables = ['openregister_table_20_66' => 0, 'openregister_table_40_70' => 0];
	}//end seedDevInstance()

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
		self::assertSame(['openregister_table_40_70'], array_keys($this->dataTables), 'the empty Keepiq data table is dropped');
	}//end testRemovesEmptyLeftoversAndNothingElse()

	/**
	 * A schema with objects, in either store, is kept with its table and register.
	 *
	 * @return void
	 */
	public function testKeepsASchemaThatHoldsObjects(): void {
		foreach (['shared table' => [66 => 2], 'data table' => []] as $case => $objects) {
			$this->seedDevInstance();
			$this->objects = $objects;
			if ($objects === []) {
				$this->dataTables['openregister_table_20_66'] = 3;
			}

			$logger = $this->createMock(LoggerInterface::class);
			$logger->expects(self::once())->method('warning')->with(
				self::stringContains('still holds'),
				self::callback(static fn (array $ctx): bool => $ctx['slug'] === 'example' && $ctx['count'] > 0)
			);

			$this->step(logger: $logger)->run($this->createMock(IOutput::class));

			self::assertArrayHasKey(66, $this->schemas, $case . ': schema kept');
			self::assertArrayHasKey('openregister_table_20_66', $this->dataTables, $case . ': data table kept');
			self::assertSame([20, 40], array_keys($this->registers), $case . ': its register kept, the empty keepiq one removed');
			self::assertSame([66], $this->registers[20]['schemas'], $case . ': register still lists it');
		}
	}//end testKeepsASchemaThatHoldsObjects()

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
		$after = [$this->registers, $this->schemas, $this->dataTables];
		$this->touched = [];

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with(self::stringContains('0 schema(s) and 0 register(s) removed'));
		$this->step()->run($output);

		self::assertSame($after, [$this->registers, $this->schemas, $this->dataTables]);
		self::assertSame([], array_filter($this->touched, static fn (string $t): bool => str_starts_with($t, 'delete') || str_starts_with($t, 'drop')));
	}//end testSecondRunIsANoop()

	/**
	 * A database failure is logged, reported and never thrown.
	 *
	 * @return void
	 */
	public function testNeverThrows(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willThrowException(new \RuntimeException('connection lost'));

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('warning');

		(new RemoveLegacyRegisterRows($db, $this->createMock(LoggerInterface::class)))->run($output);
	}//end testNeverThrows()

	/**
	 * The step under test, over the in-memory tables.
	 *
	 * @param LoggerInterface|null $logger The logger
	 *
	 * @return RemoveLegacyRegisterRows
	 */
	private function step(?LoggerInterface $logger = null): RemoveLegacyRegisterRows {
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturnCallback(
			fn (string $table): bool => $this->openRegisterTables === true
				&& (in_array($table, ['openregister_registers', 'openregister_schemas', 'openregister_objects'], true) === true
					|| array_key_exists($table, $this->dataTables) === true)
		);
		$db->method('dropTable')->willReturnCallback(
			function (string $table): void {
				$this->touched[] = 'drop ' . $table;
				unset($this->dataTables[$table]);
			}
		);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->queryBuilder());

		return new RemoveLegacyRegisterRows($db, $logger ?? $this->createMock(LoggerInterface::class));
	}//end step()

	/**
	 * A query builder that records its table and parameters and answers from
	 * the in-memory tables.
	 *
	 * @return IQueryBuilder
	 */
	private function queryBuilder(): IQueryBuilder {
		$state = (object)['kind' => 'select', 'table' => '', 'params' => [], 'inColumn' => ''];
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'where', 'andWhere', 'set', 'setMaxResults'] as $fluent) {
			$qb->method($fluent)->willReturnSelf();
		}

		$qb->method('from')->willReturnCallback(function (string $table) use ($qb, $state) {
			$state->table = $table;
			return $qb;
		});
		$qb->method('delete')->willReturnCallback(function (string $table) use ($qb, $state) {
			$state->kind = 'delete';
			$state->table = $table;
			return $qb;
		});
		$qb->method('update')->willReturnCallback(function (string $table) use ($qb, $state) {
			$state->kind = 'update';
			$state->table = $table;
			return $qb;
		});
		$qb->method('createNamedParameter')->willReturnCallback(function (mixed $value) use ($state): string {
			$state->params[] = $value;
			return ':p' . count($state->params);
		});
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('in')->willReturnCallback(function (string $column) use ($state): string {
			$state->inColumn = $column;
			return 'in';
		});
		$expr->method('eq')->willReturn('eq');
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
	 * @param object $state The builder's recorded table and parameters
	 *
	 * @return IResult
	 */
	private function answer(object $state): IResult {
		$this->touched[] = 'select ' . $state->table;
		$rows = [];
		$count = null;
		if ($state->table === 'openregister_registers') {
			foreach ($this->registers as $id => $register) {
				$rows[] = ['id' => (string)$id, 'slug' => $register['slug'], 'schemas' => json_encode($register['schemas'])];
			}
		} elseif ($state->table === 'openregister_schemas') {
			// Honour the column the step filters on, so selecting by slug
			// (which another app's `example` schema shares) is caught.
			foreach ($this->schemas as $id => $schema) {
				if (in_array(($schema[$state->inColumn] ?? null), $state->params[0], true) === true) {
					$rows[] = ['id' => (string)$id, 'slug' => $schema['slug']];
				}
			}
		} elseif ($state->table === 'openregister_objects') {
			$count = ($this->objects[(int)$state->params[0]] ?? 0);
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
	 */
	private function write(object $state): int {
		$this->touched[] = $state->kind . ' ' . $state->table;
		if ($state->kind === 'delete' && $state->table === 'openregister_schemas') {
			unset($this->schemas[(int)$state->params[0]]);
		} elseif ($state->kind === 'delete' && $state->table === 'openregister_registers') {
			unset($this->registers[(int)$state->params[0]]);
		} elseif ($state->kind === 'update' && $state->table === 'openregister_registers') {
			$this->registers[(int)$state->params[1]]['schemas'] = json_decode($state->params[0], true);
		}

		return 1;
	}//end write()
}//end class
