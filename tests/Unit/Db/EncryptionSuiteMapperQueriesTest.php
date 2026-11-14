<?php

/**
 * Query-shape unit tests for EncryptionSuiteMapper, without a database.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Db
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

namespace OCA\Keepiq\Tests\Unit\Db;

use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * EncryptionSuiteMapper against a recording connection.
 *
 * @covers \OCA\Keepiq\Db\EncryptionSuiteMapper
 * @covers \OCA\Keepiq\Db\FindsByStringId
 * @uses   \OCA\Keepiq\Db\EncryptionSuite
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class EncryptionSuiteMapperQueriesTest extends TestCase {
	use RecordingDatabase;

	/**
	 * findById looks up by string id and throws when absent.
	 *
	 * @return void
	 */
	public function testFindByIdHydratesOrThrows(): void {
		$this->queueRows([['id' => 'suite-1', 'owner_type' => 'user', 'owner_id' => 'alice', 'status' => 'active']]);
		$mapper = new EncryptionSuiteMapper($this->recordingConnection());

		$suite = $mapper->findById(id: 'suite-1');
		$this->assertSame('alice', $suite->getOwnerId());
		$this->assertSame(['select(*)', 'from(keepiq_enc_suites)', 'where(eq(id, :p0))'], $this->opsOf(0));

		$this->expectException(DoesNotExistException::class);
		$mapper->findById(id: 'missing');
	}//end testFindByIdHydratesOrThrows()

	/**
	 * The active suite is the newest active one for the owner.
	 *
	 * @return void
	 */
	public function testFindActiveByOwnerIsNewestActive(): void {
		$this->queueRows([['id' => 'suite-2', 'owner_id' => 'alice']]);

		$this->assertSame('suite-2', (new EncryptionSuiteMapper($this->recordingConnection()))->findActiveByOwner(ownerType: 'user', ownerId: 'alice')->getId());
		$this->assertSame(
			[
				'select(*)', 'from(keepiq_enc_suites)', 'where(eq(owner_type, :p0))', 'andWhere(eq(owner_id, :p1))', 'andWhere(eq(status, :p2))',
				'orderBy(created_at, DESC)', 'addOrderBy(id, DESC)', 'setMaxResults(1)',
			],
			$this->opsOf(0)
		);
		$this->assertSame([':p0' => 'user', ':p1' => 'alice', ':p2' => 'active'], $this->paramsOf(0));
	}//end testFindActiveByOwnerIsNewestActive()

	/**
	 * Per owner the first (newest) active suite wins; no owners, no query.
	 *
	 * @return void
	 */
	public function testFindActiveByOwnersKeepsTheFirstPerOwner(): void {
		$this->queueRows([['id' => 'new-a', 'owner_id' => 'a'], ['id' => 'b1', 'owner_id' => 'b'], ['id' => 'old-a', 'owner_id' => 'a']]);
		$mapper = new EncryptionSuiteMapper($this->recordingConnection());

		$found = $mapper->findActiveByOwners(ownerType: 'group', ownerIds: ['a', 'b']);

		$this->assertSame(['a' => 'new-a', 'b' => 'b1'], array_map(static fn (EncryptionSuite $s) => $s->getId(), $found));
		$this->assertSame(['a', 'b'], $this->paramsOf(0)[':p1']);
		$this->assertSame([], $mapper->findActiveByOwners(ownerType: 'group', ownerIds: []));
		$this->assertCount(1, $this->queries);
	}//end testFindActiveByOwnersKeepsTheFirstPerOwner()

	/**
	 * The latest inactive status per owner is the first row per owner; no owners, no query.
	 *
	 * @return void
	 */
	public function testLatestInactiveStatusKeepsTheFirstPerOwner(): void {
		$this->queueRows([['owner_id' => 'a', 'status' => 'revoked'], ['owner_id' => 'a', 'status' => 'retired'], ['owner_id' => 'b', 'status' => 'retired']]);
		$mapper = new EncryptionSuiteMapper($this->recordingConnection());

		$this->assertSame(['a' => 'revoked', 'b' => 'retired'], $mapper->latestInactiveStatusByOwners(ownerType: 'user', ownerIds: ['a', 'b']));
		$this->assertContains('andWhere(neq(status, :p2))', $this->opsOf(0));
		$this->assertSame([], $mapper->latestInactiveStatusByOwners(ownerType: 'user', ownerIds: []));
		$this->assertCount(1, $this->queries);
	}//end testLatestInactiveStatusKeepsTheFirstPerOwner()

	/**
	 * Counts read the cnt alias and default to zero.
	 *
	 * @return void
	 */
	public function testCountsReadCntOrZero(): void {
		$this->queueRows([['cnt' => '2']]);
		$this->queueRows([]);
		$mapper = new EncryptionSuiteMapper($this->recordingConnection());

		$this->assertSame(2, $mapper->countActiveByOwner(ownerType: 'user', ownerId: 'alice'));
		$this->assertSame(0, $mapper->countActiveByOwnerType(ownerType: 'application'));
		$this->assertSame([':p0' => 'user', ':p1' => 'alice', ':p2' => 'active'], $this->paramsOf(0));
		$this->assertSame(['select(count(*, cnt))', 'from(keepiq_enc_suites)', 'where(eq(status, :p3))', 'andWhere(eq(owner_type, :p4))'], $this->opsOf(1));
	}//end testCountsReadCntOrZero()

	/**
	 * Owner listings and active listings carry their own filters and paging.
	 *
	 * @return void
	 */
	public function testListings(): void {
		$mapper = new EncryptionSuiteMapper($this->recordingConnection());
		$mapper->findByOwner(ownerType: 'user', ownerId: 'alice');
		$mapper->findAllActive();
		$mapper->findAllActiveWithLimit(limit: 25, offset: 50);

		$this->assertSame(['orderBy(created_at, DESC)', 'addOrderBy(id, DESC)'], array_slice($this->opsOf(0), -2));
		$this->assertSame(['select(*)', 'from(keepiq_enc_suites)', 'where(eq(status, :p2))'], $this->opsOf(1));
		$this->assertSame(['setMaxResults(25)', 'setFirstResult(50)'], array_slice($this->opsOf(2), -2));
	}//end testListings()

	/**
	 * Deleting a user's suites targets owner_type user and returns the count.
	 *
	 * @return void
	 */
	public function testDeleteByOwnerUser(): void {
		$this->affected = [3];

		$this->assertSame(3, (new EncryptionSuiteMapper($this->recordingConnection()))->deleteByOwnerUser(ownerId: 'alice'));
		$this->assertSame(['delete(keepiq_enc_suites)', 'where(eq(owner_type, :p0))', 'andWhere(eq(owner_id, :p1))'], $this->opsOf(0));
		$this->assertSame([':p0' => 'user', ':p1' => 'alice'], $this->paramsOf(0));
	}//end testDeleteByOwnerUser()
}//end class
