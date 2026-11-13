<?php

/**
 * Query-shape unit tests for EmergencyContactMapper, without a database.
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

use OCA\Keepiq\Db\EmergencyContact;
use OCA\Keepiq\Db\EmergencyContactMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use PHPUnit\Framework\TestCase;

/**
 * EmergencyContactMapper against a recording connection.
 *
 * @covers \OCA\Keepiq\Db\EmergencyContactMapper
 * @covers \OCA\Keepiq\Db\FindsByStringId
 * @uses   \OCA\Keepiq\Db\EmergencyContact
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class EmergencyContactMapperTest extends TestCase {
	use RecordingDatabase;

	/**
	 * Grantor and grantee listings are newest first on their own column.
	 *
	 * @return void
	 */
	public function testGrantorAndGranteeListings(): void {
		$this->queueRows([['id' => 'e1', 'grantor_user_id' => 'alice']]);
		$mapper = new EmergencyContactMapper($this->recordingConnection());

		$this->assertInstanceOf(EmergencyContact::class, $mapper->findByGrantor(grantorUserId: 'alice')[0]);
		$mapper->findByGrantee(granteeUserId: 'bob');

		$this->assertSame(['select(*)', 'from(keepiq_emergency_contacts)', 'where(eq(grantor_user_id, :p0))', 'orderBy(created_at, DESC)'], $this->opsOf(0));
		$this->assertSame(['select(*)', 'from(keepiq_emergency_contacts)', 'where(eq(grantee_user_id, :p1))', 'orderBy(created_at, DESC)'], $this->opsOf(1));
	}//end testGrantorAndGranteeListings()

	/**
	 * Deleting a user removes contacts where they are grantor or grantee.
	 *
	 * @return void
	 */
	public function testDeleteByUserMatchesEitherSide(): void {
		$this->affected = [2];

		$this->assertSame(2, (new EmergencyContactMapper($this->recordingConnection()))->deleteByUser(userId: 'alice'));
		$this->assertSame(['delete(keepiq_emergency_contacts)', 'where(orX(eq(grantor_user_id, :p0), eq(grantee_user_id, :p1)))'], $this->opsOf(0));
		$this->assertSame([':p0' => 'alice', ':p1' => 'alice'], $this->paramsOf(0));
	}//end testDeleteByUserMatchesEitherSide()

	/**
	 * The pair lookup is a single entity: none and two both throw.
	 *
	 * @return void
	 */
	public function testPairLookupIsExactlyOne(): void {
		$this->queueRows([['id' => 'e1']]);
		$this->queueRows([]);
		$this->queueRows([['id' => 'e1'], ['id' => 'e2']]);
		$mapper = new EmergencyContactMapper($this->recordingConnection());

		$this->assertSame('e1', $mapper->findByGrantorAndGrantee(grantorUserId: 'alice', granteeUserId: 'bob')->getId());
		$this->assertSame(['where(eq(grantor_user_id, :p0))', 'andWhere(eq(grantee_user_id, :p1))'], array_slice($this->opsOf(0), 2));

		try {
			$mapper->findByGrantorAndGrantee(grantorUserId: 'alice', granteeUserId: 'carol');
			$this->fail('no DoesNotExistException');
		} catch (DoesNotExistException) {
			$this->addToAssertionCount(1);
		}

		$this->expectException(MultipleObjectsReturnedException::class);
		$mapper->findByGrantorAndGrantee(grantorUserId: 'alice', granteeUserId: 'dave');
	}//end testPairLookupIsExactlyOne()

	/**
	 * Requested and per-suite listings filter on their own column.
	 *
	 * @return void
	 */
	public function testStateAndSuiteListings(): void {
		$this->queueRows([]);
		$this->queueRows([]);
		$this->queueRows([]);
		$this->queueRows([['id' => 'e5', 'grantor_user_id' => 'alice']]);
		$mapper = new EmergencyContactMapper($this->recordingConnection());
		$mapper->findAllRequested();
		$mapper->findByGrantorSuite(grantorSuiteId: 's1');
		$mapper->findByGranteeSuite(granteeSuiteId: 's2');
		$this->assertSame('alice', $mapper->findById(id: 'e5')->getGrantorUserId());

		$this->assertSame('where(eq(state, :p0))', $this->opsOf(0)[2]);
		$this->assertSame([':p0' => EmergencyContact::STATE_REQUESTED], $this->paramsOf(0));
		$this->assertSame('where(eq(grantor_suite_id, :p1))', $this->opsOf(1)[2]);
		$this->assertSame('where(eq(grantee_suite_id, :p2))', $this->opsOf(2)[2]);
		$this->assertSame([':p3' => 'e5'], $this->paramsOf(3));
	}//end testStateAndSuiteListings()

	/**
	 * Grantors with a live contact are the distinct grantors in granted, accepted or active state.
	 *
	 * @return void
	 */
	public function testGrantorsWithContact(): void {
		$this->queueRows([['grantor_user_id' => 'alice'], ['grantor_user_id' => 'carol']]);
		$mapper = new EmergencyContactMapper($this->recordingConnection());

		$this->assertSame(['alice', 'carol'], $mapper->grantorsWithContact(userIds: ['alice', 'bob', 'carol']));
		$this->assertSame(
			['selectDistinct(grantor_user_id)', 'from(keepiq_emergency_contacts)', 'where(in(grantor_user_id, :p0))', 'andWhere(in(state, :p1))'],
			$this->opsOf(0)
		);
		$this->assertSame(['granted', 'accepted', 'active'], $this->paramsOf(0)[':p1']);
		$this->assertSame([], $mapper->grantorsWithContact(userIds: []));
		$this->assertCount(1, $this->queries);
	}//end testGrantorsWithContact()
}//end class
