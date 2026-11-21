<?php

/**
 * TeamFolderMemberMapper against a recording connection.
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

use DateTime;
use OCA\Keepiq\Db\TeamFolderMember;
use OCA\Keepiq\Db\TeamFolderMemberMapper;
use OCA\Keepiq\Tests\Support\RecordingDatabase;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The SQL each TeamFolderMemberMapper query builds: lookups by id, folder and
 * exact membership; the user and group memberships, each pinned to its member
 * type so a group named like a user never matches; the folder cascade; the
 * per-user membership counts (and no query at all for no users); and the
 * expiring-grant window.
 *
 * @covers \OCA\Keepiq\Db\TeamFolderMemberMapper
 * @covers \OCA\Keepiq\Db\FindsByStringId
 * @covers \OCA\Keepiq\Db\ExpiringGrantQueries
 * @uses   \OCA\Keepiq\Db\TeamFolderMember
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
class TeamFolderMemberMapperQueriesTest extends TestCase {
	use RecordingDatabase;

	/**
	 * The mapper over the recording connection.
	 *
	 * @return TeamFolderMemberMapper
	 */
	private function mapper(): TeamFolderMemberMapper {
		return new TeamFolderMemberMapper(db: $this->recordingConnection());
	}//end mapper()

	/**
	 * Lookup by id hydrates the row; no row throws.
	 *
	 * @return void
	 */
	public function testFindById(): void {
		$this->queueRows(rows: [['id' => 'm1', 'member_id' => 'alice', 'grade' => 'write', 'use_only' => 1]]);
		$mapper = $this->mapper();

		$member = $mapper->findById(id: 'm1');

		$this->assertSame(expected: 'm1', actual: $member->getId());
		$this->assertSame(expected: 'alice', actual: $member->getMemberId());
		$this->assertSame(expected: 'write', actual: $member->getGrade());
		$this->assertTrue(condition: $member->getUseOnly());
		$this->assertSame(expected: ['select(*)', 'from(keepiq_team_folder_members)', 'where(eq(id, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 'm1'], actual: $this->paramsOf(index: 0));

		$this->expectException(exception: DoesNotExistException::class);
		$mapper->findById(id: 'gone');
	}//end testFindById()

	/**
	 * A folder's members, every row hydrated.
	 *
	 * @return void
	 */
	public function testFindByTeamFolder(): void {
		$this->queueRows(rows: [['id' => 'm1'], ['id' => 'm2']]);

		$members = $this->mapper()->findByTeamFolder(teamFolderId: 'tf1');

		$this->assertSame(expected: ['m1', 'm2'], actual: array_map(static fn (TeamFolderMember $m): string => $m->getId(), $members));
		$this->assertSame(expected: ['select(*)', 'from(keepiq_team_folder_members)', 'where(eq(team_folder_id, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 'tf1'], actual: $this->paramsOf(index: 0));
	}//end testFindByTeamFolder()

	/**
	 * An exact membership filters on folder, member type and member id.
	 *
	 * @return void
	 */
	public function testFindMembership(): void {
		$this->queueRows(rows: [['id' => 'm3', 'member_type' => 'group']]);
		$mapper = $this->mapper();

		$this->assertSame(expected: 'group', actual: $mapper->findMembership(teamFolderId: 'tf1', memberType: 'group', memberId: 'staff')->getMemberType());

		$this->assertSame(
			expected: [
				'select(*)', 'from(keepiq_team_folder_members)', 'where(eq(team_folder_id, :p0))',
				'andWhere(eq(member_type, :p1))', 'andWhere(eq(member_id, :p2))',
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 'tf1', ':p1' => 'group', ':p2' => 'staff'], actual: $this->paramsOf(index: 0));

		$this->expectException(exception: DoesNotExistException::class);
		$mapper->findMembership(teamFolderId: 'tf1', memberType: 'user', memberId: 'eve');
	}//end testFindMembership()

	/**
	 * User and group memberships are each pinned to their member type.
	 *
	 * @return void
	 */
	public function testUserAndGroupMembershipsArePinnedToTheirType(): void {
		$this->queueRows(rows: [['id' => 'm1', 'member_type' => 'group', 'member_id' => 'staff']]);
		$this->queueRows(rows: [['id' => 'm2', 'member_type' => 'user', 'member_id' => 'alice']]);
		$mapper = $this->mapper();

		$groups = $mapper->findGroupMemberships(groupId: 'staff');
		$users = $mapper->findUserMemberships(userId: 'alice');

		$this->assertSame(expected: 'm1', actual: $groups[0]->getId());
		$this->assertSame(expected: 'm2', actual: $users[0]->getId());
		$expected = [
			'select(*)', 'from(keepiq_team_folder_members)', 'where(eq(member_type, :p0))', 'andWhere(eq(member_id, :p1))',
		];
		$this->assertSame(expected: $expected, actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 'group', ':p1' => 'staff'], actual: $this->paramsOf(index: 0));
		$this->assertSame(
			expected: ['select(*)', 'from(keepiq_team_folder_members)', 'where(eq(member_type, :p2))', 'andWhere(eq(member_id, :p3))'],
			actual: $this->opsOf(index: 1)
		);
		$this->assertSame(expected: [':p2' => 'user', ':p3' => 'alice'], actual: $this->paramsOf(index: 1));
	}//end testUserAndGroupMembershipsArePinnedToTheirType()

	/**
	 * The folder cascade deletes every membership of the folder.
	 *
	 * @return void
	 */
	public function testDeleteByTeamFolder(): void {
		$this->mapper()->deleteByTeamFolder(teamFolderId: 'tf1');

		$this->assertSame(expected: ['delete(keepiq_team_folder_members)', 'where(eq(team_folder_id, :p0))'], actual: $this->opsOf(index: 0));
		$this->assertSame(expected: [':p0' => 'tf1'], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: 'statement', actual: $this->ranOf(index: 0));
	}//end testDeleteByTeamFolder()

	/**
	 * Per-user counts come from one grouped query; users without rows are absent.
	 *
	 * @return void
	 */
	public function testCountUserMemberships(): void {
		$this->queueRows(rows: [['member_id' => 'alice', 'row_count' => '2'], ['member_id' => 'bob', 'row_count' => 1]]);

		$counts = $this->mapper()->countUserMemberships(userIds: ['alice', 'bob', 'carol']);

		$this->assertSame(expected: ['alice' => 2, 'bob' => 1], actual: $counts);
		$this->assertSame(
			expected: [
				'select(member_id)', 'selectAlias(count(id), row_count)', 'from(keepiq_team_folder_members)',
				'where(eq(member_type, :p0))', 'andWhere(in(member_id, :p1))', 'groupBy(member_id)',
			],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => 'user', ':p1' => ['alice', 'bob', 'carol']], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: IQueryBuilder::PARAM_STR_ARRAY, actual: $this->queries[0]['types'][':p1']);
		$this->assertSame(expected: 'query', actual: $this->ranOf(index: 0));
	}//end testCountUserMemberships()

	/**
	 * No users means no query at all.
	 *
	 * @return void
	 */
	public function testCountUserMembershipsOfNobodyRunsNoQuery(): void {
		$this->assertSame(expected: [], actual: $this->mapper()->countUserMemberships(userIds: []));
		$this->assertSame(expected: [], actual: $this->queries);
	}//end testCountUserMembershipsOfNobodyRunsNoQuery()

	/**
	 * The expiring window: up to $to, and after $from when one is given.
	 *
	 * @return void
	 */
	public function testFindEndingBetween(): void {
		$from = new DateTime('2026-03-01T00:00:00+00:00');
		$to = new DateTime('2026-03-08T00:00:00+00:00');
		$this->queueRows(rows: [['id' => 'm5']]);
		$mapper = $this->mapper();

		$ending = $mapper->findEndingBetween(from: null, to: $to);
		$mapper->findEndingBetween(from: $from, to: $to);

		$this->assertSame(expected: 'm5', actual: $ending[0]->getId());
		$this->assertSame(
			expected: ['select(*)', 'from(keepiq_team_folder_members)', 'where(isNotNull(expires_at))', 'andWhere(lte(expires_at, :p0))'],
			actual: $this->opsOf(index: 0)
		);
		$this->assertSame(expected: [':p0' => $to], actual: $this->paramsOf(index: 0));
		$this->assertSame(expected: IQueryBuilder::PARAM_DATETIME_MUTABLE, actual: $this->queries[0]['types'][':p0']);
		$this->assertSame(expected: 'andWhere(gt(expires_at, :p2))', actual: $this->opsOf(index: 1)[4]);
		$this->assertSame(expected: [':p1' => $to, ':p2' => $from], actual: $this->paramsOf(index: 1));
	}//end testFindEndingBetween()
}//end class
