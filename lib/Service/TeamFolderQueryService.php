<?php

/**
 * Doriath Team Folder Query Service
 *
 * The read side of the team-folder graph: the ownership guards every mutating
 * operation starts from, the two list views (owned folders with their member
 * list, folders shared TO the caller without one — the share-visibility rule of
 * the user-sharing spec), and the two answers that are derived by walking a
 * folder's ANCESTOR CHAIN: the effective recipient set of a secret (nested
 * folders inherit, union-only) and the effective permission grade of a user
 * (`write` outranks `read`).
 *
 * Server-visible metadata only — this service never touches ciphertext.
 *
 * @category Service
 * @package  OCA\Doriath\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Doriath\Service;

use InvalidArgumentException;
use OCA\Doriath\Db\Folder;
use OCA\Doriath\Db\FolderMapper;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\TeamFolder;
use OCA\Doriath\Db\TeamFolderMapper;
use OCA\Doriath\Db\TeamFolderMember;
use OCA\Doriath\Db\TeamFolderMemberMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;

/**
 * Read-side lookups and ancestor-chain resolution for team folders.
 */
class TeamFolderQueryService {
	/**
	 * Constructor for TeamFolderQueryService.
	 *
	 * @param TeamFolderMapper $mapper The team-folder mapper
	 * @param TeamFolderMemberMapper $memberMapper The membership mapper
	 * @param FolderMapper $folderMapper The folder mapper (ancestor walk)
	 * @param SecretMapper $secretMapper The secret mapper
	 * @param IGroupManager $groupManager The Nextcloud group manager
	 * @param TeamFolderMembershipResolver $memberships The membership resolver
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private TeamFolderMapper $mapper,
		private TeamFolderMemberMapper $memberMapper,
		private FolderMapper $folderMapper,
		private SecretMapper $secretMapper,
		private IGroupManager $groupManager,
		private TeamFolderMembershipResolver $memberships,
	) {
	}//end __construct()

	/**
	 * Load a Folder and assert user ownership.
	 *
	 * @param string $folderId The Folder UUID
	 * @param string $userId The candidate owner
	 *
	 * @return Folder
	 *
	 * @throws InvalidArgumentException On missing folder / foreign owner
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.1
	 */
	public function loadOwnedFolder(string $folderId, string $userId): Folder {
		try {
			$folder = $this->folderMapper->findById($folderId);
		} catch (DoesNotExistException) {
			throw new InvalidArgumentException(message: 'Folder not found');
		}

		if ($folder->getOwnerType() !== 'user' || $folder->getOwnerId() !== $userId) {
			throw new InvalidArgumentException(message: 'Not authorized to share this folder');
		}

		return $folder;
	}//end loadOwnedFolder()

	/**
	 * Load a TeamFolder and assert the caller owns it.
	 *
	 * @param string $teamFolderId The TeamFolder UUID
	 * @param string $userId The candidate owner
	 *
	 * @return TeamFolder
	 *
	 * @throws InvalidArgumentException On missing row / foreign owner
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.1
	 */
	public function loadOwnedTeamFolder(string $teamFolderId, string $userId): TeamFolder {
		try {
			$teamFolder = $this->mapper->findById(id: $teamFolderId);
		} catch (DoesNotExistException) {
			throw new InvalidArgumentException(message: 'Team folder not found');
		}

		if ($teamFolder->getOwnerId() !== $userId) {
			throw new InvalidArgumentException(message: 'Not authorized to manage this team folder');
		}

		return $teamFolder;
	}//end loadOwnedTeamFolder()

	/**
	 * The TeamFolder attached to a folder, or null when it is not shared.
	 *
	 * @param string $folderId The Folder UUID
	 *
	 * @return TeamFolder|null
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.1
	 */
	public function findByFolder(string $folderId): ?TeamFolder {
		try {
			return $this->mapper->findByFolder(folderId: $folderId);
		} catch (DoesNotExistException) {
			return null;
		}
	}//end findByFolder()

	/**
	 * List team folders for a user: folders they own (with membership)
	 * and folders shared to them (as direct user member or via a group).
	 *
	 * @param string $userId The requesting user
	 *
	 * @return array{owned:array<int,array<string,mixed>>,memberOf:array<int,array<string,mixed>>}
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#4.1
	 */
	public function listForUser(string $userId): array {
		$owned = [];
		foreach ($this->mapper->findByOwner(ownerId: $userId) as $teamFolder) {
			$owned[] = $this->describe(teamFolder: $teamFolder, includeMembers: true);
		}

		$memberOf = [];
		$seen = [];
		foreach ($this->memberships->membershipRowsForUser(userId: $userId) as $membership) {
			$teamFolderId = $membership->getTeamFolderId();
			if (isset($seen[$teamFolderId]) === true) {
				continue;
			}

			$seen[$teamFolderId] = true;
			try {
				$teamFolder = $this->mapper->findById(id: $teamFolderId);
			} catch (DoesNotExistException) {
				continue;
			}

			// Recipients see the folder identity, never the member list
			// (share-visibility rule, user-sharing spec).
			$memberOf[] = $this->describe(teamFolder: $teamFolder, includeMembers: false);
		}

		return [
			'owned' => $owned,
			'memberOf' => $memberOf,
		];
	}//end listForUser()

	/**
	 * List the members of a team folder — full list for the owner only.
	 *
	 * @param string $teamFolderId The TeamFolder UUID
	 * @param string $userId The caller
	 *
	 * @return array<int,TeamFolderMember> Empty for non-owners
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#4.1
	 */
	public function listMembers(string $teamFolderId, string $userId): array {
		try {
			$teamFolder = $this->mapper->findById(id: $teamFolderId);
		} catch (DoesNotExistException) {
			return [];
		}

		if ($teamFolder->getOwnerId() !== $userId) {
			return [];
		}

		return $this->memberMapper->findByTeamFolder(teamFolderId: $teamFolderId);
	}//end listMembers()

	/**
	 * Resolve the effective recipient set of a secret by walking its
	 * folder ancestor chain and unioning every ancestor team folder's
	 * member set (nested folders inherit, union-only).
	 *
	 * @param string $secretId The secret UUID
	 *
	 * @return string[] Recipient user IDs (owner excluded)
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.3
	 */
	public function resolveRecipients(string $secretId): array {
		try {
			$secret = $this->secretMapper->findById($secretId);
		} catch (DoesNotExistException) {
			return [];
		}

		$folderId = $secret->getFolderId();
		if ($folderId === null || $folderId === '') {
			return [];
		}

		$users = [];
		foreach ($this->ancestorTeamFolders(folderId: $folderId) as $teamFolder) {
			foreach ($this->memberships->effectiveUsers(teamFolderId: $teamFolder->getId()) as $memberUserId) {
				$users[$memberUserId] = true;
			}
		}

		unset($users[$secret->getOwnerId()]);

		return array_keys($users);
	}//end resolveRecipients()

	/**
	 * The MAX grade any team-folder membership along a secret's folder
	 * ancestor chain grants a user (`write` outranks `read`), or null
	 * when nothing applies. Group memberships expand via the Nextcloud
	 * group manager (folder-permission-grades §2.2). Server-visible
	 * metadata only — never any ciphertext.
	 *
	 * @param Secret $secret The SOURCE secret
	 * @param string $userId The candidate user
	 *
	 * @return string|null `write`, `read`, or null
	 *
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-effective-grade-is-the-highest-grade-along-the-ancestor-folder-chain
	 */
	public function resolveGrade(Secret $secret, string $userId): ?string {
		$best = null;
		$folderId = $secret->getFolderId();
		$hops = 0;
		while ($folderId !== null && $folderId !== '' && $hops < 50) {
			++$hops;
			try {
				$teamFolder = $this->mapper->findByFolder($folderId);
				foreach ($this->memberMapper->findByTeamFolder(teamFolderId: $teamFolder->getId()) as $membership) {
					if ($this->membershipCovers(membership: $membership, userId: $userId) === false) {
						continue;
					}

					if ($membership->effectiveGrade() === 'write') {
						return 'write';
					}

					$best = 'read';
				}
			} catch (DoesNotExistException) {
				// Not a team folder — keep climbing.
			}

			try {
				$folderId = $this->folderMapper->findById($folderId)->getParentId();
			} catch (DoesNotExistException) {
				break;
			}
		}//end while

		return $best;
	}//end resolveGrade()

	/**
	 * Describe a team folder for the API (folder name resolved; members
	 * included for the owner view only).
	 *
	 * @param TeamFolder $teamFolder The team folder
	 * @param bool $includeMembers Whether to include the member list
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#4.1
	 */
	private function describe(TeamFolder $teamFolder, bool $includeMembers): array {
		$folderName = '';
		try {
			$folderName = $this->folderMapper->findById($teamFolder->getFolderId())->getName();
		} catch (DoesNotExistException) {
			// Folder vanished — describe with an empty name.
		}

		$data = [
			'id' => $teamFolder->getId(),
			'folderId' => $teamFolder->getFolderId(),
			'folderName' => $folderName,
			'ownerId' => $teamFolder->getOwnerId(),
			'createdAt' => $teamFolder->getCreatedAt()?->format('c'),
		];

		if ($includeMembers === true) {
			$data['members'] = $this->memberMapper->findByTeamFolder(teamFolderId: $teamFolder->getId());
		}

		return $data;
	}//end describe()

	/**
	 * Walk a folder's ancestor chain (including itself) and collect every
	 * attached TeamFolder, nearest first.
	 *
	 * @param string $folderId The starting Folder UUID
	 *
	 * @return array<int,TeamFolder>
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.3
	 */
	private function ancestorTeamFolders(string $folderId): array {
		$found = [];
		$current = $folderId;
		$guard = 0;
		while ($current !== null && $current !== '' && $guard < 100) {
			++$guard;
			try {
				$found[] = $this->mapper->findByFolder(folderId: $current);
			} catch (DoesNotExistException) {
				// This level is not shared — continue up.
			}

			try {
				$folder = $this->folderMapper->findById($current);
			} catch (DoesNotExistException) {
				break;
			}

			$current = $folder->getParentId();
		}

		return $found;
	}//end ancestorTeamFolders()

	/**
	 * Whether a membership row covers a user (direct or via group).
	 *
	 * @param TeamFolderMember $membership The membership row
	 * @param string $userId The candidate user
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-effective-grade-is-the-highest-grade-along-the-ancestor-folder-chain
	 */
	private function membershipCovers(TeamFolderMember $membership, string $userId): bool {
		if ($membership->getMemberType() === 'user') {
			return $membership->getMemberId() === $userId;
		}

		if ($membership->getMemberType() === 'group') {
			return $this->groupManager->isInGroup($userId, $membership->getMemberId());
		}

		return false;
	}//end membershipCovers()
}//end class
