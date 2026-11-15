<?php

/**
 * Keepiq SchemaFingerprint Test
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
use OCA\Keepiq\Backup\SchemaFingerprint;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The schema fingerprint binds an archive to the Keepiq tables and the
 * migrations that shaped them.
 */
#[CoversClass(SchemaFingerprint::class)]
class SchemaFingerprintTest extends TestCase {

	/**
	 * A fingerprint over the given migration rows.
	 *
	 * @param array<int,array<string,mixed>> $rows The migration rows the database returns
	 * @param array<int,string> $appFilter Receives the app id the query filtered on
	 *
	 * @return string
	 */
	private function fingerprintFor(array $rows, array &$appFilter = []): string {
		$result = $this->createMock(originalClassName: IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));
		$result->expects($this->once())->method('closeCursor');

		$expr = $this->createMock(originalClassName: IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(
			static fn ($column, $value): string => $column . '=' . $value
		);

		$qb = $this->createMock(originalClassName: IQueryBuilder::class);
		$qb->expects($this->once())->method('select')->with('version')->willReturnSelf();
		$qb->expects($this->once())->method('from')->with('migrations')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(
			static function ($value) use (&$appFilter): string {
				$appFilter[] = $value;
				return ':app';
			}
		);
		$qb->expects($this->once())->method('where')->with('app=:app')->willReturnSelf();
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(originalClassName: IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return (new SchemaFingerprint(db: $db))->current();
	}//end fingerprintFor()

	/**
	 * The fingerprint hashes the table list and the sorted Keepiq migrations.
	 *
	 * @return void
	 */
	public function testFingerprintHashesTablesAndSortedMigrations(): void {
		$appFilter = [];
		$fingerprint = $this->fingerprintFor(
			rows: [['version' => '010200Date20260301'], ['version' => '010000Date20260101'], ['version' => '010100Date20260201']],
			appFilter: $appFilter
		);

		self::assertSame(expected: ['keepiq'], actual: $appFilter);
		self::assertSame(
			expected: hash('sha256', implode(',', BackupTableRegistry::TABLES) . '|010000Date20260101,010100Date20260201,010200Date20260301'),
			actual: $fingerprint
		);
	}//end testFingerprintHashesTablesAndSortedMigrations()

	/**
	 * The order the database returns migrations in does not change the fingerprint.
	 *
	 * @return void
	 */
	public function testFingerprintIgnoresRowOrder(): void {
		$a = $this->fingerprintFor(rows: [['version' => 'b'], ['version' => 'a']]);
		$b = $this->fingerprintFor(rows: [['version' => 'a'], ['version' => 'b']]);

		self::assertSame(expected: $a, actual: $b);
		self::assertNotSame(expected: $a, actual: $this->fingerprintFor(rows: [['version' => 'a']]));
	}//end testFingerprintIgnoresRowOrder()

	/**
	 * An install without migrations still fingerprints its table list.
	 *
	 * @return void
	 */
	public function testFingerprintWithoutMigrations(): void {
		self::assertSame(
			expected: hash('sha256', implode(',', BackupTableRegistry::TABLES) . '|'),
			actual: $this->fingerprintFor(rows: [])
		);
	}//end testFingerprintWithoutMigrations()
}//end class
