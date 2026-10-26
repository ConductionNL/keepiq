<?php

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Migration;

use OCA\Keepiq\Migration\Version001015Date20261021120000;
use OCA\Keepiq\Service\ShareQuarantineScanner;
use OCP\DB\IResult;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\DB\Schema\ITable;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionNamedType;

/**
 * The unique index on keepiq_share_targets.secret_id, the pre-index duplicate
 * cleanup, and the hand-off to the quarantine scan (GHSA-m5p9-6pw7-pf3g).
 *
 * The dedup is driven over a small in-memory model of `keepiq_share_targets`
 * and `keepiq_secrets` through a query-builder double, so its DELETE loop is
 * actually exercised. The scan itself is tested against a real database in
 * ShareQuarantineScannerTest.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-revoke-share
 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
 */
class ShareTargetCopyUniqueMigrationTest extends TestCase {

	/**
	 * A base epoch the fixtures offset from.
	 *
	 * @var int
	 */
	private const BASE = 1700000000;

	/**
	 * Share-target rows, each {id, source_secret_id, target_user_id, secret_id,
	 * created_at}. `created_at` is an epoch.
	 *
	 * @var array<int,array{id:string,source_secret_id:string,target_user_id:string,secret_id:string,created_at:int}>
	 */
	private array $shareTargets = [];

	/**
	 * Secrets, id => {owner_type, owner_id}.
	 *
	 * @var array<string,array{owner_type:string,owner_id:string}>
	 */
	private array $secrets = [];

	/**
	 * Warning messages written to the migration output, in order.
	 *
	 * @var list<string>
	 */
	private array $warnings = [];

	/**
	 * Warning messages written to the logger, in order.
	 *
	 * @var list<string>
	 */
	private array $logged = [];

	/**
	 * Indexes/columns dropped and added, recorded by the table double.
	 *
	 * @var array{dropped:list<string>,unique:list<string>,columns:list<string>}
	 */
	private array $recorded = ['dropped' => [], 'unique' => [], 'columns' => []];

	/**
	 * A table double recording index and column changes, typed after whatever
	 * getTable() declares (ITable, or Doctrine's Table on a server whose
	 * getTable() declares nothing), so the schema tests run wherever the unit
	 * suite runs.
	 *
	 * @param array<string,bool> $hasIndex  The hasIndex answer per index name
	 * @param array<string,bool> $hasColumn The hasColumn answer per column name
	 *
	 * @return object
	 */
	private function table(array $hasIndex, array $hasColumn = []): object {
		$returnType = (new \ReflectionMethod(ISchemaWrapper::class, 'getTable'))->getReturnType();
		$class      = ($returnType instanceof ReflectionNamedType) ? $returnType->getName() : ITable::class;
		if (interface_exists($class) === false && class_exists($class) === false) {
			$class = \Doctrine\DBAL\Schema\Table::class;
		}

		$mock = $this->createMock($class);
		$mock->method('hasIndex')->willReturnCallback(
			static fn (string $name): bool => ($hasIndex[$name] ?? false)
		);
		$mock->method('hasColumn')->willReturnCallback(
			static fn (string $name): bool => ($hasColumn[$name] ?? false)
		);
		$mock->method('dropIndex')->willReturnCallback(
			function (string $name) use ($mock) {
				$this->recorded['dropped'][] = $name;
				return $mock;
			}
		);
		$mock->method('addUniqueIndex')->willReturnCallback(
			function (array $columns, ?string $name = null) use ($mock) {
				$this->recorded['unique'][] = (string)$name;
				return $mock;
			}
		);
		$columnReturn = (new \ReflectionMethod($class, 'addColumn'))->getReturnType();
		$columnClass  = ($columnReturn instanceof ReflectionNamedType) ? $columnReturn->getName() : \OCP\DB\Schema\IColumn::class;
		if (interface_exists($columnClass) === false && class_exists($columnClass) === false) {
			$columnClass = \Doctrine\DBAL\Schema\Column::class;
		}

		$column = $this->createMock($columnClass);
		$mock->method('addColumn')->willReturnCallback(
			function (string $name) use ($column): object {
				$this->recorded['columns'][] = $name;
				return $column;
			}
		);

		return $mock;
	}

	/**
	 * Run changeSchema against a schema whose table answers the given maps.
	 *
	 * @param array<string,bool> $hasIndex  The hasIndex answer per index name
	 * @param array<string,bool> $hasColumn The hasColumn answer per column name
	 *
	 * @return ISchemaWrapper|null
	 */
	private function changeSchema(array $hasIndex, array $hasColumn = []): ?ISchemaWrapper {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);
		$schema->method('getTable')->willReturn($this->table($hasIndex, $hasColumn));

		return (new Version001015Date20261021120000($this->createMock(IDBConnection::class)))
			->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);
	}

	/**
	 * Run preSchemaChange (dedup) over the in-memory tables.
	 *
	 * @return void
	 */
	private function preSchemaChange(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string|\Stringable $message, array $context = []): void {
			$this->logged[] = (string)$message;
		});

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->queryBuilder());

		(new Version001015Date20261021120000($db, $logger))
			->preSchemaChange($this->outputDouble(), fn () => $this->tableSchema(true), []);
	}

	/**
	 * The migration output double, collecting warnings.
	 *
	 * @return IOutput
	 */
	private function outputDouble(): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('warning')->willReturnCallback(function (string $message): void {
			$this->warnings[] = $message;
		});
		return $output;
	}

	/**
	 * A schema whose share-targets table reports the quarantine column present
	 * or not.
	 *
	 * @param bool $hasColumn Whether the quarantine column exists yet
	 *
	 * @return ISchemaWrapper
	 */
	private function tableSchema(bool $hasColumn): ISchemaWrapper {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);
		$schema->method('getTable')->willReturn($this->table([], ['quarantined_at' => $hasColumn]));
		return $schema;
	}

	/**
	 * A query builder that records its table, kind, grouping and filter, then
	 * answers from or deletes in the in-memory tables.
	 *
	 * @return IQueryBuilder
	 */
	private function queryBuilder(): IQueryBuilder {
		$state = (object)[
			'kind' => 'select',
			'table' => '',
			'groupBy' => false,
			'whereCol' => null,
			'params' => [],
		];
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'having', 'orderBy', 'addOrderBy', 'setMaxResults', 'where', 'andWhere'] as $fluent) {
			$qb->method($fluent)->willReturnSelf();
		}

		$qb->method('from')->willReturnCallback(function (string $table) use ($qb, $state): IQueryBuilder {
			$state->table = $table;
			return $qb;
		});
		$qb->method('delete')->willReturnCallback(function (string $table) use ($qb, $state): IQueryBuilder {
			$state->kind  = 'delete';
			$state->table = $table;
			return $qb;
		});
		$qb->method('groupBy')->willReturnCallback(function () use ($qb, $state): IQueryBuilder {
			$state->groupBy = true;
			return $qb;
		});
		$qb->method('createNamedParameter')->willReturnCallback(function (mixed $value) use ($state): string {
			$state->params[] = $value;
			return ':p' . count($state->params);
		});

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(function (string $column) use ($state): string {
			$state->whereCol = $column;
			return 'eq';
		});
		$expr->method('gt')->willReturn('gt');
		$qb->method('expr')->willReturn($expr);

		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('count')->willReturn($this->createMock(IQueryFunction::class));
		$qb->method('func')->willReturn($func);

		$qb->method('executeQuery')->willReturnCallback(fn (): IResult => $this->answer(state: $state));
		$qb->method('executeStatement')->willReturnCallback(fn (): int => $this->write(state: $state));

		return $qb;
	}

	/**
	 * Share-target rows ordered oldest first.
	 *
	 * @return list<array{id:string,source_secret_id:string,target_user_id:string,secret_id:string,created_at:int}>
	 */
	private function orderedRows(): array {
		$rows = array_values($this->shareTargets);
		usort($rows, static fn (array $a, array $b): int => [$a['created_at'], $a['id']] <=> [$b['created_at'], $b['id']]);
		return $rows;
	}

	/**
	 * Answer a SELECT from the in-memory tables.
	 *
	 * @param object $state The builder's recorded table, grouping and filter
	 *
	 * @return IResult
	 */
	private function answer(object $state): IResult {
		$rows = [];
		if ($state->table === 'keepiq_secrets') {
			$secret = ($this->secrets[(string)($state->params[0] ?? '')] ?? null);
			if ($secret !== null) {
				$rows[] = ['owner_type' => $secret['owner_type'], 'owner_id' => $secret['owner_id']];
			}
		} elseif ($state->groupBy === true) {
			// secret_id values held by more than one row (HAVING COUNT > 1).
			$counts = [];
			foreach ($this->orderedRows() as $row) {
				$counts[$row['secret_id']] = (($counts[$row['secret_id']] ?? 0) + 1);
			}

			foreach ($counts as $secretId => $count) {
				if ($count > 1) {
					$rows[] = ['secret_id' => (string)$secretId];
				}
			}
		} else {
			// The rows of one secret_id (dedup group).
			foreach ($this->orderedRows() as $row) {
				if ($state->whereCol === 'secret_id' && $row['secret_id'] !== (string)($state->params[0] ?? '')) {
					continue;
				}

				$rows[] = [
					'id' => $row['id'],
					'source_secret_id' => $row['source_secret_id'],
					'target_user_id' => $row['target_user_id'],
					'secret_id' => $row['secret_id'],
				];
			}
		}//end if

		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));
		return $result;
	}

	/**
	 * Apply a DELETE to the in-memory share-target table.
	 *
	 * @param object $state The builder's recorded kind, table and params
	 *
	 * @return int Affected rows
	 */
	private function write(object $state): int {
		if ($state->kind !== 'delete' || $state->table !== 'keepiq_share_targets' || $state->whereCol !== 'id') {
			return 0;
		}

		$id      = (string)($state->params[array_key_last($state->params)] ?? '');
		$changed = 0;
		foreach ($this->shareTargets as $key => $row) {
			if ($row['id'] === $id) {
				unset($this->shareTargets[$key]);
				$changed++;
			}
		}

		return $changed;
	}

	/**
	 * The ids of the surviving (not deleted) share-target rows.
	 *
	 * @return list<string>
	 */
	private function survivingIds(): array {
		return array_values(array_map(static fn (array $row): string => $row['id'], $this->shareTargets));
	}

	/**
	 * Build a share-target row with a creation offset from BASE.
	 *
	 * @param string $id       The row id
	 * @param string $source   The source secret id
	 * @param string $target   The target user id
	 * @param string $secretId The recipient copy id
	 * @param int    $offset   Seconds added to BASE for created_at
	 *
	 * @return array{id:string,source_secret_id:string,target_user_id:string,secret_id:string,created_at:int}
	 */
	private function row(string $id, string $source, string $target, string $secretId, int $offset): array {
		return [
			'id' => $id,
			'source_secret_id' => $source,
			'target_user_id' => $target,
			'secret_id' => $secretId,
			'created_at' => (self::BASE + $offset),
		];
	}

	/**
	 * On an install that still has the old non-unique index and neither
	 * quarantine column, both columns are added and the index is made unique.
	 *
	 * @return void
	 */
	public function testAddsColumnsDropsOldIndexAndAddsTheUniqueOne(): void {
		$result = $this->changeSchema(
			[
				Version001015Date20261021120000::OLD_INDEX => true,
				Version001015Date20261021120000::UNIQUE_INDEX => false,
			],
			[
				Version001015Date20261021120000::COL_QUARANTINED_AT => false,
				Version001015Date20261021120000::COL_QUARANTINE_REASON => false,
			]
		);

		$this->assertNotNull($result);
		$this->assertSame([Version001015Date20261021120000::OLD_INDEX], $this->recorded['dropped']);
		$this->assertSame([Version001015Date20261021120000::UNIQUE_INDEX], $this->recorded['unique']);
		$this->assertSame(
			[
				Version001015Date20261021120000::COL_QUARANTINED_AT,
				Version001015Date20261021120000::COL_QUARANTINE_REASON,
			],
			$this->recorded['columns']
		);
	}

	/**
	 * A schema that already has the columns and the unique index and not the old
	 * one is left alone (idempotent).
	 *
	 * @return void
	 */
	public function testIsANoopWhenEverythingIsAlreadyPresent(): void {
		$result = $this->changeSchema(
			[
				Version001015Date20261021120000::OLD_INDEX => false,
				Version001015Date20261021120000::UNIQUE_INDEX => true,
			],
			[
				Version001015Date20261021120000::COL_QUARANTINED_AT => true,
				Version001015Date20261021120000::COL_QUARANTINE_REASON => true,
			]
		);

		$this->assertNull($result);
		$this->assertSame([], $this->recorded['dropped']);
		$this->assertSame([], $this->recorded['unique']);
		$this->assertSame([], $this->recorded['columns']);
	}

	/**
	 * Exact duplicates are deduplicated down to the row held by its target
	 * before the unique index is added; the two others are deleted and reported.
	 * Flipping `$row['id'] === $keep` to `!==` deletes the held row instead,
	 * which this catches.
	 *
	 * @return void
	 */
	public function testDeduplicatesDownToTheRowHeldByItsTarget(): void {
		$this->shareTargets = [
			$this->row('d1', 'srcDup', 'carol', 'dup', 1),
			$this->row('d2', 'srcDup', 'bob', 'dup', 2),
			$this->row('d3', 'srcDup', 'dan', 'dup', 3),
		];
		// The copy is held by bob, so d2 is the keeper even though it is not the
		// earliest row.
		$this->secrets = [
			'dup' => ['owner_type' => 'user', 'owner_id' => 'bob'],
		];

		$this->preSchemaChange();

		$this->assertSame(['d2'], $this->survivingIds(), 'only the row whose target holds the copy survives');
		$this->assertCount(2, $this->warnings, 'the two deleted rows are each reported');
		$this->assertCount(2, $this->logged, 'the two deleted rows are each logged');
	}

	/**
	 * Once the quarantine column exists, postSchemaChange hands the scan to
	 * ShareQuarantineScanner with the migration output. The scanner, not the
	 * migration, decides whether the pass completed, so a failure leaves the
	 * background job to retry it (ShareQuarantineScannerTest).
	 *
	 * @return void
	 */
	public function testPostSchemaChangeRunsTheQuarantineScan(): void {
		$output  = $this->createMock(IOutput::class);
		$scanner = $this->createMock(ShareQuarantineScanner::class);
		$scanner->expects($this->once())->method('run')->with($output)->willReturn(false);

		(new Version001015Date20261021120000($this->createMock(IDBConnection::class), null, $scanner))
			->postSchemaChange($output, fn () => $this->tableSchema(true), []);
	}

	/**
	 * Without a scanner, postSchemaChange says in the migration output that
	 * the background job will quarantine planted rows.
	 *
	 * @return void
	 */
	public function testPostSchemaChangeWarnsWhenTheScannerIsMissing(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with($this->stringContains('ShareQuarantineScanJob'));

		(new Version001015Date20261021120000($this->createMock(IDBConnection::class), null, null))
			->postSchemaChange($output, fn () => $this->tableSchema(true), []);
	}

	/**
	 * postSchemaChange is skipped until the quarantine column exists, so a
	 * schema that reports it missing runs no scan.
	 *
	 * @return void
	 */
	public function testPostSchemaChangeIsSkippedBeforeTheColumnExists(): void {
		$scanner = $this->createMock(ShareQuarantineScanner::class);
		$scanner->expects($this->never())->method('run');

		(new Version001015Date20261021120000($this->createMock(IDBConnection::class), null, $scanner))
			->postSchemaChange($this->createMock(IOutput::class), fn () => $this->tableSchema(false), []);
	}

	/**
	 * The keeper of a duplicate group is the row whose copy the target actually
	 * holds, even when it is not the earliest.
	 *
	 * @return void
	 */
	public function testKeeperIsTheRowHeldByTheTarget(): void {
		$rows = [
			['id' => 'st-old', 'targetUserId' => 'carol'],
			['id' => 'st-held', 'targetUserId' => 'bob'],
		];

		$this->assertSame('st-held', Version001015Date20261021120000::keeperId($rows, 'user', 'bob'));
	}

	/**
	 * When no row's target holds the copy, the earliest row is kept.
	 *
	 * @return void
	 */
	public function testKeeperFallsBackToTheEarliestRow(): void {
		$rows = [
			['id' => 'st-old', 'targetUserId' => 'carol'],
			['id' => 'st-new', 'targetUserId' => 'dan'],
		];

		$this->assertSame('st-old', Version001015Date20261021120000::keeperId($rows, 'user', 'bob'));
		// A team-owned copy is held by no user, so the earliest row wins too.
		$this->assertSame('st-old', Version001015Date20261021120000::keeperId($rows, 'team', 'team-1'));
	}

	/**
	 * A group that is not actually duplicated keeps its one row, and an empty
	 * group yields nothing.
	 *
	 * @return void
	 */
	public function testKeeperOnASingleOrEmptyGroup(): void {
		$this->assertSame('st-1', Version001015Date20261021120000::keeperId([['id' => 'st-1', 'targetUserId' => 'bob']], 'user', 'bob'));
		$this->assertSame('', Version001015Date20261021120000::keeperId([], 'user', 'bob'));
	}

	/**
	 * With no share-targets table there is nothing to do and the DB is never
	 * touched.
	 *
	 * @return void
	 */
	public function testPreSchemaChangeIsANoopWithoutTheTable(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->never())->method('getQueryBuilder');

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(false);

		(new Version001015Date20261021120000($db))
			->preSchemaChange($this->createMock(IOutput::class), static fn () => $schema, []);
	}
}
