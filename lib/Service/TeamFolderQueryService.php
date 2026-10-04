<?php

/**
 * Keepiq Team Folder Query Service
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
 * @package  OCA\Keepiq\Service
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

namespace OCA\Keepiq\Service;

use InvalidArgumentException;
use OCA\Keepiq\Db\Folder;
use OCA\Keepiq\Db\FolderMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\TeamFolder;
use OCA\Keepiq\Db\TeamFolderMapper;
use OCA\Keepiq\Db\TeamFolderMember;
use OCA\Keepiq\Db\TeamFolderMemberMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;

/**
 * Read-side lookups and ancestor-chain resolution for team folders.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The read side of team folders,
 *   including the ancestor walks that grades and restrictions both need.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) One public lookup per caller need.
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
			// (share-visibility rule, user-sharing spec); a manager sees the
			// list it manages (sharing-team-folder-manager-role D5).
			$grade = $this->gradeOnTeamFolder(teamFolder: $teamFolder, userId: $userId);
			$entry = $this->describe(teamFolder: $teamFolder, includeMembers: $grade === 'manage');
			$entry['grade'] = $grade;
			$memberOf[] = $entry;
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

		if ($teamFolder->getOwnerId() !== $userId
			&& $this->gradeOnTeamFolder(teamFolder: $teamFolder, userId: $userId) !== 'manage'
		) {
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

					$best = $this->higherGrade(current: $best, candidate: $membership->effectiveGrade());
					if ($best === 'manage') {
						// Nothing ranks higher.
						return $best;
					}
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
	 * The higher of two grades (`read` < `write` < `manage`).
	 *
	 * @param string|null $current   The best grade so far
	 * @param string      $candidate Another grade
	 *
	 * @return string
	 */
	private function higherGrade(?string $current, string $candidate): string {
		$ranks = array_flip(TeamFolderMember::GRADES);
		if ($current === null || ($ranks[$candidate] ?? -1) > ($ranks[$current] ?? -1)) {
			return $candidate;
		}

		return $current;
	}//end higherGrade()

	/**
	 * The caller's effective grade on a team folder itself: the highest
	 * grade any membership of it or of an ancestor team folder gives them.
	 * Null when nothing covers them.
	 *
	 * @param TeamFolder $teamFolder The team folder
	 * @param string     $userId     The caller
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/sharing-team-folder-manager-role/specs/folder-permission-grades/spec.md#requirement-effective-grade-is-the-highest-grade-along-the-ancestor-folder-chain
	 */
	public function gradeOnTeamFolder(TeamFolder $teamFolder, string $userId): ?string {
		$best = null;
		foreach ($this->ancestorTeamFolders(folderId: $teamFolder->getFolderId()) as $ancestor) {
			foreach ($this->memberMapper->findByTeamFolder(teamFolderId: $ancestor->getId()) as $membership) {
				if ($this->membershipCovers(membership: $membership, userId: $userId) === true) {
					$best = $this->higherGrade(current: $best, candidate: $membership->effectiveGrade());
				}
			}
		}

		return $best;
	}//end gradeOnTeamFolder()

	/**
	 * Load a team folder the caller may manage: its owner, or a member whose
	 * effective grade on it is `manage` (sharing-team-folder-manager-role D2).
	 *
	 * @param string $teamFolderId The TeamFolder UUID
	 * @param string $userId       The caller
	 *
	 * @return TeamFolder
	 *
	 * @throws InvalidArgumentException When missing or the caller may not manage it
	 *
	 * @spec openspec/changes/sharing-team-folder-manager-role/specs/folder-permission-grades/spec.md#requirement-managers-keep-the-membership-current
	 */
	public function loadManageableTeamFolder(string $teamFolderId, string $userId): TeamFolder {
		try {
			$teamFolder = $this->mapper->findById(id: $teamFolderId);
		} catch (DoesNotExistException) {
			throw new InvalidArgumentException(message: 'Team folder not found');
		}

		if ($teamFolder->getOwnerId() !== $userId
			&& $this->gradeOnTeamFolder(teamFolder: $teamFolder, userId: $userId) !== 'manage'
		) {
			throw new InvalidArgumentException(message: 'Not authorized to manage this team folder');
		}

		return $teamFolder;
	}//end loadManageableTeamFolder()

	/**
	 * Every team-folder membership along a secret's folder ancestor chain
	 * that covers a user, directly or through a group. These are the
	 * team-folder grants ShareRestrictionResolver combines.
	 *
	 * @param Secret $secret The SOURCE secret
	 * @param string $userId The candidate user
	 *
	 * @return array<int,TeamFolderMember>
	 *
	 * @spec openspec/specs/use-only-shares/spec.md#requirement-owners-can-share-a-secret-as-use-only
	 */
	public function coveringMemberships(Secret $secret, string $userId): array {
		$folderId = $secret->getFolderId();
		if ($folderId === null || $folderId === '') {
			return [];
		}

		$covering = [];
		foreach ($this->ancestorTeamFolders(folderId: $folderId) as $teamFolder) {
			foreach ($this->memberMapper->findByTeamFolder(teamFolderId: $teamFolder->getId()) as $membership) {
				if ($this->membershipCovers(membership: $membership, userId: $userId) === true) {
					$covering[] = $membership;
				}
			}
		}

		return $covering;
	}//end coveringMemberships()

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
	 * @spec openspec/changes/admin-vault-policies/tasks.md#4.1
	 */
	public function ancestorTeamFolders(string $folderId): array {
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
