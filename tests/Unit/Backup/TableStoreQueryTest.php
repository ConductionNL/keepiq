<?php

/**
 * Keepiq TableStore query Test
 *
 * @category Tests
 * @package  OCA\Keepiq\Tests\Unit\Backup
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

namespace OCA\Keepiq\Tests\Unit\Backup;


use OCA\Keepiq\Backup\BackupTableRegistry;
use OCA\Keepiq\Backup\TableStore;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Reading Keepiq tables for an archive, and the transaction and sequence
 * handling of a restore.
 */
#[CoversClass(TableStore::class)]
class TableStoreQueryTest extends TestCase {

	/**
	 * A query builder double that answers every builder call with itself.
	 *
	 * @param IResult|null $result The result executeQuery() returns
	 *
	 * @return IQueryBuilder
	 */
	private function builder(?IResult $result = null): IQueryBuilder {
		$qb = $this->createMock(originalClassName: IQueryBuilder::class);
		foreach (['select', 'from', 'delete', 'insert', 'values'] as $method) {
			$qb->method($method)->willReturnSelf();
		}

		if ($result !== null) {
			$qb->method('executeQuery')->willReturn($result);
		}

		return $qb;
	}//end builder()

	/**
	 * rows(): yields every row of the prefixed table, then closes the cursor.
	 *
	 * @return void
	 */
	public function testRowsStreamsThePrefixedTable(): void {
		$result = $this->createMock(originalClassName: IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(['id' => 1], ['id' => 2], false);
		$result->expects($this->once())->method('closeCursor');

		$qb = $this->builder(result: $result);
		$qb->expects($this->once())->method('from')->with('keepiq_secrets')->willReturnSelf();

		$db = $this->createMock(originalClassName: IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$rows = [];
		foreach ((new TableStore(db: $db))->rows(table: 'secrets') as $row) {
			$rows[] = $row;
		}

		self::assertSame(expected: [['id' => 1], ['id' => 2]], actual: $rows);
	}//end testRowsStreamsThePrefixedTable()

	/**
	 * count(): the row count of the prefixed table, as an integer.
	 *
	 * @return void
	 */
	public function testCountReturnsTheTableRowCount(): void {
		$result = $this->createMock(originalClassName: IResult::class);
		$result->method('fetchOne')->willReturn('17');
		$result->expects($this->once())->method('closeCursor');

		$func = $this->createMock(originalClassName: IFunctionBuilder::class);
		$func->expects($this->once())->method('count')->with('*', 'row_count')
			->willReturn($this->createMock(originalClassName: IQueryFunction::class));

		$qb = $this->builder(result: $result);
		$qb->method('func')->willReturn($func);
		$qb->expects($this->once())->method('from')->with('keepiq_folders')->willReturnSelf();

		$db = $this->createMock(originalClassName: IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		self::assertSame(expected: 17, actual: (new TableStore(db: $db))->count(table: 'folders'));
	}//end testCountReturnsTheTableRowCount()

	/**
	 * newestAuditEntry(): the maximum occurred_at of the audit log, or null.
	 *
	 * @param mixed $stored What the database returns for the maximum
	 * @param string|null $expected The expected newest entry
	 *
	 * @return void
	 *
	 * @dataProvider newestAuditEntryProvider
	 */
	public function testNewestAuditEntry(mixed $stored, ?string $expected): void {
		$result = $this->createMock(originalClassName: IResult::class);
		$result->method('fetchOne')->willReturn($stored);
		$result->expects($this->once())->method('closeCursor');

		$func = $this->createMock(originalClassName: IFunctionBuilder::class);
		$func->expects($this->once())->method('max')->with('occurred_at')
			->willReturn($this->createMock(originalClassName: IQueryFunction::class));

		$qb = $this->builder(result: $result);
		$qb->method('func')->willReturn($func);
		$qb->expects($this->once())->method('from')->with('keepiq_audit_log')->willReturnSelf();

		$db = $this->createMock(originalClassName: IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		self::assertSame(expected: $expected, actual: (new TableStore(db: $db))->newestAuditEntry());
	}//end testNewestAuditEntry()

	/**
	 * Database answers for the newest audit entry.
	 *
	 * @return array<string,array{0:mixed,1:string|null}>
	 */
	public static function newestAuditEntryProvider(): array {
		return [
			'a timestamp' => ['2026-11-01 10:00:00', '2026-11-01 10:00:00'],
			'no rows (false)' => [false, null],
			'empty log (null)' => [null, null],
		];
	}//end newestAuditEntryProvider()

	/**
	 * replaceAll(): a failing insert rolls the whole restore back and rethrows.
	 *
	 * @return void
	 */
	public function testReplaceAllRollsBackOnFailure(): void {
		$qb = $this->builder();
		$qb->method('createNamedParameter')->willReturn(':p');
		$qb->method('executeStatement')->willReturn(1);

		$failure = new RuntimeException('insert refused');
		$db = $this->createMock(originalClassName: IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->never())->method('commit');
		$db->expects($this->once())->method('rollBack');
		$db->expects($this->never())->method('executeQuery');

		try {
			(new TableStore(db: $db))->replaceAll(
				rowsFor: static function (string $table) use ($failure): array {
					if ($table === 'audit_log') {
						throw $failure;
					}

					return [];
				}
			);
			self::fail(message: 'replaceAll() must rethrow the failure.');
		} catch (RuntimeException $exception) {
			self::assertSame(expected: $failure, actual: $exception);
		}
	}//end testReplaceAllRollsBackOnFailure()

	/**
	 * replaceAll(): on PostgreSQL every autoincrement sequence is moved past
	 * the restored maximum after the commit.
	 *
	 * @return void
	 */
	public function testReplaceAllResetsSequencesOnPostgres(): void {
		$qb = $this->builder();
		$qb->method('executeStatement')->willReturn(0);

		$order = [];
		$db = $this->createMock(originalClassName: IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_POSTGRES);
		$db->method('commit')->willReturnCallback(
			static function () use (&$order): void {
				$order[] = 'commit';
			}
		);
		$db->method('executeQuery')->willReturnCallback(
			function (string $sql) use (&$order): IResult {
				$order[] = $sql;
				return $this->createMock(originalClassName: IResult::class);
			}
		);

		$written = (new TableStore(db: $db))->replaceAll(rowsFor: static fn (string $table): array => []);

		self::assertSame(expected: array_fill_keys(BackupTableRegistry::TABLES, 0), actual: $written);
		$expected = ['commit'];
		foreach (BackupTableRegistry::AUTOINCREMENT as $table) {
			$name = '*PREFIX*keepiq_' . $table;
			$expected[] = "SELECT setval(pg_get_serial_sequence('" . $name . "', 'id'), COALESCE((SELECT MAX(id) FROM " . $name . '), 0) + 1, false)';
		}

		self::assertSame(expected: $expected, actual: $order);
	}//end testReplaceAllResetsSequencesOnPostgres()
}//end class
