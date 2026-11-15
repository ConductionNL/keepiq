<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden outcomes of the grants that reach a share row's recipient.
 *
 * @category Tests
 * @package  OCA\Keepiq\Tests\Unit\Service
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-10-04-sharing-use-only-and-expiring-shares/tasks.md#task-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use DateTime;
use OCA\Keepiq\Db\GroupShare;
use OCA\Keepiq\Db\GroupShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\TeamFolderMember;
use OCA\Keepiq\Service\ShareGrantReader;
use OCA\Keepiq\Service\ShareRestriction;
use OCA\Keepiq\Service\TeamFolderQueryService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/**
 * Share grant outcomes.
 */
class ShareGrantReaderTest extends TestCase {

	/**
	 * A group share of S.
	 *
	 * @param string        $id      The id
	 * @param string        $groupId The group
	 * @param bool          $useOnly Whether it is use-only
	 * @param DateTime|null $ends    Its end date
	 *
	 * @return GroupShare
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) A fixture field.
	 */
	private function groupShare(string $id, string $groupId, bool $useOnly, ?DateTime $ends = null): GroupShare {
		$share = new GroupShare();
		$share->setId($id);
		$share->setGroupId($groupId);
		$share->setUseOnly($useOnly);
		$share->setExpiresAt($ends);
		return $share;
	}//end groupShare()

	/**
	 * A membership row.
	 *
	 * @param string $grade   The grade
	 * @param bool   $useOnly Whether it is use-only
	 *
	 * @return TeamFolderMember
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) A fixture field.
	 */
	private function membership(string $grade, bool $useOnly): TeamFolderMember {
		$row = new TeamFolderMember();
		$row->setGrade($grade);
		$row->setUseOnly($useOnly);
		return $row;
	}//end membership()

	/**
	 * A reader over the given group shares and memberships.
	 *
	 * @param list<GroupShare>       $groupShares The group shares of S
	 * @param list<TeamFolderMember> $memberships The memberships covering bob
	 * @param bool                   $sourceGone  Whether S is gone
	 *
	 * @return ShareGrantReader
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) A fixture field.
	 */
	private function reader(array $groupShares, array $memberships, bool $sourceGone = false): ShareGrantReader {
		$source = new Secret();
		$source->setId('S');
		$secrets = $this->createMock(SecretMapper::class);
		$secrets->method('findById')->willReturnCallback(
			static fn (string $id): Secret => $sourceGone === false && $id === 'S' ? $source : throw new DoesNotExistException('gone')
		);
		$groupMapper = $this->createMock(GroupShareMapper::class);
		$groupMapper->method('findBySecret')->with('S')->willReturn($groupShares);
		$folders = $this->createMock(TeamFolderQueryService::class);
		$folders->method('coveringMemberships')->willReturnCallback(
			static fn (Secret $secret, string $userId): array => $secret === $source && $userId === 'bob' ? $memberships : []
		);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(static fn (string $uid, string $gid): bool => $uid === 'bob' && $gid === 'staff');
		return new ShareGrantReader(secretMapper: $secrets, groupShareMapper: $groupMapper, teamFolders: $folders, groupManager: $groups);
	}//end reader()

	/**
	 * A share row of S to bob.
	 *
	 * @param string|null $groupShareId The group share it came from
	 *
	 * @return ShareTarget
	 */
	private function row(?string $groupShareId = null): ShareTarget {
		$row = new ShareTarget();
		$row->setSourceSecretId('S');
		$row->setTargetUserId('bob');
		$row->setUseOnly(true);
		$row->setExpiresAt(new DateTime('2031-01-01T00:00:00+00:00'));
		$row->setGroupShareId($groupShareId);
		return $row;
	}//end row()

	/**
	 * The grants as [useOnly, end date] pairs.
	 *
	 * @param array<int,ShareRestriction> $grants The grants
	 *
	 * @return list<array{0:bool,1:string|null}>
	 */
	private function pairs(array $grants): array {
		return array_map(static fn (ShareRestriction $g): array => [$g->useOnly, $g->expiresAt?->format(DATE_ATOM)], $grants);
	}//end pairs()

	/**
	 * A direct row's own grant comes first, then each group share that reaches
	 * bob, then each membership, a write membership never use-only.
	 *
	 * @return void
	 */
	public function testEveryGrantReachingTheRecipientInOrder(): void {
		$reader = $this->reader(
			[
				$this->groupShare(id: 'g-1', groupId: 'staff', useOnly: false, ends: new DateTime('2030-01-01T00:00:00+00:00')),
				$this->groupShare(id: 'g-2', groupId: 'others', useOnly: true),
			],
			[$this->membership(grade: 'read', useOnly: true), $this->membership(grade: 'write', useOnly: true)]
		);

		$this->assertSame(
			[[true, '2031-01-01T00:00:00+00:00'], [false, '2030-01-01T00:00:00+00:00'], [true, null], [false, null]],
			$this->pairs($reader->grantsFor($this->row()))
		);
	}//end testEveryGrantReachingTheRecipientInOrder()

	/**
	 * A row from a group share has no grant of its own, and its own group share
	 * counts even when bob left the group.
	 *
	 * @return void
	 */
	public function testARowFromAGroupShareCountsThatShareOnly(): void {
		$reader = $this->reader([$this->groupShare(id: 'g-2', groupId: 'others', useOnly: true)], []);

		$this->assertSame([[true, null]], $this->pairs($reader->grantsFor($this->row(groupShareId: 'g-2'))));
	}//end testARowFromAGroupShareCountsThatShareOnly()

	/**
	 * Without its source only the row's and the group shares' grants count.
	 *
	 * @return void
	 */
	public function testWithoutItsSourceNoMembershipCounts(): void {
		$reader = $this->reader(
			[$this->groupShare(id: 'g-1', groupId: 'staff', useOnly: true)],
			[$this->membership(grade: 'read', useOnly: false)],
			sourceGone: true
		);

		$this->assertSame([[true, '2031-01-01T00:00:00+00:00'], [true, null]], $this->pairs($reader->grantsFor($this->row())));
	}//end testWithoutItsSourceNoMembershipCounts()
}//end class
