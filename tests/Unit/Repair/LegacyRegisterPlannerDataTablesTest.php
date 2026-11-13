<?php

/**
 * Unit tests for LegacyRegisterPlanner::dataTables().
 *
 * The planner lists OpenRegister's per-schema data tables from the database
 * catalogue. Each supported platform gets its own catalogue query, the table
 * prefix is escaped for LIKE, and only names of the exact
 * `<prefix>openregister_table_<register>_<schema>` shape are returned.
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

use OCA\Keepiq\Repair\LegacyRegisterPlanner;
use OCP\DB\IResult;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for LegacyRegisterPlanner::dataTables().
 *
 * @covers \OCA\Keepiq\Repair\LegacyRegisterPlanner
 * @uses   \OCA\Keepiq\Repair\StoredIdList
 */
class LegacyRegisterPlannerDataTablesTest extends TestCase {

	/**
	 * The SQL and parameters each query ran with.
	 *
	 * @var array<int,array{0:string,1:array<int,mixed>}>
	 */
	private array $queries = [];

	/**
	 * A planner over a database of the given platform answering the rows.
	 *
	 * @param string                          $platform The database provider
	 * @param array<int,array<string,string>> $rows     The catalogue rows
	 * @param string                          $prefix   The table prefix
	 *
	 * @return LegacyRegisterPlanner The planner
	 */
	private function planner(string $platform, array $rows = [], string $prefix = 'oc_'): LegacyRegisterPlanner {
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(...[...$rows, false]);
		$result->expects($this->once())->method('closeCursor');

		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabaseProvider')->willReturn($platform);
		$db->method('executeQuery')->willReturnCallback(
			function (string $sql, array $params = []) use ($result): IResult {
				$this->queries[] = [$sql, $params];
				return $result;
			}
		);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->with('dbtableprefix', 'oc_')->willReturn($prefix);

		return new LegacyRegisterPlanner($db, $config, $this->createMock(LoggerInterface::class));
	}//end planner()

	/**
	 * MySQL and MariaDB read information_schema for the current database.
	 *
	 * @return void
	 */
	public function testMysqlAndMariadbQueryTheCurrentDatabase(): void {
		foreach ([IDBConnection::PLATFORM_MYSQL, IDBConnection::PLATFORM_MARIADB] as $platform) {
			$this->queries = [];
			$tables = $this->planner($platform, [['name' => 'oc_openregister_table_3_14']])->dataTables();

			self::assertSame([[3, 14, 'openregister_table_3_14']], $tables, $platform);
			self::assertSame(
				[[
					'SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE ?',
					['oc\\_openregister\\_table\\_%'],
				]],
				$this->queries,
				$platform
			);
		}
	}//end testMysqlAndMariadbQueryTheCurrentDatabase()

	/**
	 * SQLite reads sqlite_master with an explicit LIKE escape character.
	 *
	 * @return void
	 */
	public function testSqliteQueriesTheMasterTable(): void {
		$tables = $this->planner(IDBConnection::PLATFORM_SQLITE, [['name' => 'oc_openregister_table_1_2']])->dataTables();

		self::assertSame([[1, 2, 'openregister_table_1_2']], $tables);
		self::assertSame(
			"SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE ? ESCAPE '\\'",
			$this->queries[0][0]
		);
	}//end testSqliteQueriesTheMasterTable()

	/**
	 * PostgreSQL reads information_schema for the current schema.
	 *
	 * @return void
	 */
	public function testPostgresQueriesTheCurrentSchema(): void {
		$this->planner(IDBConnection::PLATFORM_POSTGRES)->dataTables();

		self::assertSame(
			'SELECT table_name AS name FROM information_schema.tables WHERE table_schema = current_schema() AND table_name LIKE ?',
			$this->queries[0][0]
		);
	}//end testPostgresQueriesTheCurrentSchema()

	/**
	 * An unsupported platform is refused before anything is queried.
	 *
	 * @return void
	 */
	public function testAnUnsupportedPlatformIsRefused(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabaseProvider')->willReturn('oracle');
		$db->expects($this->never())->method('executeQuery');
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('oc_');
		$planner = new LegacyRegisterPlanner($db, $config, $this->createMock(LoggerInterface::class));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('cannot list OpenRegister data tables on this database');
		$planner->dataTables();
	}//end testAnUnsupportedPlatformIsRefused()

	/**
	 * Backslash, underscore and percent in the prefix are escaped for LIKE,
	 * and an upper-case NAME column is read too.
	 *
	 * @return void
	 */
	public function testTheCustomPrefixIsEscapedAndStripped(): void {
		$tables = $this->planner(
			IDBConnection::PLATFORM_POSTGRES,
			[['NAME' => 'n%c\\openregister_table_7_8']],
			'n%c\\'
		)->dataTables();

		self::assertSame(['n\\%c\\\\openregister\\_table\\_%'], $this->queries[0][1]);
		self::assertSame([[7, 8, 'openregister_table_7_8']], $tables);
	}//end testTheCustomPrefixIsEscapedAndStripped()

	/**
	 * Only names of the exact data-table shape are listed; LIKE matches that
	 * are not, and rows without a name, are skipped.
	 *
	 * @return void
	 */
	public function testOnlyExactDataTableNamesAreListed(): void {
		$tables = $this->planner(
			IDBConnection::PLATFORM_POSTGRES,
			[
				['name' => 'oc_openregister_table_10_20'],
				['name' => 'oc_openregister_table_10_20_old'],
				['name' => 'oc_openregister_table_x_20'],
				['name' => 'oc_openregister_table_10'],
				['other' => 'oc_openregister_table_1_1'],
				['name' => 'xoc_openregister_table_1_1'],
				['name' => 'oc_openregister_table_2_3'],
			]
		)->dataTables();

		self::assertSame([[10, 20, 'openregister_table_10_20'], [2, 3, 'openregister_table_2_3']], $tables);
	}//end testOnlyExactDataTableNamesAreListed()
}//end class
