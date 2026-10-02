<?php

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Migration;

use OCA\Keepiq\Migration\Version001004Date20261002120000;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Schema\IColumn;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the use-only and expiring-share columns (task 1.1).
 *
 * @spec openspec/changes/sharing-use-only-and-expiring-shares/tasks.md#task-1.1
 */
class UseOnlyExpiringSharesMigrationTest extends TestCase {

	/**
	 * Columns and indexes recorded per table.
	 *
	 * @var array<string,array{columns:array<string,array<string,mixed>>,indexes:array<string,array<int,string>>}>
	 */
	private array $recorded = [];

	/**
	 * A table double recording into $this->recorded, typed after whatever
	 * ISchemaWrapper::getTable() declares on this Nextcloud version.
	 *
	 * @param string $name     The table name.
	 * @param bool   $complete Whether the table already carries everything.
	 *
	 * @return object
	 */
	private function table(string $name, bool $complete): object {
		$this->recorded[$name] = ($this->recorded[$name] ?? ['columns' => [], 'indexes' => []]);
		$returnType = (new \ReflectionMethod(ISchemaWrapper::class, 'getTable'))->getReturnType();
		$class = ($returnType instanceof \ReflectionNamedType) ? $returnType->getName() : null;
		if ($class === null) {
			$this->markTestSkipped('getTable() declares no return type on this Nextcloud version');
		}

		$mock = $this->createMock($class);
		$mock->method('hasColumn')->willReturn($complete);
		$mock->method('hasIndex')->willReturn($complete);
		$mock->method('addColumn')->willReturnCallback(
			function (string $column, mixed $type, array $options = []) use ($name): object {
				$this->recorded[$name]['columns'][$column] = $options + [
					'type' => ($type instanceof \BackedEnum ? (string)$type->value : (string)$type),
				];
				return $this->createMock(IColumn::class);
			}
		);
		$mock->method('addIndex')->willReturnCallback(
			function (array $columns, ?string $index = null) use ($name, $mock): object {
				$this->recorded[$name]['indexes'][(string)$index] = $columns;
				return $mock;
			}
		);

		return $mock;
	}

	/**
	 * Run the migration against a schema whose tables are all present.
	 *
	 * @param bool $complete Whether every table already has the columns.
	 *
	 * @return ISchemaWrapper|null
	 */
	private function migrate(bool $complete): ?ISchemaWrapper {
		$tables = [];
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);
		$schema->method('getTable')->willReturnCallback(
			function (string $name) use (&$tables, $complete): object {
				$tables[$name] = ($tables[$name] ?? $this->table($name, $complete));
				return $tables[$name];
			}
		);

		return (new Version001004Date20261002120000())
			->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);
	}

	/**
	 * Every grant table gets use_only and expires_at; secrets gets use_only,
	 * access_expires_at and its index.
	 *
	 * @return void
	 */
	public function testAddsTheFlagsToEveryGrantTableAndTheSecrets(): void {
		$this->assertNotNull($this->migrate(complete: false));

		foreach (['keepiq_share_targets', 'keepiq_group_shares', 'keepiq_team_folder_members'] as $table) {
			$this->assertArrayHasKey('use_only', $this->recorded[$table]['columns'], $table);
			$this->assertArrayHasKey('expires_at', $this->recorded[$table]['columns'], $table);
			$this->assertFalse($this->recorded[$table]['columns']['use_only']['notnull']);
			$this->assertFalse($this->recorded[$table]['columns']['use_only']['default']);
		}

		$secrets = $this->recorded['keepiq_secrets'];
		$this->assertArrayHasKey('use_only', $secrets['columns']);
		$this->assertArrayHasKey('access_expires_at', $secrets['columns']);
		$this->assertArrayNotHasKey('expires_at', $secrets['columns'], 'secrets.expires_at is credential expiry and already exists');
		$this->assertSame(['access_expires_at'], $secrets['indexes']['keepiq_sec_access_exp_idx']);
	}

	/**
	 * A schema that already has everything is left alone.
	 *
	 * @return void
	 */
	public function testIsANoopWhenTheColumnsExist(): void {
		$this->assertNull($this->migrate(complete: true));
	}
}
