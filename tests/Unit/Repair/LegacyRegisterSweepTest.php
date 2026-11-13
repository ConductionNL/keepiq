<?php

/**
 * Unit tests for LegacyRegisterSweep.
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

use OCA\Keepiq\Repair\LegacyRegisterSweep;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LegacyRegisterSweep.
 *
 * @covers \OCA\Keepiq\Repair\LegacyRegisterSweep
 */
class LegacyRegisterSweepTest extends TestCase {

	/**
	 * A sweep over three registers' tables.
	 *
	 * @return LegacyRegisterSweep
	 */
	private function sweep(): LegacyRegisterSweep {
		return new LegacyRegisterSweep(
			removedSchemas: ['secret', 'folder'],
			tablesByRegister: [
				1 => ['oc_openregister_table_1_10'],
				2 => ['oc_openregister_table_2_20', 'oc_openregister_table_2_21'],
				3 => [],
			]
		);
	}//end sweep()

	/**
	 * The constructor arguments stay readable as given.
	 *
	 * @return void
	 */
	public function testItKeepsWhatItWasGiven(): void {
		$sweep = $this->sweep();

		$this->assertSame(['secret', 'folder'], $sweep->removedSchemas);
		$this->assertSame([], $sweep->removed);
		$this->assertSame([], $sweep->rewrites);
		$this->assertSame([], $sweep->registers);
	}//end testItKeepsWhatItWasGiven()

	/**
	 * A register's tables come back as declared, and an unknown register has none.
	 *
	 * @return void
	 */
	public function testTablesOfAKnownAndAnUnknownRegister(): void {
		$sweep = $this->sweep();

		$this->assertSame(['oc_openregister_table_2_20', 'oc_openregister_table_2_21'], $sweep->tablesOf(registerId: 2));
		$this->assertSame([], $sweep->tablesOf(registerId: 99));
	}//end testTablesOfAKnownAndAnUnknownRegister()

	/**
	 * Kept registers are the seen registers that were not removed.
	 *
	 * @return void
	 */
	public function testKeptRegistersAreTheSeenOnesNotRemoved(): void {
		$sweep = $this->sweep();
		$sweep->registers = [1 => 'a', 2 => 'b', 3 => 'c'];
		$sweep->removed = [2 => true];

		$this->assertSame([0 => 1, 2 => 3], $sweep->keptRegisters());
	}//end testKeptRegistersAreTheSeenOnesNotRemoved()

	/**
	 * Removed tables are every table of every removed register, in removal order.
	 *
	 * @return void
	 */
	public function testRemovedTablesAreTheTablesOfRemovedRegisters(): void {
		$sweep = $this->sweep();
		$sweep->removed = [2 => true, 1 => true, 3 => true, 7 => true];

		$this->assertSame(
			['oc_openregister_table_2_20', 'oc_openregister_table_2_21', 'oc_openregister_table_1_10'],
			$sweep->removedTables()
		);
	}//end testRemovedTablesAreTheTablesOfRemovedRegisters()

	/**
	 * Nothing removed means no removed tables.
	 *
	 * @return void
	 */
	public function testNothingRemovedMeansNoTables(): void {
		$this->assertSame([], $this->sweep()->removedTables());
	}//end testNothingRemovedMeansNoTables()
}//end class
