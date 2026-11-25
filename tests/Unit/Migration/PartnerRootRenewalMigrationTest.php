<?php

/**
 * The announced root renewal migration adds the signature column to the CA table and the renewal columns to the
 * federation partners.
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
 *
 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-announced-renewal-is-offered-to-administrators-after-its-signature-checks-out
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Migration;

use OCA\Keepiq\Migration\Version001023Date20261125120000;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Migration step for the announced root renewals.
 */
class PartnerRootRenewalMigrationTest extends TestCase {

	/**
	 * Every column is added nullable, the signature as TEXT and the fingerprint as 64 characters.
	 *
	 * @return void
	 */
	public function testAddsTheNullableColumns(): void {
		$added = [];
		$wrapper = $this->schemaDouble(added: $added, exists: false);

		$result = (new Version001023Date20261125120000())->changeSchema($this->createMock(IOutput::class), fn () => $wrapper, []);

		$this->assertSame($wrapper, $result);
		$this->assertSame(['predecessor_signature' => [Types::TEXT, ['notnull' => false]]], $added['keepiq_ca_certs']);
		$this->assertSame(
			[
				'pending_root_fingerprint' => [Types::STRING, ['notnull' => false, 'length' => 64]],
				'pending_root_verified_at' => [Types::DATETIME, ['notnull' => false]],
				'pending_root_window_ends_at' => [Types::DATETIME, ['notnull' => false]],
				'pending_root_reminded' => [Types::SMALLINT, ['notnull' => false]],
				'renewal_notice_attempts' => [Types::SMALLINT, ['notnull' => false]],
				'renewal_notice_next_at' => [Types::DATETIME, ['notnull' => false]],
			],
			$added['keepiq_federation_partners']
		);
		$this->assertCount(2, $added);
	}//end testAddsTheNullableColumns()

	/**
	 * A second run, on tables that have the columns, changes nothing; missing tables are skipped.
	 *
	 * @return void
	 */
	public function testIsANoopWhenTheColumnsExistOrTheTablesAreMissing(): void {
		$added = [];
		$step = new Version001023Date20261125120000();

		$this->assertNull($step->changeSchema($this->createMock(IOutput::class), fn () => $this->schemaDouble(added: $added, exists: true), []));
		$this->assertSame([], $added);

		$empty = $this->createMock(ISchemaWrapper::class);
		$empty->method('hasTable')->willReturn(false);
		$empty->expects($this->never())->method('getTable');
		$this->assertNull($step->changeSchema($this->createMock(IOutput::class), fn () => $empty, []));
	}//end testIsANoopWhenTheColumnsExistOrTheTablesAreMissing()

	/**
	 * A schema double whose getTable() hands out one recording table per name.
	 *
	 * @param array<string,array<string,array{0:string,1:array<string,mixed>}>> $added Receives table => column => [type, options]
	 * @param bool $exists Whether hasColumn() answers true
	 *
	 * @return ISchemaWrapper
	 */
	private function schemaDouble(array &$added, bool $exists): ISchemaWrapper {
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
	 * A table double of whatever type ISchemaWrapper::getTable() declares, recording addColumn() calls (see
	 * ConsolidatedSchemaMigrationTest for why the type is read from the signature).
	 *
	 * @param string $name The table name
	 * @param array<string,array<string,array{0:string,1:array<string,mixed>}>> $added Receives the added columns
	 * @param bool $exists Whether hasColumn() answers true
	 *
	 * @return object
	 */
	private function tableDouble(string $name, array &$added, bool $exists): object {
		$type = (new \ReflectionMethod(ISchemaWrapper::class, 'getTable'))->getReturnType();
		if ($type instanceof \ReflectionNamedType === false) {
			return new class($name, $added, $exists) {
				/** @var array<string,array<string,array{0:string,1:array<string,mixed>}>> */
				private array $added;

				/**
				 * @param array<string,array<string,array{0:string,1:array<string,mixed>}>> $added
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
				public function addColumn(string $column, string $type, array $options = []): void {
					$this->added[$this->name][$column] = [$type, $options];
				}
			};
		}

		$class = $type->getName();
		$mock = $this->getMockBuilder($class)->disableOriginalConstructor()->getMock();
		$mock->method('hasColumn')->willReturn($exists);
		$addReturn = (new \ReflectionMethod($class, 'addColumn'))->getReturnType();
		$mock->method('addColumn')->willReturnCallback(
			function (string $column, mixed $colType, array $options = []) use (&$added, $name, $addReturn): mixed {
				$added[$name][$column] = [$colType, $options];
				if ($addReturn instanceof \ReflectionNamedType && $addReturn->isBuiltin() === false) {
					return $this->createMock($addReturn->getName());
				}

				return null;
			}
		);

		return $mock;
	}//end tableDouble()
}//end class
