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

use DateTime;
use InvalidArgumentException;
use OCA\Keepiq\Db\Folder;
use OCA\Keepiq\Db\FolderMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\TeamFolder;
use OCA\Keepiq\Db\TeamFolderMapper;
use OCA\Keepiq\Db\TeamFolderMember;
use OCA\Keepiq\Db\TeamFolderMemberMapper;
use OCA\Keepiq\Exception\ManagerOnlyException;
use OCA\Keepiq\Exception\OwnerOnlyException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Group\ISubAdmin;
use OCP\IGroupManager;

/**
 * Read-side lookups and ancestor-chain resolution for team folders.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The read side of team folders,
 *   including the ancestor walks that grades and restrictions both need.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) One public lookup per caller need.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The read side joins folders,
 *   team folders, memberships, groups and secrets, and the manage check
 *   refuses with its own exception so a viewer's grade change reads as
 *   forbidden (folder-permission-grades).
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
	 * @param ISubAdmin $subAdmin Group subadmins (a subadmin can join the group)
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
		private ISubAdmin $subAdmin,
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

			$memberOf[] = $this->memberEntry(teamFolder: $teamFolder, userId: $userId);
		}

		return [
			'owned' => $owned,
			'memberOf' => $memberOf,
		];
	}//end listForUser()

	/**
	 * A shared team folder as its member sees it.
	 *
	 * Recipients see the folder identity, never the member list
	 * (share-visibility rule, user-sharing spec); a manager sees the list it
	 * manages (sharing-team-folder-manager-role D5) and the rows only the
	 * owner may change, so its browser does not offer controls the server
	 * refuses.
	 *
	 * @param TeamFolder $teamFolder The shared team folder
	 * @param string     $userId     The member
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#4.1
	 */
	private function memberEntry(TeamFolder $teamFolder, string $userId): array {
		$grade = $this->gradeOnTeamFolder(teamFolder: $teamFolder, userId: $userId);
		$entry = $this->describe(teamFolder: $teamFolder, includeMembers: $grade === 'manage');
		$entry['grade'] = $grade;
		if ($grade === 'manage') {
			$entry['ownerOnlyMemberIds'] = $this->ownerOnlyMemberIds(
				teamFolder: $teamFolder,
				members: $entry['members'],
				userId: $userId
			);
		}

		return $entry;
	}//end memberEntry()

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
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-effective-grade-is-the-highest-grade-along-the-ancestor-folder-chain
	 */
	public function gradeOnTeamFolder(TeamFolder $teamFolder, string $userId): ?string {
		$best = null;
		foreach ($this->ancestorTeamFolders(folderId: $teamFolder->getFolderId()) as $ancestor) {
			foreach ($this->memberMapper->findByTeamFolder(teamFolderId: $ancestor->getId()) as $membership) {
				if ($this->hasEnded(membership: $membership) === true) {
					// Past its end date: it grants nothing, even before
					// ExpireSharesJob removes it.
					continue;
				}

				if ($this->membershipCovers(membership: $membership, userId: $userId) === true) {
					$best = $this->higherGrade(current: $best, candidate: $membership->effectiveGrade());
				}
			}
		}

		return $best;
	}//end gradeOnTeamFolder()

	/**
	 * Whether a membership's end date has passed.
	 *
	 * @param TeamFolderMember $membership The membership row
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/expiring-shares/spec.md#requirement-a-background-job-removes-expired-access
	 */
	private function hasEnded(TeamFolderMember $membership): bool {
		$expiresAt = $membership->getExpiresAt();

		return $expiresAt !== null && $expiresAt <= new DateTime();
	}//end hasEnded()

	/**
	 * The `manage` membership rows whose managers a membership of this team
	 * folder can reach: the rows of the folder, of its ancestor team folders
	 * and of the team folders nested under it. A row on a parent covers the
	 * nested folders too, so a nested folder's manager counts as well. A row
	 * past its end date still counts, because the owner set that end date and
	 * only the owner may undo it.
	 *
	 * @param TeamFolder $teamFolder The team folder
	 *
	 * @return TeamFolderMember[]
	 *
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-only-the-owner-governs-managers-and-the-folder-itself
	 */
	private function manageRows(TeamFolder $teamFolder): array {
		$teamFolders = [];
		foreach ($this->ancestorTeamFolders(folderId: $teamFolder->getFolderId()) as $ancestor) {
			$teamFolders[$ancestor->getId()] = $ancestor;
		}

		foreach ($this->folderMapper->getSubtreeIds($teamFolder->getFolderId()) as $folderId) {
			try {
				$nested = $this->mapper->findByFolder(folderId: (string)$folderId);
			} catch (DoesNotExistException) {
				continue;
			}

			$teamFolders[$nested->getId()] = $nested;
		}

		$rows = [];
		foreach ($teamFolders as $candidate) {
			foreach ($this->memberMapper->findByTeamFolder(teamFolderId: $candidate->getId()) as $membership) {
				if ($membership->effectiveGrade() === 'manage') {
					$rows[] = $membership;
				}
			}
		}

		return $rows;
	}//end manageRows()

	/**
	 * The managers behind `manage` rows, the owner left out. Only a `manage`
	 * group row is expanded; those groups name who manages, so they stay small.
	 *
	 * @param TeamFolderMember[] $manageRows The `manage` rows (see manageRows())
	 * @param string             $ownerId    The team folder's owner
	 *
	 * @return string[] User IDs
	 *
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-only-the-owner-governs-managers-and-the-folder-itself
	 */
	private function managersFrom(array $manageRows, string $ownerId): array {
		$managers = [];
		foreach ($manageRows as $membership) {
			$expanded = $this->memberships->expandMember(
				memberType: $membership->getMemberType(),
				memberId: $membership->getMemberId()
			);
			foreach ($expanded as $managerId) {
				$managers[(string)$managerId] = true;
			}
		}

		$managers = array_keys($managers);

		return array_values(
			array_filter(
				array_map('strval', $managers),
				static fn (string $managerId): bool => self::sameUser(left: $managerId, right: $ownerId) === false
			)
		);
	}//end managersFrom()

	/**
	 * The managers of a team folder: every user other than the owner whom a
	 * `manage` row of it, of an ancestor team folder or of a team folder nested
	 * under it covers.
	 *
	 * @param TeamFolder $teamFolder The team folder
	 *
	 * @return string[] User IDs
	 *
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-only-the-owner-governs-managers-and-the-folder-itself
	 */
	public function managersOf(TeamFolder $teamFolder): array {
		return $this->managersFrom(manageRows: $this->manageRows(teamFolder: $teamFolder), ownerId: $teamFolder->getOwnerId());
	}//end managersOf()

	/**
	 * Whether two Nextcloud user IDs name the same user; user lookups ignore
	 * case, so the comparison does too.
	 *
	 * @param string $left  One user ID
	 * @param string $right The other user ID
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-only-the-owner-governs-managers-and-the-folder-itself
	 */
	private static function sameUser(string $left, string $right): bool {
		return mb_strtolower($left) === mb_strtolower($right);
	}//end sameUser()

	/**
	 * Whether a membership covers one of the given users, or may come to cover
	 * them without the owner: a user membership of one of them, or a group one
	 * of them is in or is a subadmin of (a subadmin can add themselves to the
	 * group). Membership is read through `isInGroup()`, as the read side does.
	 *
	 * @param string   $memberType The membership's member type (`user`|`group`)
	 * @param string   $memberId   The membership's user or group ID
	 * @param string[] $userIds    The users to look for
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-only-the-owner-governs-managers-and-the-folder-itself
	 */
	public function mayCoverAnyOf(string $memberType, string $memberId, array $userIds): bool {
		if ($memberType === 'user') {
			foreach ($userIds as $userId) {
				if (self::sameUser(left: $memberId, right: $userId) === true) {
					return true;
				}
			}

			return false;
		}

		if ($memberType !== 'group') {
			return false;
		}

		foreach ($userIds as $userId) {
			if ($this->groupManager->isInGroup($userId, $memberId) === true) {
				return true;
			}
		}

		return $this->hasSubAdminAmong(groupId: $memberId, userIds: $userIds);
	}//end mayCoverAnyOf()

	/**
	 * Whether one of the given users is a subadmin of a group, and so can add
	 * themselves to it.
	 *
	 * @param string   $groupId The group ID
	 * @param string[] $userIds The users to look for
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-only-the-owner-governs-managers-and-the-folder-itself
	 */
	private function hasSubAdminAmong(string $groupId, array $userIds): bool {
		$group = $this->groupManager->get($groupId);
		if ($group === null) {
			return false;
		}

		foreach ($this->subAdmin->getGroupsSubAdmins($group) as $subAdmin) {
			foreach ($userIds as $userId) {
				if (self::sameUser(left: $subAdmin->getUID(), right: $userId) === true) {
					return true;
				}
			}
		}

		return false;
	}//end hasSubAdminAmong()

	/**
	 * Keep every manager's access with the owner: a caller who is not the
	 * owner may not add or change a membership that covers a manager, the
	 * caller included, or may come to cover one without the owner (a group a
	 * manager is a subadmin of). Grants are unioned, so any such membership
	 * without the owner's use-only flag or end date would let a manager keep
	 * the folder past the end the owner set.
	 *
	 * @param TeamFolder $teamFolder The team folder
	 * @param string     $memberType The membership's member type (`user`|`group`)
	 * @param string     $memberId   The membership's user or group ID
	 * @param string     $userId     The caller
	 *
	 * @return void
	 *
	 * @throws OwnerOnlyException When the membership covers a manager and the caller is not the owner
	 *
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-only-the-owner-governs-managers-and-the-folder-itself
	 */
	public function assertCoversNoManager(
		TeamFolder $teamFolder,
		string $memberType,
		string $memberId,
		string $userId,
	): void {
		if ($teamFolder->getOwnerId() === $userId) {
			return;
		}

		if ($this->coversAManager(teamFolder: $teamFolder, memberType: $memberType, memberId: $memberId) === true) {
			throw new OwnerOnlyException(message: 'Only the owner can add or change a membership that covers a manager');
		}
	}//end assertCoversNoManager()

	/**
	 * Whether a membership covers a manager of the team folder, or may come to
	 * cover one without the owner; such a membership is the owner's to set.
	 *
	 * @param TeamFolder $teamFolder The team folder
	 * @param string     $memberType The membership's member type (`user`|`group`)
	 * @param string     $memberId   The membership's user or group ID
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-only-the-owner-governs-managers-and-the-folder-itself
	 */
	private function coversAManager(TeamFolder $teamFolder, string $memberType, string $memberId): bool {
		$manageRows = $this->manageRows(teamFolder: $teamFolder);
		if ($memberType === 'user') {
			// One user: ask each `manage` row whether it covers them, so no
			// group is expanded. The owner is never a member row (see
			// TeamFolderMembershipResolver::assertMemberAddable()).
			return $this->anyRowCovers(manageRows: $manageRows, userId: $memberId);
		}

		return $this->mayCoverAnyOf(
			memberType: $memberType,
			memberId: $memberId,
			userIds: $this->managersFrom(manageRows: $manageRows, ownerId: $teamFolder->getOwnerId())
		);
	}//end coversAManager()

	/**
	 * Whether any of the given rows covers a user: a user row of theirs, or a
	 * group row of a group they are in.
	 *
	 * @param TeamFolderMember[] $manageRows The rows
	 * @param string             $userId     The user
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-only-the-owner-governs-managers-and-the-folder-itself
	 */
	private function anyRowCovers(array $manageRows, string $userId): bool {
		foreach ($manageRows as $membership) {
			if ($membership->getMemberType() === 'user'
				&& self::sameUser(left: $membership->getMemberId(), right: $userId) === true
			) {
				return true;
			}

			if ($membership->getMemberType() === 'group'
				&& $this->groupManager->isInGroup($userId, $membership->getMemberId()) === true
			) {
				return true;
			}
		}

		return false;
	}//end anyRowCovers()

	/**
	 * The ids of the membership rows a caller who is not the owner may not
	 * change: every `manage` row and every row that covers a manager, or may
	 * come to cover one. The browser greys out their controls; the server
	 * refuses the change either way. Empty for the owner. The managers are
	 * worked out once for the whole list.
	 *
	 * @param TeamFolder              $teamFolder The team folder
	 * @param array<TeamFolderMember> $members    Its membership rows
	 * @param string                  $userId     The caller
	 *
	 * @return string[]
	 *
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-only-the-owner-governs-managers-and-the-folder-itself
	 */
	public function ownerOnlyMemberIds(TeamFolder $teamFolder, array $members, string $userId): array {
		if ($teamFolder->getOwnerId() === $userId || $members === []) {
			return [];
		}

		$managers = $this->managersOf(teamFolder: $teamFolder);
		$ids = [];
		foreach ($members as $member) {
			if ($member->effectiveGrade() === 'manage'
				|| $this->mayCoverAnyOf(
					memberType: $member->getMemberType(),
					memberId: $member->getMemberId(),
					userIds: $managers
				) === true
			) {
				$ids[] = (string)$member->getId();
			}
		}

		return $ids;
	}//end ownerOnlyMemberIds()

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
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-managers-keep-the-membership-current
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
			// A viewer or an editor: forbidden, not a bad request
			// (folder-permission-grades, "Non-owner cannot change a grade").
			throw new ManagerOnlyException(message: 'Not authorized to manage this team folder');
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
	 * @spec openspec/specs/vault-policies/spec.md#requirement-work-logins-are-kept-in-team-folders
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
