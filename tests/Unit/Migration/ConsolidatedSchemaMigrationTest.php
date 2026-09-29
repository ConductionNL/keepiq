<?php

/**
 * Unit tests for the consolidated schema migration.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Migration
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

namespace OCA\Keepiq\Tests\Unit\Migration;

use InvalidArgumentException;
use OCA\Keepiq\Migration\Version001000Date20260908000000;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Schema\IColumn;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * A stand-in for the Doctrine table object the schema hands back.
 *
 * Doctrine is only on the autoloader when this app sits inside a server
 * checkout, so a test that built a real Table would pass in CI and fail
 * on a standalone clone. ISchemaWrapper::getTable() declares no return
 * type, so a double keeps these tests hermetic in both places.
 */
final class FakeTable {
	/** @var array<int,string> */
	public array $columns = [];

	/** @var array<int,string> */
	public array $indexes = [];

	/** @var array<int,string> */
	public array $uniqueIndexes = [];

	/** @var array<int,string> */
	public array $primary = [];

	public bool $hasEverything = false;

	public function hasColumn(string $name): bool {
		return $this->hasEverything;
	}

	/**
	 * @param array<string,mixed> $options
	 */
	public function addColumn(string $name, string $type, array $options = []): void {
		$this->columns[] = $name;
	}

	public function hasPrimaryKey(): bool {
		return $this->hasEverything;
	}

	/**
	 * @param array<int,string> $columns
	 */
	public function setPrimaryKey(array $columns): void {
		$this->primary = $columns;
	}

	public function hasIndex(string $name): bool {
		return $this->hasEverything;
	}

	/**
	 * @param array<int,string> $columns
	 */
	public function addIndex(array $columns, string $name): void {
		$this->indexes[] = $name;
	}

	/**
	 * @param array<int,string> $columns
	 */
	public function addUniqueIndex(array $columns, string $name): void {
		$this->uniqueIndexes[] = $name;
	}
}

/**
 * Tests for Version001000Date20260908000000.
 */
class ConsolidatedSchemaMigrationTest extends TestCase {
	/**
	 * Read a private constant off the migration.
	 *
	 * @param string $name Constant name.
	 *
	 * @return mixed
	 */
	private function constant(string $name): mixed {
		return (new ReflectionClass(Version001000Date20260908000000::class))->getConstant($name);
	}

	/**
	 * The table double the migration is handed, recording into $fake.
	 *
	 * Nextcloud 35 gave `ISchemaWrapper::createTable()` and `getTable()` a
	 * declared return type of `OCP\DB\Schema\ITable`, so a mock of
	 * `ISchemaWrapper` refuses to hand back a plain `FakeTable`:
	 *
	 *   TypeError: createTable(): Return value must be of type
	 *   OCP\DB\Schema\ITable, FakeTable returned
	 *
	 * `FakeTable implements ITable` is not available as a fix: this app declares
	 * `min-version="32"`, and `ITable` — along with `IColumn`, `IIndex` and the
	 * `ColumnType` enum in its signatures — exists only from 35. Declaring it
	 * unconditionally would fatal on every older leg, and hand-writing its 25
	 * methods would pin this test to one revision of an interface it does not own.
	 *
	 * So on 35 the migration is handed a GENERATED mock of the real interface,
	 * which cannot drift from it, delegating the seven methods the migration
	 * actually calls to the same `FakeTable` the assertions read. Below 35 the
	 * interface does not exist and the `FakeTable` is passed through unchanged.
	 *
	 * @param FakeTable $fake The recorder the assertions read.
	 *
	 * @return object The double to hand the migration.
	 */
	private function tableDouble(FakeTable $fake): object {
		// Ask the SIGNATURE, not the autoloader.
		//
		// This used to branch on `interface_exists(ITable::class)`, which asks
		// "has this interface been loaded yet" — a question whose answer depends
		// on autoloader state and test order, not on the platform. It answered
		// true on one stable35 run and false on the next, with no code change
		// between them, so the same tests passed and then failed for reasons
		// that had nothing to do with what they assert (keepiq#712).
		//
		// The question that actually decides this is what the mocked method is
		// DECLARED to return, because that is what PHPUnit enforces. Nextcloud
		// 34 declares no return type on `createTable()`; 35 declares `ITable`.
		// Reflection over the interface under test answers it exactly, on any
		// version, in any order.
		$returnType = (new \ReflectionMethod(ISchemaWrapper::class, 'createTable'))->getReturnType();
		if ($returnType instanceof \ReflectionNamedType === false) {
			return $fake;
		}

		// No interface_exists() probe on the resolved name, deliberately: if the
		// signature declares it, PHPUnit will enforce it, so the mock has to be
		// of that type or nothing works. Probing here could disagree with the
		// signature and hand back a FakeTable the mock then refuses — which is
		// the exact failure this method exists to prevent.
		$mock = $this->createMock($returnType->getName());
		$mock->method('hasColumn')->willReturnCallback(static fn (string $n): bool => $fake->hasColumn($n));
		$mock->method('hasPrimaryKey')->willReturnCallback(static fn (): bool => $fake->hasPrimaryKey());
		$mock->method('hasIndex')->willReturnCallback(static fn (string $n): bool => $fake->hasIndex($n));

		$mock->method('addColumn')->willReturnCallback(
			function (string $name, mixed $type, array $options = []) use ($fake): object {
				// `ColumnType` is a backed enum on 35 and a plain string before.
				$fake->addColumn(
					$name,
					($type instanceof \BackedEnum ? (string)$type->value : (string)$type),
					$options
				);

				// `IColumn` exists only from Nextcloud 35. phpstan resolves it from
				// the vendored `nextcloud/ocp` DEV stub, and whether that stub
				// carries `OCP/DB/Schema/` depends on which ocp the checkout
				// installed — so the symbol is ignored in phpstan.neon with
				// `reportUnmatched: false`, which is correct in both worlds. The
				// RUNTIME class is the server's, never the stub's, and this line
				// only executes on 35.
				return $this->createMock(IColumn::class);
			}
		);

		$mock->method('setPrimaryKey')->willReturnCallback(
			static function (array $columns, string|false $indexName = false) use ($fake, $mock): object {
				$fake->setPrimaryKey($columns);
				return $mock;
			}
		);

		$mock->method('addIndex')->willReturnCallback(
			static function (array $columns, ?string $name = null, array $flags = [], array $options = []) use ($fake, $mock): object {
				$fake->addIndex($columns, (string)$name);
				return $mock;
			}
		);

		$mock->method('addUniqueIndex')->willReturnCallback(
			static function (array $columns, ?string $name = null, array $options = []) use ($fake, $mock): object {
				$fake->addUniqueIndex($columns, (string)$name);
				return $mock;
			}
		);

		return $mock;

	}//end tableDouble()

	/**
	 * Build the migration with the two doubles it needs.
	 *
	 * @param IDBConnection $db     Connection double.
	 * @param string        $prefix Table prefix the config reports.
	 *
	 * @return Version001000Date20260908000000
	 */
	private function migration(IDBConnection $db, string $prefix = 'oc_'): Version001000Date20260908000000 {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn($prefix);

		return new Version001000Date20260908000000($db, $config);
	}

	/**
	 * A fresh install gets every declared table, column and index.
	 *
	 * @return void
	 */
	public function testChangeSchemaBuildsTheWholeSchemaOnAFreshInstall(): void {
		$tables = [];
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(false);
		$schema->method('createTable')->willReturnCallback(
			function (string $name) use (&$tables): object {
				$tables[$name] = new FakeTable();
				return $this->tableDouble($tables[$name]);
			}
		);
		$schema->method('getTable')->willReturnCallback(
			function (string $name) use (&$tables): object {
				return $this->tableDouble($tables[$name]);
			}
		);

		$result = $this->migration($this->createMock(IDBConnection::class))
			->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);

		$this->assertSame($schema, $result);

		$declared = $this->constant('SCHEMA');
		$this->assertCount(count($declared), $tables, 'every declared table must be created');

		$columns = array_sum(array_map(static fn (FakeTable $t): int => count($t->columns), $tables));
		$indexes = array_sum(
			array_map(static fn (FakeTable $t): int => count($t->indexes) + count($t->uniqueIndexes), $tables)
		);
		$expectedColumns = array_sum(array_map(static fn (array $d): int => count($d['columns']), $declared));
		$expectedIndexes = array_sum(
			array_map(static fn (array $d): int => count($d['indexes']) + count($d['uniqueIndexes']), $declared)
		);

		$this->assertSame($expectedColumns, $columns);
		$this->assertSame($expectedIndexes, $indexes);

		foreach ($tables as $name => $table) {
			$this->assertNotSame([], $table->primary, $name.' must get a primary key');
			$this->assertStringStartsWith('keepiq_', $name, 'every table must carry the new prefix');
		}
	}

	/**
	 * A table that already carries everything is left untouched.
	 *
	 * @return void
	 */
	public function testChangeSchemaAddsNothingToATableThatIsAlreadyComplete(): void {
		$table = new FakeTable();
		$table->hasEverything = true;

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);
		$schema->expects($this->never())->method('createTable');
		$schema->method('getTable')->willReturn($this->tableDouble($table));

		$this->migration($this->createMock(IDBConnection::class))
			->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);

		$this->assertSame([], $table->columns);
		$this->assertSame([], $table->indexes);
		$this->assertSame([], $table->uniqueIndexes);
		$this->assertSame([], $table->primary);
	}

	/**
	 * Every legacy table still carrying the old prefix is renamed once.
	 *
	 * @return void
	 */
	public function testPreSchemaChangeRenamesEveryLegacyTable(): void {
		$statements = [];
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_SQLITE);
		$db->method('executeStatement')->willReturnCallback(
			static function (string $sql) use (&$statements): int {
				$statements[] = $sql;
				return 0;
			}
		);

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturnCallback(
			static fn (string $name): bool => str_starts_with($name, 'doriath_')
		);

		$this->migration($db)->preSchemaChange($this->createMock(IOutput::class), static fn () => $schema, []);

		$this->assertCount(count($this->constant('TABLES')), $statements);
		foreach ($statements as $sql) {
			$this->assertStringContainsString('ALTER TABLE', $sql);
			$this->assertStringContainsString('*PREFIX*doriath_', $sql);
			$this->assertStringContainsString('*PREFIX*keepiq_', $sql);
		}
	}

	/**
	 * An install that has already been renamed is not renamed again.
	 *
	 * @return void
	 */
	public function testPreSchemaChangeIsANoopWhenTheNewNamesAlreadyExist(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->never())->method('executeStatement');

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);

		$this->migration($db)->preSchemaChange($this->createMock(IOutput::class), static fn () => $schema, []);
	}

	/**
	 * MySQL takes RENAME TABLE, which is the one platform that does not take
	 * the ALTER TABLE form used everywhere else.
	 *
	 * @return void
	 */
	public function testMysqlUsesRenameTable(): void {
		$statements = [];
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_MYSQL);
		$db->method('executeStatement')->willReturnCallback(
			static function (string $sql) use (&$statements): int {
				$statements[] = $sql;
				return 0;
			}
		);
		$db->expects($this->never())->method('executeQuery');

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturnCallback(
			static fn (string $name): bool => str_starts_with($name, 'doriath_')
		);

		$this->migration($db)->preSchemaChange($this->createMock(IOutput::class), static fn () => $schema, []);

		$this->assertNotSame([], $statements);
		foreach ($statements as $sql) {
			$this->assertStringStartsWith('RENAME TABLE', $sql);
		}
	}

	/**
	 * PostgreSQL also moves the sequence and the primary-key constraint, which
	 * a table rename leaves behind and the schema comparison does not fix.
	 *
	 * @return void
	 */
	public function testPostgresAlsoRenamesSequencesAndPrimaryKeys(): void {
		$statements = [];
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_POSTGRES);
		$db->method('executeStatement')->willReturnCallback(
			static function (string $sql) use (&$statements): int {
				$statements[] = $sql;
				return 0;
			}
		);
		$db->method('executeQuery')->willReturnCallback(
			function (string $sql, array $params) {
				$rows = str_contains($sql, 'pg_sequences')
					? [['name' => 'oc_doriath_audit_log_id_seq']]
					: [['name' => 'oc_doriath_audit_log_pkey']];
				$result = $this->createMock(\OCP\DB\IResult::class);
				$result->method('fetchAll')->willReturn($rows);
				return $result;
			}
		);

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturnCallback(
			static fn (string $name): bool => $name === 'doriath_audit_log'
		);

		$this->migration($db)->preSchemaChange($this->createMock(IOutput::class), static fn () => $schema, []);

		$joined = implode("\n", $statements);
		$this->assertStringContainsString('ALTER TABLE "*PREFIX*doriath_audit_log" RENAME TO "*PREFIX*keepiq_audit_log"', $joined);
		$this->assertStringContainsString('ALTER SEQUENCE "oc_doriath_audit_log_id_seq" RENAME TO "oc_keepiq_audit_log_id_seq"', $joined);
		$this->assertStringContainsString('RENAME CONSTRAINT "oc_doriath_audit_log_pkey" TO "oc_keepiq_audit_log_pkey"', $joined);
	}

	/**
	 * The identifier guard refuses a name that is not a bare identifier.
	 *
	 * @return void
	 */
	public function testANonIdentifierTableNameIsRefused(): void {
		$migration = $this->migration($this->createMock(IDBConnection::class));
		$rename = (new ReflectionClass($migration))->getMethod('renameTable');
		$rename->setAccessible(true);

		$this->expectException(InvalidArgumentException::class);
		$rename->invoke($migration, 'ok_name', 'bad"; DROP TABLE x; --');
	}

	/**
	 * The declared tables and the mapper table names are the same set.
	 *
	 * These are two independently maintained lists. A table renamed in the
	 * migration but not in its mapper leaves the mapper querying a table that
	 * does not exist, which is a runtime error rather than a failing build.
	 *
	 * @return void
	 */
	public function testTheDeclaredTablesMatchTheMapperTableNames(): void {
		$declared = array_map(
			static fn (string $suffix): string => 'keepiq_'.$suffix,
			array_keys($this->constant('SCHEMA'))
		);

		$fromMappers = [];
		foreach (glob(__DIR__.'/../../../lib/Db/*.php') ?: [] as $file) {
			if (preg_match_all("/tableName:\s*'([a-z0-9_]+)'/", (string) file_get_contents($file), $m) === 0) {
				continue;
			}

			$fromMappers = array_merge($fromMappers, $m[1]);
		}

		$fromMappers = array_values(array_unique($fromMappers));
		sort($declared);
		sort($fromMappers);

		$this->assertNotSame([], $fromMappers, 'the mappers must declare table names for this test to mean anything');
		$this->assertSame($declared, $fromMappers);
	}
}
