<?php

/**
 * The TOTP and passkeys migration adds the value columns and the partner's advertised value fields.
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

use OCA\Keepiq\Migration\Version001016Date20261028120000;
use OCA\Keepiq\Migration\Version001017Date20261028130000;
use OCA\Keepiq\Migration\Version001018Date20261111120000;
use OCA\Keepiq\Migration\Version001019Date20261111130000;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Migration step for totp, passkeys and value_fields.
 */
class TotpPasskeysColumnsMigrationTest extends TestCase {

	/**
	 * Each value table gets nullable `totp` and `passkeys` TEXT columns, and the partner table a nullable `value_fields`.
	 *
	 * @return void
	 */
	public function testAddsTheColumnsToEveryTable(): void {
		$added = [];
		$wrapper = $this->schemaDouble(added: $added);

		$result = (new Version001016Date20261028120000())->changeSchema($this->createMock(IOutput::class), fn () => $wrapper, []);
		$this->assertSame($wrapper, $result);

		foreach (['keepiq_secrets', 'keepiq_secret_versions', 'keepiq_federated_shares'] as $table) {
			$this->assertSame(['totp', 'passkeys'], array_keys($added[$table]), $table);
			$this->assertSame(['notnull' => false], $added[$table]['totp']);
			$this->assertSame(['notnull' => false], $added[$table]['passkeys']);
		}

		$this->assertSame(['value_fields' => ['notnull' => false, 'length' => 255]], $added['keepiq_federation_partners']);
		$this->assertCount(4, $added);
	}//end testAddsTheColumnsToEveryTable()

	/**
	 * A second run, on tables that already have the columns, changes nothing.
	 *
	 * @return void
	 */
	public function testIsANoopWhenTheColumnsExist(): void {
		$added = [];
		$wrapper = $this->schemaDouble(added: $added, exists: true);

		$this->assertNull((new Version001016Date20261028120000())->changeSchema($this->createMock(IOutput::class), fn () => $wrapper, []));
		$this->assertSame([], $added);
	}//end testIsANoopWhenTheColumnsExist()

	/**
	 * The secrets table gets nullable `totp_updated_at` and `passkeys_updated_at`, once.
	 *
	 * @return void
	 */
	public function testAddsTheChangeTimesToTheSecrets(): void {
		$added = [];
		$wrapper = $this->schemaDouble(added: $added);

		$step = new Version001017Date20261028130000();
		$this->assertSame($wrapper, $step->changeSchema($this->createMock(IOutput::class), fn () => $wrapper, []));
		$this->assertSame(
			['keepiq_secrets' => ['totp_updated_at' => ['notnull' => false], 'passkeys_updated_at' => ['notnull' => false]]],
			$added
		);

		$added = [];
		$this->assertNull($step->changeSchema($this->createMock(IOutput::class), fn () => $this->schemaDouble(added: $added, exists: true), []));
		$this->assertSame([], $added);
	}//end testAddsTheChangeTimesToTheSecrets()

	/**
	 * The passkey credentials get a `sign_count` starting at 0, once.
	 *
	 * @return void
	 */
	public function testAddsTheSignCounterToThePasskeys(): void {
		$added = [];
		$wrapper = $this->schemaDouble(added: $added);

		$step = new Version001018Date20261111120000();
		$this->assertSame($wrapper, $step->changeSchema($this->createMock(IOutput::class), fn () => $wrapper, []));
		$this->assertSame(
			['keepiq_passkey_credentials' => ['sign_count' => ['notnull' => true, 'default' => 0, 'unsigned' => true]]],
			$added
		);

		$added = [];
		$this->assertNull($step->changeSchema($this->createMock(IOutput::class), fn () => $this->schemaDouble(added: $added, exists: true), []));
		$this->assertSame([], $added);
	}//end testAddsTheSignCounterToThePasskeys()

	/**
	 * The credentials get `token_id` and `user_handle`, and the challenge
	 * table is created with its binding index; a second run changes nothing.
	 *
	 * @return void
	 */
	public function testAddsTheExtensionChallengeTableAndBinding(): void {
		$added = [];
		$wrapper = $this->createMock(ISchemaWrapper::class);
		$wrapper->method('hasTable')->willReturnCallback(
			static fn (string $name): bool => $name === Version001019Date20261111130000::CREDENTIALS
		);
		$table = function (string $name) use (&$added): object {
			return $this->tableDouble(name: $name, added: $added, exists: false);
		};
		$wrapper->method('getTable')->willReturnCallback($table);
		$wrapper->method('createTable')->willReturnCallback($table);

		$step = new Version001019Date20261111130000(db: $this->createMock(IDBConnection::class));
		$this->assertSame($wrapper, $step->changeSchema($this->createMock(IOutput::class), fn () => $wrapper, []));
		$this->assertSame(
			['token_id' => ['notnull' => false], 'user_handle' => ['notnull' => false, 'length' => 128]],
			$added[Version001019Date20261111130000::CREDENTIALS]
		);
		$this->assertSame(
			['id', 'uid', 'token_id', 'purpose', 'challenge', 'assertion_challenge', 'expires_at'],
			array_keys($added[Version001019Date20261111130000::CHALLENGES])
		);
	}//end testAddsTheExtensionChallengeTableAndBinding()

	/**
	 * After the schema change, active extension credentials without an app
	 * password binding are marked stale; web credentials are not touched.
	 *
	 * @return void
	 */
	public function testMarksUnboundExtensionCredentialsStale(): void {
		$conditions = [];
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(static function (string $column, mixed $value) use (&$conditions): string {
			$conditions[] = $column . '=' . $value;
			return $column;
		});
		$expr->method('isNull')->willReturnCallback(static function (string $column) use (&$conditions): string {
			$conditions[] = $column . ' IS NULL';
			return $column;
		});
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->expects($this->once())->method('update')->with(Version001019Date20261111130000::CREDENTIALS)->willReturnSelf();
		$qb->expects($this->once())->method('set')->with('status', 'stale')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->expects($this->once())->method('executeStatement')->willReturn(0);
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturn($qb);

		(new Version001019Date20261111130000(db: $db))->postSchemaChange($this->createMock(IOutput::class), fn () => null, []);

		$this->assertSame(['client_kind=extension', 'status=active', 'token_id IS NULL'], $conditions);
	}//end testMarksUnboundExtensionCredentialsStale()

	/**
	 * Missing tables are skipped, and with none present nothing changes.
	 *
	 * @return void
	 */
	public function testSkipsMissingTables(): void {
		$wrapper = $this->createMock(ISchemaWrapper::class);
		$wrapper->method('hasTable')->willReturn(false);
		$wrapper->expects($this->never())->method('getTable');

		$this->assertNull((new Version001016Date20261028120000())->changeSchema($this->createMock(IOutput::class), fn () => $wrapper, []));
	}//end testSkipsMissingTables()

	/**
	 * A schema double whose getTable() hands out one recording table per name.
	 *
	 * @param array<string,array<string,array<string,mixed>>> $added  Receives table => column => options
	 * @param bool                                            $exists Whether hasColumn() answers true
	 *
	 * @return ISchemaWrapper
	 */
	private function schemaDouble(array &$added, bool $exists = false): ISchemaWrapper {
		$wrapper = $this->createMock(ISchemaWrapper::class);
		$wrapper->method('hasTable')->willReturn(true);
		$wrapper->method('getTable')->willReturnCallback(
			function (string $name) use (&$added, $exists): object {
				return $this->tableDouble(name: $name, added: $added, exists: $exists);
			}
		);
		return $wrapper;
	}//end schemaDouble()

	/**
	 * A table double of whatever type ISchemaWrapper::getTable() declares,
	 * recording addColumn() calls under the table name (see ConsolidatedSchemaMigrationTest for why
	 * the type is read from the signature).
	 *
	 * @param string                                          $name   The table name
	 * @param array<string,array<string,array<string,mixed>>> $added  Receives table => column => options
	 * @param bool                                            $exists Whether hasColumn() answers true
	 *
	 * @return object
	 */
	private function tableDouble(string $name, array &$added, bool $exists): object {
		$type = (new \ReflectionMethod(ISchemaWrapper::class, 'getTable'))->getReturnType();
		if ($type instanceof \ReflectionNamedType === false) {
			// Nextcloud 34 and older declare no return type, so PHPUnit enforces
			// none: a recording object stands in, as a mock of stdClass cannot
			// have hasColumn() configured.
			return $this->recordingTable(name: $name, added: $added, exists: $exists);
		}

		$class = $type->getName();
		$mock = $this->getMockBuilder($class)->disableOriginalConstructor()->getMock();
		$mock->method('hasColumn')->willReturn($exists);
		$addReturn = (new \ReflectionMethod($class, 'addColumn'))->getReturnType();
		$mock->method('addColumn')->willReturnCallback(
			function (string $column, mixed $colType, array $options = []) use (&$added, $name, $addReturn): mixed {
				$added[$name][$column] = $options;
				if ($addReturn instanceof \ReflectionNamedType && $addReturn->isBuiltin() === false) {
					return $this->createMock($addReturn->getName());
				}

				return null;
			}
		);
		return $mock;
	}//end tableDouble()

	/**
	 * A plain table stand-in recording addColumn() calls under the table name.
	 *
	 * @param string                                          $name   The table name
	 * @param array<string,array<string,array<string,mixed>>> $added  Receives table => column => options
	 * @param bool                                            $exists Whether hasColumn() answers true
	 *
	 * @return object
	 */
	private function recordingTable(string $name, array &$added, bool $exists): object {
		return new class($name, $added, $exists) {
			/** @var array<string,array<string,array<string,mixed>>> */
			private array $added;

			/**
			 * @param array<string,array<string,array<string,mixed>>> $added
			 */
			public function __construct(private string $name, array &$added, private bool $exists) {
				$this->added = &$added;
			}

			public function hasColumn(string $column): bool {
				return $this->exists;
			}

			/**
			 * @param array<string,mixed> $options
			 */
			public function addColumn(string $column, mixed $type, array $options = []): void {
				$this->added[$this->name][$column] = $options;
			}
		};
	}//end recordingTable()
}//end class
