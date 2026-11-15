<?php

/**
 * Unit tests for the MCP metadata allow-list projection.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Mcp
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

namespace OCA\Keepiq\Tests\Unit\Mcp;

use InvalidArgumentException;
use OCA\Keepiq\Mcp\MetadataAllowList;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * MetadataAllowList::project keeps allow-listed scalar keys in list order.
 *
 * @covers \OCA\Keepiq\Mcp\MetadataAllowList
 */
class MetadataAllowListTest extends TestCase {

	/**
	 * The output follows the allow-list order, not the row order.
	 *
	 * @return void
	 */
	public function testOutputFollowsAllowListOrder(): void {
		$row = ['expired' => false, 'name' => 'n', 'daysRemaining' => 3, 'id' => 'x', 'expiresAt' => '2026-01-01T00:00:00+00:00'];

		$this->assertSame(
			['id' => 'x', 'name' => 'n', 'expiresAt' => '2026-01-01T00:00:00+00:00', 'daysRemaining' => 3, 'expired' => false],
			(new MetadataAllowList())->project(row: $row, type: 'expiringSecret')
		);
	}//end testOutputFollowsAllowListOrder()

	/**
	 * A null value is kept; arrays and objects are dropped; absent keys stay absent.
	 *
	 * @return void
	 */
	public function testNullKeptNonScalarsDroppedAbsentKeysOmitted(): void {
		$row = ['id' => 'f1', 'name' => null, 'reason' => ['nested' => 'x'], 'status' => new stdClass(), 'flaggedAt' => 0];

		$this->assertSame(
			['id' => 'f1', 'name' => null, 'flaggedAt' => 0],
			(new MetadataAllowList())->project(row: $row, type: 'rotationFlag')
		);
	}//end testNullKeptNonScalarsDroppedAbsentKeysOmitted()

	/**
	 * Each result type projects a full row onto exactly its own keys.
	 *
	 * @return void
	 */
	public function testEachTypeProjectsOntoItsOwnKeys(): void {
		$all = [];
		foreach (MetadataAllowList::KEYS as $keys) {
			foreach ($keys as $key) {
				$all[$key] = 'v-' . $key;
			}
		}

		$all['key'] = 'CIPHER';
		$all['encryptionSuiteId'] = 'suite';
		foreach (MetadataAllowList::KEYS as $type => $keys) {
			$this->assertSame($keys, array_keys((new MetadataAllowList())->project(row: $all, type: $type)), $type);
		}
	}//end testEachTypeProjectsOntoItsOwnKeys()

	/**
	 * A result type that is not on the list is refused with its name.
	 *
	 * @return void
	 */
	public function testUnknownTypeIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown MCP result type: secret');
		(new MetadataAllowList())->project(row: ['id' => 'x'], type: 'secret');
	}//end testUnknownTypeIsRefused()
}//end class
