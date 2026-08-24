<?php

/**
 * Keepiq Team Folder Membership Resolver
 *
 * Turns team-folder MEMBERSHIP rows into the concrete sets the fan-out works
 * on (team-folder-sharing §2.2): a group expands to its current users (static
 * expansion per ADR-003 — there is no live group key), the union of a folder's
 * memberships is its effective user set, and only users with an active
 * encryption suite are eligible recipients. It also answers which memberships
 * cover a given user and which of an owner's secrets live in a folder subtree.
 *
 * Everything here is read-only: no row is written and no ciphertext is seen.
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
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\FolderMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\TeamFolder;
use OCA\Keepiq\Db\TeamFolderMemberMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Resolves team-folder memberships into concrete user and secret sets.
 */
class TeamFolderMembershipResolver {
	/**
	 * Constructor for TeamFolderMembershipResolver.
	 *
	 * @param TeamFolderMemberMapper $memberMapper The membership mapper
	 * @param FolderMapper $folderMapper The folder mapper (subtree walk)
	 * @param SecretMapper $secretMapper The secret mapper
	 * @param EncryptionSuiteMapper $suiteMapper The suite mapper (eligibility filter)
	 * @param IGroupManager $groupManager The Nextcloud group manager
	 * @param IUserManager $userManager The Nextcloud user manager
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private TeamFolderMemberMapper $memberMapper,
		private FolderMapper $folderMapper,
		private SecretMapper $secretMapper,
		private EncryptionSuiteMapper $suiteMapper,
		private IGroupManager $groupManager,
		private IUserManager $userManager,
	) {
	}//end __construct()

	/**
	 * Reject a membership the folder cannot accept: an unknown member type,
	 * a blank id, the folder owner, or a user / group that does not exist.
	 *
	 * @param TeamFolder $teamFolder The team folder receiving the member
	 * @param string $memberType The member type (`user`|`group`)
	 * @param string $memberId The Nextcloud user or group ID
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the membership is not acceptable
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.2
	 */
	public function assertMemberAddable(TeamFolder $teamFolder, string $memberType, string $memberId): void {
		if (in_array($memberType, ['user', 'group'], true) === false) {
			throw new InvalidArgumentException(message: 'memberType must be user or group');
		}

		if ($memberId === '') {
			throw new InvalidArgumentException(message: 'memberId is required');
		}

		if ($memberType === 'group') {
			if ($this->groupManager->get($memberId) === null) {
				throw new InvalidArgumentException(message: 'Group not found');
			}

			return;
		}

		if ($memberId === $teamFolder->getOwnerId()) {
			throw new InvalidArgumentException(message: 'Cannot add the folder owner as a member');
		}

		if ($this->userManager->get($memberId) === null) {
			throw new InvalidArgumentException(message: 'User not found');
		}
	}//end assertMemberAddable()

	/**
	 * Expand a membership to concrete user IDs (a group expands to its
	 * current users — static expansion per ADR-003, no live group key).
	 *
	 * @param string $memberType The member type (`user`|`group`)
	 * @param string $memberId The user or group ID
	 *
	 * @return string[]
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.2
	 */
	public function expandMember(string $memberType, string $memberId): array {
		if ($memberType === 'user') {
			return [$memberId];
		}

		$group = $this->groupManager->get($memberId);
		if ($group === null) {
			return [];
		}

		$ids = [];
		foreach ($group->getUsers() as $user) {
			$ids[] = $user->getUID();
		}

		return $ids;
	}//end expandMember()

	/**
	 * The effective (deduplicated) user set covered by a team folder's
	 * current memberships. The owner is NOT excluded here; callers strip
	 * the owner where relevant.
	 *
	 * @param string $teamFolderId The TeamFolder UUID
	 *
	 * @return string[]
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.2
	 */
	public function effectiveUsers(string $teamFolderId): array {
		$users = [];
		foreach ($this->memberMapper->findByTeamFolder(teamFolderId: $teamFolderId) as $membership) {
			foreach ($this->expandMember(
				memberType: $membership->getMemberType(),
				memberId: $membership->getMemberId()
			) as $memberUserId) {
				$users[$memberUserId] = true;
			}
		}

		return array_keys($users);
	}//end effectiveUsers()

	/**
	 * Filter user IDs to those with an active EncryptionSuite and return
	 * their public certificates for browser-side encryption. Users
	 * without a suite are skipped silently (§2.2).
	 *
	 * @param string[] $userIds The candidate user IDs
	 *
	 * @return array<int,array{userId:string,certificate:string}>
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.2
	 */
	public function eligibleRecipients(array $userIds): array {
		$recipients = [];
		foreach ($userIds as $candidateId) {
			try {
				$suite = $this->suiteMapper->findActiveByOwner(ownerType: 'user', ownerId: $candidateId);
			} catch (DoesNotExistException) {
				continue;
			}

			$recipients[] = [
				'userId' => $candidateId,
				'certificate' => $suite->getCertificate(),
			];
		}

		return $recipients;
	}//end eligibleRecipients()

	/**
	 * All membership rows that cover a user: direct user rows plus group
	 * rows of every group the user belongs to.
	 *
	 * @param string $userId The user
	 *
	 * @return array<int,\OCA\Keepiq\Db\TeamFolderMember>
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#4.1
	 */
	public function membershipRowsForUser(string $userId): array {
		$rows = [];
		$user = $this->userManager->get($userId);

		// Direct user memberships.
		foreach ($this->memberMapper->findUserMemberships(userId: $userId) as $row) {
			$rows[] = $row;
		}

		// Group memberships via the user's groups.
		if ($user !== null) {
			foreach ($this->groupManager->getUserGroupIds($user) as $groupId) {
				foreach ($this->memberMapper->findGroupMemberships(groupId: (string)$groupId) as $row) {
					$rows[] = $row;
				}
			}
		}

		return $rows;
	}//end membershipRowsForUser()

	/**
	 * The owner's secrets in the team folder's subtree (id + plaintext
	 * display name only — names are server-visible metadata already).
	 *
	 * @param TeamFolder $teamFolder The team folder
	 *
	 * @return array<int,array{id:string,name:string}>
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.3
	 */
	public function subtreeSecretRefs(TeamFolder $teamFolder): array {
		$refs = [];
		$folderIds = $this->folderMapper->getSubtreeIds(folderId: $teamFolder->getFolderId());
		foreach ($folderIds as $folderId) {
			foreach ($this->secretMapper->findByOwner(
				ownerType: 'user',
				ownerId: $teamFolder->getOwnerId(),
				folderId: (string)$folderId
			) as $secret) {
				$refs[] = [
					'id' => $secret->getId(),
					'name' => $secret->getName(),
				];
			}
		}

		return $refs;
	}//end subtreeSecretRefs()
}//end class
