<?php

/**
 * The SIEM connector migration adds three columns and leaves old sinks on JSON.
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

use OCA\Keepiq\Db\SiemSink;
use OCA\Keepiq\Migration\Version001004Date20261002170000;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Migration step for format, credential_enc and connector_options.
 *
 * @spec openspec/specs/siem-vendor-connectors/spec.md
 */
class SiemConnectorColumnsMigrationTest extends TestCase {

	/**
	 * The step adds the three columns with the defaults the design names,
	 * once, and a sink without a stored format reads back as json.
	 *
	 * @return void
	 */
	public function testAddsTheThreeColumns(): void {
		$added = [];
		$table = $this->tableDouble(added: $added);

		$wrapper = $this->createMock(ISchemaWrapper::class);
		$wrapper->method('hasTable')->willReturn(true);
		$wrapper->method('getTable')->willReturn($table);

		$step = new Version001004Date20261002170000();
		$result = $step->changeSchema($this->createMock(IOutput::class), fn () => $wrapper, []);
		$this->assertSame($wrapper, $result);

		$this->assertSame(['format', 'credential_enc', 'connector_options'], array_keys($added));
		$this->assertSame(['notnull' => true, 'length' => 16, 'default' => 'json'], $added['format']);
		$this->assertSame(['notnull' => false], $added['credential_enc']);
		$this->assertSame(['notnull' => false], $added['connector_options']);

		// A sink loaded from an existing row has no stored format yet.
		$this->assertSame('json', (new SiemSink())->getFormat());
	}//end testAddsTheThreeColumns()

	/**
	 * A second run, on a table that already has the columns, changes nothing.
	 *
	 * @return void
	 */
	public function testIsANoopWhenTheColumnsExist(): void {
		$added = [];
		$table = $this->tableDouble(added: $added, exists: true);
		$wrapper = $this->createMock(ISchemaWrapper::class);
		$wrapper->method('hasTable')->willReturn(true);
		$wrapper->method('getTable')->willReturn($table);

		$this->assertNull((new Version001004Date20261002170000())->changeSchema($this->createMock(IOutput::class), fn () => $wrapper, []));
		$this->assertSame([], $added);
	}//end testIsANoopWhenTheColumnsExist()

	/**
	 * A table double of whatever type ISchemaWrapper::getTable() declares,
	 * recording addColumn() calls (see ConsolidatedSchemaMigrationTest for why
	 * the type is read from the signature).
	 *
	 * @param array<string,array<string,mixed>> $added Receives name => options
	 * @param bool $exists Whether hasColumn() answers true
	 *
	 * @return object
	 */
	private function tableDouble(array &$added, bool $exists = false): object {
		$type = (new \ReflectionMethod(ISchemaWrapper::class, 'getTable'))->getReturnType();
		if ($type instanceof \ReflectionNamedType === false) {
			// Nextcloud 34 and older declare no return type, so PHPUnit enforces
			// none: a recording object stands in, as a mock of stdClass cannot
			// have hasColumn() configured.
			return $this->recordingTable(added: $added, exists: $exists);
		}

		$class = $type->getName();
		$mock = $this->getMockBuilder($class)->disableOriginalConstructor()->getMock();
		$mock->method('hasColumn')->willReturn($exists);
		$addReturn = (new \ReflectionMethod($class, 'addColumn'))->getReturnType();
		$mock->method('addColumn')->willReturnCallback(
			function (string $name, mixed $colType, array $options = []) use (&$added, $addReturn): mixed {
				$added[$name] = $options;
				if ($addReturn instanceof \ReflectionNamedType && $addReturn->isBuiltin() === false) {
					return $this->createMock($addReturn->getName());
				}

				return null;
			}
		);
		return $mock;
	}//end tableDouble()

	/**
	 * A plain table stand-in recording addColumn() calls.
	 *
	 * @param array<string,array<string,mixed>> $added  Receives name => options
	 * @param bool                              $exists Whether hasColumn() answers true
	 *
	 * @return object
	 */
	private function recordingTable(array &$added, bool $exists): object {
		return new class($added, $exists) {
			/** @var array<string,array<string,mixed>> */
			private array $added;

			/**
			 * @param array<string,array<string,mixed>> $added
			 */
			public function __construct(array &$added, private bool $exists) {
				$this->added = &$added;
			}

			public function hasColumn(string $name): bool {
				return $this->exists;
			}

			/**
			 * @param array<string,mixed> $options
			 */
			public function addColumn(string $name, mixed $type, array $options = []): void {
				$this->added[$name] = $options;
			}

			/**
			 * @param array<int,string> $columns
			 */
			public function setPrimaryKey(array $columns): self {
				return $this;
			}

			/**
			 * @param array<int,string> $columns
			 */
			public function addIndex(array $columns, ?string $name = null): self {
				return $this;
			}

			/**
			 * @param array<int,string> $columns
			 */
			public function addUniqueIndex(array $columns, ?string $name = null): self {
				return $this;
			}
		};
	}//end recordingTable()
}//end class
