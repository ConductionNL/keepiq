<?php

/**
 * Keepiq TableStore Test
 *
 * A restore inserts archive rows back into the Keepiq tables. PostgreSQL
 * returns boolean columns as PHP booleans, the archive keeps them, and a
 * boolean bound as a string reaches PostgreSQL as '' and is refused
 * ("invalid input syntax for type boolean"). Found on a live restore,
 * 4 Oct 2026. Every value must be bound with the type it carries.
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

use OCA\Keepiq\Backup\TableStore;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Binding types on restore.
 */
class TableStoreTest extends TestCase {

	/**
	 * Booleans, integers and nulls are bound with their own type.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/vault-backups/spec.md#requirement-archives-are-verified-and-restored-from-the-command-line
	 */
	public function testRestoreBindsEachValueWithItsOwnType(): void {
		$bound = [];
		$qb = $this->createMock(originalClassName: IQueryBuilder::class);
		$qb->method('delete')->willReturnSelf();
		$qb->method('insert')->willReturnSelf();
		$qb->method('values')->willReturnSelf();
		$qb->method('executeStatement')->willReturn(1);
		$qb->method('createNamedParameter')->willReturnCallback(
			function ($value, $type = IQueryBuilder::PARAM_STR) use (&$bound): string {
				$bound[] = [$value, $type];
				return ':p' . count($bound);
			}
		);

		$db = $this->createMock(originalClassName: IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_SQLITE);

		$row = ['id' => 'abc', 'use_only' => false, 'is_favourite' => true, 'version' => 3, 'parent_id' => null];
		(new TableStore(db: $db))->replaceAll(
			rowsFor: function (string $table) use ($row): array {
				if ($table === 'secrets') {
					return [$row];
				}

				return [];
			}
		);

		self::assertSame(
			expected: [
				['abc', IQueryBuilder::PARAM_STR],
				[false, IQueryBuilder::PARAM_BOOL],
				[true, IQueryBuilder::PARAM_BOOL],
				[3, IQueryBuilder::PARAM_INT],
				[null, IQueryBuilder::PARAM_NULL],
			],
			actual: $bound
		);
	}//end testRestoreBindsEachValueWithItsOwnType()
}//end class
