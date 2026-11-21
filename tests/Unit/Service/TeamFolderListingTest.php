<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden outcomes of the team-folder listing: what each caller is shown.
 *
 * @category Tests
 * @package  OCA\Keepiq\Tests\Unit\Service
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec openspec/changes/team-folder-sharing/tasks.md#4.1
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use DateTime;
use OCA\Keepiq\Db\Folder;
use OCA\Keepiq\Db\FolderMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\TeamFolder;
use OCA\Keepiq\Db\TeamFolderMapper;
use OCA\Keepiq\Db\TeamFolderMember;
use OCA\Keepiq\Db\TeamFolderMemberMapper;
use OCA\Keepiq\Service\TeamFolderMembershipResolver;
use OCA\Keepiq\Service\TeamFolderQueryService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Group\ISubAdmin;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/**
 * Team folder listing outcomes.
 */
class TeamFolderListingTest extends TestCase {

	/**
	 * Team folders by id.
	 *
	 * @var array<string,TeamFolder>
	 */
	private array $teamFolders = [];

	/**
	 * Membership rows by team-folder id.
	 *
	 * @var array<string,list<TeamFolderMember>>
	 */
	private array $rows = [];

	/**
	 * The rows the resolver returns for the caller.
	 *
	 * @var list<TeamFolderMember>
	 */
	private array $callerRows = [];

	/**
	 * Build a service over the in-memory folders and rows.
	 *
	 * @return TeamFolderQueryService
	 */
	private function service(): TeamFolderQueryService {
		$mapper = $this->createMock(TeamFolderMapper::class);
		$mapper->method('findByOwner')->willReturnCallback(
			fn (string $ownerId): array => array_values(
				array_filter($this->teamFolders, static fn (TeamFolder $tf): bool => $tf->getOwnerId() === $ownerId)
			)
		);
		$mapper->method('findById')->willReturnCallback(
			fn (string $id): TeamFolder => $this->teamFolders[$id] ?? throw new DoesNotExistException('gone')
		);
		$mapper->method('findByFolder')->willReturnCallback(
			function (string $folderId): TeamFolder {
				foreach ($this->teamFolders as $teamFolder) {
					if ($teamFolder->getFolderId() === $folderId) {
						return $teamFolder;
					}
				}

				throw new DoesNotExistException('not shared');
			}
		);
		$members = $this->createMock(TeamFolderMemberMapper::class);
		$members->method('findByTeamFolder')->willReturnCallback(
			fn (string $teamFolderId): array => $this->rows[$teamFolderId] ?? []
		);
		$folders = $this->createMock(FolderMapper::class);
		$folders->method('findById')->willReturnCallback(
			static function (string $id): Folder {
				$folder = new Folder();
				$folder->setId($id);
				$folder->setName('Name of ' . $id);
				return $folder;
			}
		);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $gid): bool => $gid === 'crew' && $uid === 'bob'
		);
		$resolver = $this->createMock(TeamFolderMembershipResolver::class);
		$resolver->method('membershipRowsForUser')->willReturnCallback(fn (): array => $this->callerRows);

		return new TeamFolderQueryService(
			mapper: $mapper,
			memberMapper: $members,
			folderMapper: $folders,
			secretMapper: $this->createMock(SecretMapper::class),
			groupManager: $groups,
			memberships: $resolver,
			subAdmin: $this->createMock(ISubAdmin::class),
		);
	}//end service()

	/**
	 * Add a team folder.
	 *
	 * @param string $id    The team-folder id
	 * @param string $owner The owner
	 *
	 * @return TeamFolder
	 */
	private function teamFolder(string $id, string $owner): TeamFolder {
		$teamFolder = new TeamFolder();
		$teamFolder->setId($id);
		$teamFolder->setFolderId('folder-' . $id);
		$teamFolder->setOwnerId($owner);
		$teamFolder->setCreatedAt(new DateTime('2026-01-02T03:04:05+00:00'));
		$this->teamFolders[$id] = $teamFolder;
		return $teamFolder;
	}//end teamFolder()

	/**
	 * Add a membership row.
	 *
	 * @param string $teamFolderId The team-folder id
	 * @param string $who          "user:<id>" or "group:<id>", then ":<grade>"
	 *
	 * @return TeamFolderMember
	 */
	private function row(string $teamFolderId, string $who): TeamFolderMember {
		[$type, $id, $grade] = explode(':', $who);
		$row = new TeamFolderMember();
		$row->setId('m-' . $teamFolderId . '-' . $id);
		$row->setTeamFolderId($teamFolderId);
		$row->setMemberType($type);
		$row->setMemberId($id);
		$row->setGrade($grade);
		$this->rows[$teamFolderId][] = $row;
		return $row;
	}//end row()

	/**
	 * The owner sees each owned folder with its member list, and nothing under memberOf.
	 *
	 * @return void
	 */
	public function testTheOwnerSeesOwnedFoldersWithTheirMembers(): void {
		$this->teamFolder(id: 'tf-a', owner: 'alice');
		$bob = $this->row(teamFolderId: 'tf-a', who: 'user:bob:read');

		$listing = $this->service()->listForUser(userId: 'alice');

		$this->assertSame(
			[
				'owned' => [
					[
						'id' => 'tf-a',
						'folderId' => 'folder-tf-a',
						'folderName' => 'Name of folder-tf-a',
						'ownerId' => 'alice',
						'createdAt' => '2026-01-02T03:04:05+00:00',
						'members' => [$bob],
					],
				],
				'memberOf' => [],
			],
			$listing
		);
	}//end testTheOwnerSeesOwnedFoldersWithTheirMembers()

	/**
	 * A read member sees the folder identity and its grade, never the member list.
	 *
	 * @return void
	 */
	public function testAReadMemberSeesTheIdentityAndGradeOnly(): void {
		$this->teamFolder(id: 'tf-a', owner: 'alice');
		$this->callerRows = [$this->row(teamFolderId: 'tf-a', who: 'user:bob:read')];
		$this->row(teamFolderId: 'tf-a', who: 'user:carol:write');

		$listing = $this->service()->listForUser(userId: 'bob');

		$this->assertSame([], $listing['owned']);
		$this->assertSame(
			[
				[
					'id' => 'tf-a',
					'folderId' => 'folder-tf-a',
					'folderName' => 'Name of folder-tf-a',
					'ownerId' => 'alice',
					'createdAt' => '2026-01-02T03:04:05+00:00',
					'grade' => 'read',
				],
			],
			$listing['memberOf']
		);
	}//end testAReadMemberSeesTheIdentityAndGradeOnly()

	/**
	 * A folder reached directly and through a group is listed once, with the higher grade.
	 *
	 * @return void
	 */
	public function testAFolderReachedTwiceIsListedOnceWithTheHigherGrade(): void {
		$this->teamFolder(id: 'tf-a', owner: 'alice');
		$this->callerRows = [
			$this->row(teamFolderId: 'tf-a', who: 'user:bob:read'),
			$this->row(teamFolderId: 'tf-a', who: 'group:crew:write'),
		];

		$memberOf = $this->service()->listForUser(userId: 'bob')['memberOf'];

		$this->assertCount(1, $memberOf);
		$this->assertSame('write', $memberOf[0]['grade']);
		$this->assertArrayNotHasKey('members', $memberOf[0]);
		$this->assertArrayNotHasKey('ownerOnlyMemberIds', $memberOf[0]);
	}//end testAFolderReachedTwiceIsListedOnceWithTheHigherGrade()

	/**
	 * A row whose folder is gone is skipped, and the next one is still listed.
	 *
	 * @return void
	 */
	public function testARowOnAVanishedFolderIsSkipped(): void {
		$this->teamFolder(id: 'tf-b', owner: 'alice');
		$gone = new TeamFolderMember();
		$gone->setTeamFolderId('tf-gone');
		$gone->setMemberType('user');
		$gone->setMemberId('bob');
		$this->callerRows = [$gone, $this->row(teamFolderId: 'tf-b', who: 'user:bob:read')];

		$memberOf = $this->service()->listForUser(userId: 'bob')['memberOf'];

		$this->assertSame(['tf-b'], array_column($memberOf, 'id'));
	}//end testARowOnAVanishedFolderIsSkipped()

	/**
	 * A row past its end date still lists the folder, but grants no grade.
	 *
	 * @return void
	 */
	public function testAnEndedRowListsTheFolderWithoutAGrade(): void {
		$this->teamFolder(id: 'tf-a', owner: 'alice');
		$ended = $this->row(teamFolderId: 'tf-a', who: 'user:bob:manage');
		$ended->setExpiresAt(new DateTime('-1 day'));
		$this->callerRows = [$ended];

		$memberOf = $this->service()->listForUser(userId: 'bob')['memberOf'];

		$this->assertCount(1, $memberOf);
		$this->assertNull($memberOf[0]['grade']);
		$this->assertArrayNotHasKey('members', $memberOf[0]);
		$this->assertArrayNotHasKey('ownerOnlyMemberIds', $memberOf[0]);
	}//end testAnEndedRowListsTheFolderWithoutAGrade()

	/**
	 * A user with no folders and no rows sees nothing.
	 *
	 * @return void
	 */
	public function testAStrangerSeesNothing(): void {
		$this->teamFolder(id: 'tf-a', owner: 'alice');
		$this->row(teamFolderId: 'tf-a', who: 'user:bob:manage');

		$this->assertSame(['owned' => [], 'memberOf' => []], $this->service()->listForUser(userId: 'eve'));
	}//end testAStrangerSeesNothing()

	/**
	 * A manager sees the member list and the rows only the owner may change.
	 *
	 * @return void
	 */
	public function testAManagerSeesTheMembersAndTheOwnerOnlyRows(): void {
		$this->teamFolder(id: 'tf-a', owner: 'alice');
		$bob = $this->row(teamFolderId: 'tf-a', who: 'user:bob:manage');
		$carol = $this->row(teamFolderId: 'tf-a', who: 'user:carol:read');
		$this->callerRows = [$bob];

		$memberOf = $this->service()->listForUser(userId: 'bob')['memberOf'];

		$this->assertCount(1, $memberOf);
		$this->assertSame('manage', $memberOf[0]['grade']);
		$this->assertSame([$bob, $carol], $memberOf[0]['members']);
		$this->assertSame(['m-tf-a-bob'], $memberOf[0]['ownerOnlyMemberIds']);
		$this->assertSame(
			['id', 'folderId', 'folderName', 'ownerId', 'createdAt', 'members', 'grade', 'ownerOnlyMemberIds'],
			array_keys($memberOf[0])
		);
	}//end testAManagerSeesTheMembersAndTheOwnerOnlyRows()
}//end class
