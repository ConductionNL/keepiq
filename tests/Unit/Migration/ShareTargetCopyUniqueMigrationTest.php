<?php

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Migration;

use OCA\Keepiq\Migration\Version001015Date20261021120000;
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
 * cleanup, and the provenance quarantine (GHSA-m5p9-6pw7-pf3g).
 *
 * The migration is driven over a small in-memory model of
 * `keepiq_share_targets` and `keepiq_secrets` through a query-builder double, so
 * the dedup DELETE loop and the quarantine scan/UPDATE are actually exercised.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-revoke-share
 */
class ShareTargetCopyUniqueMigrationTest extends TestCase {

	/**
	 * A base epoch the fixtures offset from, so stored datetimes parse back to
	 * the same gaps the migration compares against the mint window.
	 *
	 * @var int
	 */
	private const BASE = 1700000000;

	/**
	 * Share-target rows, each {id, source_secret_id, target_user_id, secret_id,
	 * created_at, quarantined_at}. `created_at` is an epoch; `quarantined_at` is
	 * null until the migration quarantines the row.
	 *
	 * @var array<int,array{id:string,source_secret_id:string,target_user_id:string,secret_id:string,created_at:int,quarantined_at:int|null}>
	 */
	private array $shareTargets = [];

	/**
	 * Secrets, id => {owner_type, owner_id, created_at}.
	 *
	 * @var array<string,array{owner_type:string,owner_id:string,created_at:int}>
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
	 * The ids the filtered candidate (join) query returned, proving SQL — not
	 * PHP — narrowed the scan to copy-predates-row rows.
	 *
	 * @var list<string>
	 */
	private array $candidateIds = [];

	/**
	 * Indexes/columns dropped and added, recorded by the table double.
	 *
	 * @var array{dropped:list<string>,unique:list<string>,columns:list<string>}
	 */
	private array $recorded = ['dropped' => [], 'unique' => [], 'columns' => []];

	/**
	 * A table double recording index and column changes, typed after whatever
	 * getTable() declares (ITable when it declares nothing), so the schema tests
	 * run wherever the unit suite runs.
	 *
	 * @param array<string,bool> $hasIndex  The hasIndex answer per index name
	 * @param array<string,bool> $hasColumn The hasColumn answer per column name
	 *
	 * @return object
	 */
	private function table(array $hasIndex, array $hasColumn = []): object {
		$returnType = (new \ReflectionMethod(ISchemaWrapper::class, 'getTable'))->getReturnType();
		$class      = ($returnType instanceof ReflectionNamedType) ? $returnType->getName() : ITable::class;

		$mock = $this->createMock($class);
		$mock->method('hasIndex')->willReturnCallback(
			static fn (string $name): bool => ($hasIndex[$name] ?? false)
		);
		$mock->method('hasColumn')->willReturnCallback(
			static fn (string $name): bool => ($hasColumn[$name] ?? false)
		);
		$mock->method('dropIndex')->willReturnCallback(
			function (string $name) use ($mock): object {
				$this->recorded['dropped'][] = $name;
				return $mock;
			}
		);
		$mock->method('addUniqueIndex')->willReturnCallback(
			function (array $columns, ?string $name = null) use ($mock): object {
				$this->recorded['unique'][] = (string)$name;
				return $mock;
			}
		);
		$columnReturn = (new \ReflectionMethod($class, 'addColumn'))->getReturnType();
		$columnClass  = ($columnReturn instanceof ReflectionNamedType) ? $columnReturn->getName() : \OCP\DB\Schema\IColumn::class;
		$column       = $this->createMock($columnClass);
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
		$migration = $this->migration();
		$migration->preSchemaChange($this->outputDouble(), fn () => $this->tableSchema(true), []);
	}

	/**
	 * Run postSchemaChange (quarantine scan) over the in-memory tables.
	 *
	 * @return void
	 */
	private function postSchemaChange(): void {
		$migration = $this->migration();
		$migration->postSchemaChange($this->outputDouble(), fn () => $this->tableSchema(true), []);
	}

	/**
	 * A migration wired to the in-memory query-builder double and the recording
	 * logger.
	 *
	 * @return Version001015Date20261021120000
	 */
	private function migration(): Version001015Date20261021120000 {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string|\Stringable $message, array $context = []): void {
			$this->logged[] = (string)$message;
		});

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->queryBuilder());

		return new Version001015Date20261021120000($db, $logger);
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
	 * A schema whose share-targets table reports the quarantine column present.
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
	 * A query builder that records its table, kind, columns, join, grouping and
	 * filter, then answers from or writes to the in-memory tables.
	 *
	 * @return IQueryBuilder
	 */
	private function queryBuilder(): IQueryBuilder {
		$state = (object)[
			'kind' => 'select',
			'table' => '',
			'join' => false,
			'groupBy' => false,
			'whereCol' => null,
			'params' => [],
			'setCols' => [],
		];
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'selectAlias', 'having', 'orderBy', 'addOrderBy', 'setMaxResults', 'where', 'andWhere'] as $fluent) {
			$qb->method($fluent)->willReturnSelf();
		}

		$qb->method('from')->willReturnCallback(function (string $table, ?string $alias = null) use ($qb, $state): IQueryBuilder {
			$state->table = $table;
			return $qb;
		});
		$qb->method('innerJoin')->willReturnCallback(function () use ($qb, $state): IQueryBuilder {
			$state->join = true;
			return $qb;
		});
		$qb->method('delete')->willReturnCallback(function (string $table) use ($qb, $state): IQueryBuilder {
			$state->kind  = 'delete';
			$state->table = $table;
			return $qb;
		});
		$qb->method('update')->willReturnCallback(function (string $table) use ($qb, $state): IQueryBuilder {
			$state->kind  = 'update';
			$state->table = $table;
			return $qb;
		});
		$qb->method('set')->willReturnCallback(function (string $column) use ($qb, $state): IQueryBuilder {
			$state->setCols[] = $column;
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
		$expr->method('lt')->willReturn('lt');
		$expr->method('isNull')->willReturn('isNull');
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
	 * @return list<array{id:string,source_secret_id:string,target_user_id:string,secret_id:string,created_at:int,quarantined_at:int|null}>
	 */
	private function orderedRows(): array {
		$rows = array_values($this->shareTargets);
		usort($rows, static fn (array $a, array $b): int => [$a['created_at'], $a['id']] <=> [$b['created_at'], $b['id']]);
		return $rows;
	}

	/**
	 * Answer a SELECT from the in-memory tables.
	 *
	 * @param object $state The builder's recorded table, join, grouping and filter
	 *
	 * @return IResult
	 */
	private function answer(object $state): IResult {
		$rows = [];
		if ($state->join === true) {
			// The filtered candidate query: active rows whose copy was created
			// before the row. The database does the narrowing, so only these
			// cross to PHP (N2).
			foreach ($this->orderedRows() as $row) {
				$secret = ($this->secrets[$row['secret_id']] ?? null);
				if ($row['quarantined_at'] !== null || $secret === null || $secret['created_at'] >= $row['created_at']) {
					continue;
				}

				$this->candidateIds[] = $row['id'];
				$rows[] = [
					'id' => $row['id'],
					'source_secret_id' => $row['source_secret_id'],
					'target_user_id' => $row['target_user_id'],
					'secret_id' => $row['secret_id'],
					'row_created_at' => date('Y-m-d H:i:s', $row['created_at']),
					'copy_created_at' => date('Y-m-d H:i:s', $secret['created_at']),
				];
			}
		} elseif ($state->table === 'keepiq_secrets') {
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
	 * Apply a DELETE or UPDATE to the in-memory share-target table.
	 *
	 * @param object $state The builder's recorded kind, table, set columns and params
	 *
	 * @return int Affected rows
	 */
	private function write(object $state): int {
		if ($state->table !== 'keepiq_share_targets' || $state->whereCol !== 'id') {
			return 0;
		}

		// The id parameter is the last one bound (set() values come first on an
		// UPDATE).
		$id      = (string)($state->params[array_key_last($state->params)] ?? '');
		$changed = 0;
		foreach ($this->shareTargets as $key => $row) {
			if ($row['id'] !== $id) {
				continue;
			}

			if ($state->kind === 'delete') {
				unset($this->shareTargets[$key]);
			} elseif ($state->kind === 'update' && in_array('quarantined_at', $state->setCols, true) === true) {
				$this->shareTargets[$key]['quarantined_at'] = self::BASE;
			}

			$changed++;
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
	 * The ids of the rows the migration quarantined.
	 *
	 * @return list<string>
	 */
	private function quarantinedIds(): array {
		$ids = [];
		foreach ($this->shareTargets as $row) {
			if ($row['quarantined_at'] !== null) {
				$ids[] = $row['id'];
			}
		}

		return array_values($ids);
	}

	/**
	 * Build a share-target row with an optional creation offset from BASE.
	 *
	 * @param string $id       The row id
	 * @param string $source   The source secret id
	 * @param string $target   The target user id
	 * @param string $secretId The recipient copy id
	 * @param int    $offset   Seconds added to BASE for created_at
	 *
	 * @return array{id:string,source_secret_id:string,target_user_id:string,secret_id:string,created_at:int,quarantined_at:null}
	 */
	private function row(string $id, string $source, string $target, string $secretId, int $offset): array {
		return [
			'id' => $id,
			'source_secret_id' => $source,
			'target_user_id' => $target,
			'secret_id' => $secretId,
			'created_at' => (self::BASE + $offset),
			'quarantined_at' => null,
		];
	}

	/**
	 * Build a secret with an optional creation offset from BASE.
	 *
	 * @param string $ownerType The owner type
	 * @param string $ownerId   The owner id
	 * @param int    $offset    Seconds added to BASE for created_at
	 *
	 * @return array{owner_type:string,owner_id:string,created_at:int}
	 */
	private function secret(string $ownerType, string $ownerId, int $offset): array {
		return ['owner_type' => $ownerType, 'owner_id' => $ownerId, 'created_at' => (self::BASE + $offset)];
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
	 * Over-deletion case (the reviewer's 🔴): a legitimate onward-share row
	 * `{V_A, B, C_B}`, whose copy C_B is itself a share source but was minted
	 * with its row (created_at within the mint window), is NOT quarantined and
	 * stays functional. The old source-membership rule deleted it; the
	 * provenance rule keeps it.
	 *
	 * @return void
	 */
	public function testKeepsALegitimateOnwardShareRowMintedWithItsRow(): void {
		// A's share of V_A to B gives B the copy C_B (minted with the row).
		// B then shares C_B onward to C, so C_B is also a source — but its copy
		// C_C is likewise minted with that row.
		$this->shareTargets = [
			$this->row('st-ab', 'V_A', 'bob', 'C_B', 0),
			$this->row('st-bc', 'C_B', 'carol', 'C_C', 5),
		];
		$this->secrets = [
			'C_B' => $this->secret('user', 'bob', 0),
			'C_C' => $this->secret('user', 'carol', 5),
		];

		$this->preSchemaChange();
		$this->postSchemaChange();

		$this->assertSame(['st-ab', 'st-bc'], $this->survivingIds());
		$this->assertSame([], $this->quarantinedIds(), 'a copy minted with its row is never quarantined');
		$this->assertSame([], $this->warnings);
	}

	/**
	 * The core advisory case: a planted row whose secret_id is the victim's OWN,
	 * pre-existing secret V (owner == target) is QUARANTINED, not deleted, with V
	 * intact — so no revoke or sync from the attacker's source can reach V while
	 * the row stays for the audit. Removing the quarantine scan leaves the row
	 * active and turns this red.
	 *
	 * @return void
	 */
	public function testQuarantinesARowWhoseCopyPredatesIt(): void {
		// The victim's secret V was created long before the planted row.
		$this->shareTargets = [
			$this->row('st-hijack', 'S_a', 'victim', 'V', 100000),
		];
		$this->secrets = [
			'V' => $this->secret('user', 'victim', 0),
		];

		$this->preSchemaChange();
		$this->postSchemaChange();

		$this->assertSame(['st-hijack'], $this->survivingIds(), 'the row is kept (reversible), not deleted');
		$this->assertSame(['st-hijack'], $this->quarantinedIds(), 'the row is quarantined');
		$this->assertArrayHasKey('V', $this->secrets, 'the victim secret V was never deleted');
		$this->assertCount(1, $this->warnings);
		$this->assertStringContainsString('quarantined', $this->warnings[0]);
		$this->assertStringContainsString('st-hijack', $this->warnings[0]);
		$this->assertStringContainsString('secret_id V', $this->warnings[0]);
		$this->assertStringContainsString('st-hijack', implode("\n", $this->logged), 'the quarantine is logged for admins');
	}

	/**
	 * Under-catch case (the reviewer's 🔴 follow-up): a planted row whose V is no
	 * longer any share's source is still quarantined, because the signal is the
	 * copy's created_at, not source membership.
	 *
	 * @return void
	 */
	public function testQuarantinesAPlantedRowEvenWhenVIsNoLongerASource(): void {
		// V is named only by the planted row; it is nobody's source_secret_id.
		$this->shareTargets = [
			$this->row('st-planted', 'S_a', 'victim', 'V', 50000),
		];
		$this->secrets = [
			'V' => $this->secret('user', 'victim', 0),
		];

		$this->preSchemaChange();
		$this->postSchemaChange();

		$this->assertSame(['st-planted'], $this->quarantinedIds(), 'the created_at signal catches it without a source signal');
		$this->assertArrayHasKey('V', $this->secrets);
	}

	/**
	 * The scan runs off a filtered candidate query (N2): a row whose copy is
	 * NEWER than the row never reaches PHP, so the full table is never loaded.
	 *
	 * @return void
	 */
	public function testScanUsesTheFilteredCandidateQuery(): void {
		$this->shareTargets = [
			// Copy predates the row — a candidate.
			$this->row('st-old', 'S_a', 'victim', 'V', 100000),
			// Copy minted with the row — not a candidate.
			$this->row('st-fresh', 'S_b', 'bob', 'C_B', 0),
		];
		$this->secrets = [
			'V' => $this->secret('user', 'victim', 0),
			'C_B' => $this->secret('user', 'bob', 0),
		];

		$this->postSchemaChange();

		$this->assertSame(['st-old'], $this->candidateIds, 'only the copy-predates-row candidate is streamed');
		$this->assertSame(['st-old'], $this->quarantinedIds());
	}

	/**
	 * A copy minted a little before its row — within the mint window of the
	 * legacy two-step client flow — is a candidate but is NOT quarantined.
	 *
	 * @return void
	 */
	public function testKeepsACopyMintedWithinTheMintWindow(): void {
		$withinWindow = (Version001015Date20261021120000::MINT_WINDOW_SECONDS - 60);
		$this->shareTargets = [
			$this->row('st-legacy', 'S', 'bob', 'C_B', $withinWindow),
		];
		$this->secrets = [
			'C_B' => $this->secret('user', 'bob', 0),
		];

		$this->postSchemaChange();

		$this->assertSame(['st-legacy'], $this->candidateIds, 'it is a candidate (copy older than row)');
		$this->assertSame([], $this->quarantinedIds(), 'but the gap is within the window, so it is kept');
	}

	/**
	 * Exact duplicates are still deduplicated down to the row held by its target
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
			'dup' => $this->secret('user', 'bob', 2),
		];

		$this->preSchemaChange();

		$this->assertSame(['d2'], $this->survivingIds(), 'only the row whose target holds the copy survives');
		$this->assertCount(2, $this->warnings, 'the two deleted rows are each reported');
		$this->assertCount(2, $this->logged, 'the two deleted rows are each logged');
	}

	/**
	 * The quarantine scan is non-fatal: when the candidate query throws, the
	 * upgrade continues and the failure is reported rather than raised.
	 *
	 * @return void
	 */
	public function testQuarantineScanIsNonFatal(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string|\Stringable $message, array $context = []): void {
			$this->logged[] = (string)$message;
		});

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willThrowException(new \RuntimeException('boom'));

		// Must not throw.
		(new Version001015Date20261021120000($db, $logger))
			->postSchemaChange($this->outputDouble(), fn () => $this->tableSchema(true), []);

		$this->assertNotSame([], $this->warnings, 'the failure is reported to the admin');
		$this->assertStringContainsString('quarantine scan failed', $this->warnings[0]);
	}

	/**
	 * postSchemaChange is skipped (no DB access) until the quarantine column
	 * exists, so a schema that reports it missing touches nothing.
	 *
	 * @return void
	 */
	public function testPostSchemaChangeIsSkippedBeforeTheColumnExists(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->never())->method('getQueryBuilder');

		(new Version001015Date20261021120000($db))
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
