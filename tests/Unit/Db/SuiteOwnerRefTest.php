<?php

/**
 * SuiteOwnerRef narrows a query to one owner of one encryption suite.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Db;

use OCA\Keepiq\Db\SuiteOwnerRef;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use PHPUnit\Framework\TestCase;

/**
 * The suite, owner type and owner id each become one condition, in that order.
 *
 * @covers \OCA\Keepiq\Db\SuiteOwnerRef
 */
class SuiteOwnerRefTest extends TestCase {
	use RecordingDatabase;

	/**
	 * Narrowing adds the three conditions and hands the builder back.
	 *
	 * @return void
	 */
	public function testNarrowAddsSuiteAndOwnerConditions(): void {
		$qb  = $this->recordingQueryBuilder();
		$ref = new SuiteOwnerRef(encryptionSuiteId: 'suite-1', ownerType: 'group', ownerId: 'finance');

		$this->assertSame($qb, $ref->narrow(qb: $qb));
		$this->assertSame(
			['where(eq(encryption_suite_id, :p0))', 'andWhere(eq(owner_type, :p1))', 'andWhere(eq(owner_id, :p2))'],
			$this->opsOf(0)
		);
		$this->assertSame([':p0' => 'suite-1', ':p1' => 'group', ':p2' => 'finance'], $this->paramsOf(0));
	}//end testNarrowAddsSuiteAndOwnerConditions()

	/**
	 * The constructor keeps its values as given.
	 *
	 * @return void
	 */
	public function testTheReferenceKeepsItsValues(): void {
		$ref = new SuiteOwnerRef(encryptionSuiteId: 'suite-2', ownerType: 'user', ownerId: 'alice');

		$this->assertSame(['suite-2', 'user', 'alice'], [$ref->encryptionSuiteId, $ref->ownerType, $ref->ownerId]);
	}//end testTheReferenceKeepsItsValues()
}//end class
