<?php

/**
 * ExpiryPolicyMapper against a recording connection.
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

use OCA\Keepiq\Db\ExpiryPolicy;
use OCA\Keepiq\Db\ExpiryPolicyMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use PHPUnit\Framework\TestCase;

/**
 * The SQL each ExpiryPolicyMapper query builds: an owner's policies, where a
 * null owner means the organisation-wide (owner-less) policies; and the
 * policies that apply to a user, which are theirs plus the global ones.
 *
 * @covers \OCA\Keepiq\Db\ExpiryPolicyMapper
 * @covers \OCA\Keepiq\Db\FindsByStringId
 * @uses   \OCA\Keepiq\Db\ExpiryPolicy
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class ExpiryPolicyMapperQueriesTest extends TestCase {
	use RecordingDatabase;

	/**
	 * The mapper over the recording connection.
	 *
	 * @return ExpiryPolicyMapper
	 */
	private function mapper(): ExpiryPolicyMapper {
		return new ExpiryPolicyMapper(db: $this->recordingConnection());
	}//end mapper()

	/**
	 * By id hydrates the row, integers typed; no row throws.
	 *
	 * @return void
	 */
	public function testFindById(): void {
		$this->queueRows(rows: [['id' => 'x1', 'scope' => 'type', 'max_age_days' => '90']]);
		$mapper = $this->mapper();

		$policy = $mapper->findById(id: 'x1');

		$this->assertSame(expected: 'x1', actual: $policy->getId());
		$this->assertSame(expected: 'type', actual: $policy->getScope());
		$this->assertSame(expected: 90, actual: $policy->getMaxAgeDays());
		$this->assertSame(expected: ['select(*)', 'from(keepiq_expiry_policies)', 'where(eq(id, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 'x1'], actual: $this->paramsOf(index: 0));

		$this->expectException(exception: DoesNotExistException::class);
		$mapper->findById(id: 'gone');
	}//end testFindById()

	/**
	 * Two rows under one id is ambiguous.
	 *
	 * @return void
	 */
	public function testFindByIdRefusesTwoRows(): void {
		$this->queueRows(rows: [['id' => 'x1'], ['id' => 'x1']]);

		$this->expectException(exception: MultipleObjectsReturnedException::class);
		$this->mapper()->findById(id: 'x1');
	}//end testFindByIdRefusesTwoRows()

	/**
	 * A named owner filters on owner_id; a null owner selects the owner-less policies, unbound.
	 *
	 * @return void
	 */
	public function testByOwnerNamedOrGlobal(): void {
		$this->queueRows(rows: [['id' => 'x2', 'owner_id' => 'alice']]);
		$this->queueRows(rows: [['id' => 'x3'], ['id' => 'x4']]);
		$mapper = $this->mapper();

		$mine = $mapper->findByOwner(ownerId: 'alice');
		$global = $mapper->findByOwner(ownerId: null);

		$this->assertSame(expected: 'alice', actual: $mine[0]->getOwnerId());
		$this->assertSame(expected: ['x3', 'x4'], actual: array_map(static fn (ExpiryPolicy $p): string => $p->getId(), $global));
		$this->assertSame(expected: ['select(*)', 'from(keepiq_expiry_policies)', 'where(eq(owner_id, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 'alice'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: ['select(*)', 'from(keepiq_expiry_policies)', 'where(isNull(owner_id))'], actual: $this->opsOf(index: 1));
		$this->assertSame(expected: [], actual: $this->paramsOf(index: 1));
	}//end testByOwnerNamedOrGlobal()

	/**
	 * The applicable policies are the user's own or the global ones.
	 *
	 * @return void
	 */
	public function testApplicableIsOwnOrGlobal(): void {
		$this->queueRows(rows: [['id' => 'x2', 'owner_id' => 'alice'], ['id' => 'x3']]);

		$applicable = $this->mapper()->findApplicable(ownerId: 'alice');

		$this->assertSame(expected: ['x2', 'x3'], actual: array_map(static fn (ExpiryPolicy $p): string => $p->getId(), $applicable));
		$this->assertSame(
			expected: ['select(*)', 'from(keepiq_expiry_policies)', 'where(orX(eq(owner_id, :p0), isNull(owner_id)))'],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 'alice'], actual: $this->paramsOf(index: 0));
	}//end testApplicableIsOwnOrGlobal()
}//end class
