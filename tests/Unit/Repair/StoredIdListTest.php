<?php

/**
 * Unit tests for StoredIdList.
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

use OCA\Keepiq\Repair\StoredIdList;
use PHPUnit\Framework\TestCase;

/**
 * Tests for StoredIdList.
 *
 * @covers \OCA\Keepiq\Repair\StoredIdList
 */
class StoredIdListTest extends TestCase {

	private StoredIdList $list;

	protected function setUp(): void {
		$this->list = new StoredIdList();
	}//end setUp()

	/**
	 * A stored JSON list decodes to a re-indexed list.
	 *
	 * @return void
	 */
	public function testDecodeReindexesAStoredList(): void {
		$this->assertSame([3, '4', 'x'], $this->list->decode('{"a":3,"b":"4","c":"x"}'));
		$this->assertSame([1, 2], $this->list->decode('[1,2]'));
	}//end testDecodeReindexesAStoredList()

	/**
	 * Nothing stored, or something that is not a JSON array, decodes to an empty list.
	 *
	 * @return void
	 */
	public function testDecodeOfNothingOrGarbageIsEmpty(): void {
		$this->assertSame([], $this->list->decode(null));
		$this->assertSame([], $this->list->decode(''));
		$this->assertSame([], $this->list->decode('not json'));
		$this->assertSame([], $this->list->decode('42'));
	}//end testDecodeOfNothingOrGarbageIsEmpty()

	/**
	 * Only integers and digit-only strings count as ids, cast to int.
	 *
	 * @return void
	 */
	public function testNumericIdsKeepsIntegersAndDigitStrings(): void {
		$this->assertSame(
			[7, 12, 0],
			$this->list->numericIds([7, '12', '0', '-3', '1.5', 'abc', 2.0, null, true, ['9']])
		);
	}//end testNumericIdsKeepsIntegersAndDigitStrings()

	/**
	 * Removing ids drops their numeric entries in either spelling and keeps everything else in order.
	 *
	 * @return void
	 */
	public function testWithoutDropsTheGivenIdsAndKeepsNonIds(): void {
		$this->assertSame(
			['uuid-a', 3, '-1'],
			$this->list->without([1, 'uuid-a', '2', 3, '-1'], [1, 2])
		);
	}//end testWithoutDropsTheGivenIdsAndKeepsNonIds()

	/**
	 * Removing no ids keeps the list as it was, re-indexed.
	 *
	 * @return void
	 */
	public function testWithoutNothingKeepsTheList(): void {
		$this->assertSame([1, '2'], $this->list->without([5 => 1, 9 => '2'], []));
	}//end testWithoutNothingKeepsTheList()
}//end class
