<?php

/**
 * Keepiq Team Folder Service
 *
 * Business logic for team folder sharing (team-folder-sharing §2):
 * a TeamFolder attaches shared membership (users + groups) to an existing
 * owner Folder; every secret in the folder subtree is fanned out to the
 * effective member set as ordinary per-recipient RSA copies. There is no
 * shared symmetric key and the server never sees plaintext (ADR-003) —
 * the browser encrypts every (secret × recipient) pair and this service
 * only records provenance-linked ShareTarget rows idempotently.
 *
 * This class owns the ATTACHMENT lifecycle (share, unshare, membership rows)
 * and sequences the collaborators that own the rest:
 *  - TeamFolderQueryService          ownership guards, listings, ancestor-chain
 *                                    recipient and grade resolution
 *  - TeamFolderMembershipResolver    membership validation and expansion,
 *                                    recipient eligibility, subtree secrets
 *  - TeamFolderShareService          the derived ShareTarget rows: fan-out,
 *                                    reconciliation gaps, revocation
 *  - TeamFolderOffboardingService    admin offboarding of a departing user
 *  - TeamFolderAuditor               the team-folder audit vocabulary
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
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Db\TeamFolder;
use OCA\Keepiq\Db\TeamFolderMapper;
use OCA\Keepiq\Db\TeamFolderMember;
use OCA\Keepiq\Db\TeamFolderMemberMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Business logic for the TeamFolder lifecycle.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The service threads
 *   through the team-folder mappers plus the five collaborators that own
 *   the folder fan-out invariants, so the ORDER of the lifecycle lives in
 *   one place.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)   One public method per
 *   API operation of the team-folder lifecycle.
 */
class TeamFolderService {
	/**
	 * Constructor for TeamFolderService.
	 *
	 * @param TeamFolderMapper $mapper The team-folder mapper
	 * @param TeamFolderMemberMapper $memberMapper The membership mapper
	 * @param TeamFolderQueryService $queries The team-folder read side
	 * @param TeamFolderMembershipResolver $memberships The membership resolver
	 * @param TeamFolderShareService $shares The derived-share service
	 * @param TeamFolderOffboardingService $offboarding The offboarding service
	 * @param TeamFolderAuditor $audit The team-folder auditor
	 * @param NotificationService $notificationService The notification dispatcher
	 * @param IDBConnection $db The database connection
	 * @param ShareRestrictionResolver|null $restrictions Materialises use-only and end dates onto copies
	 * @param ShareTargetMapper|null $shareTargets The share-target mapper (copies to recompute)
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI list; the two
	 *   optional collaborators recompute copies after a membership change.
	 */
	public function __construct(
		private TeamFolderMapper $mapper,
		private TeamFolderMemberMapper $memberMapper,
		private TeamFolderQueryService $queries,
		private TeamFolderMembershipResolver $memberships,
		private TeamFolderShareService $shares,
		private TeamFolderOffboardingService $offboarding,
		private TeamFolderAuditor $audit,
		private NotificationService $notificationService,
		private IDBConnection $db,
		private ?ShareRestrictionResolver $restrictions = null,
		private ?ShareTargetMapper $shareTargets = null,
	) {
	}//end __construct()

	/**
	 * Share an owned folder — creates the TeamFolder attachment.
	 *
	 * Idempotent: sharing an already-shared folder returns the existing
	 * TeamFolder unchanged.
	 *
	 * @param string $folderId The Folder UUID to share
	 * @param string $userId The caller (must own the folder)
	 *
	 * @return TeamFolder
	 *
	 * @throws InvalidArgumentException When the folder is missing or not owned
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.1
	 */
	public function shareFolder(string $folderId, string $userId): TeamFolder {
		$folder = $this->queries->loadOwnedFolder(folderId: $folderId, userId: $userId);
		$existing = $this->queries->findByFolder(folderId: $folder->getId());
		if ($existing !== null) {
			return $existing;
		}

		$entity = new TeamFolder();
		$entity->setId(Uuid::uuid4()->toString());
		$entity->setFolderId($folder->getId());
		$entity->setOwnerId($userId);
		$entity->setCreatedAt(new DateTime());
		$entity->setUpdatedAt(new DateTime());
		$persisted = $this->mapper->insert($entity);

		$this->audit->folderShared(
			actorId: $userId,
			teamFolderId: $persisted->getId(),
			folderName: $folder->getName(),
			folderId: $folder->getId(),
		);

		return $persisted;
	}//end shareFolder()

	/**
	 * Unshare a folder — cascade-revokes every derived ShareTarget (and
	 * the recipient Secret copies), removes all memberships and the
	 * TeamFolder row. The folder itself remains as a private folder.
	 *
	 * @param string $teamFolderId The TeamFolder UUID
	 * @param string $userId The caller (must be the owner)
	 *
	 * @return int Number of derived shares revoked
	 *
	 * @throws InvalidArgumentException When not found / not authorized
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.4
	 */
	public function unshareFolder(string $teamFolderId, string $userId): int {
		$teamFolder = $this->queries->loadOwnedTeamFolder(teamFolderId: $teamFolderId, userId: $userId);

		$this->db->beginTransaction();
		try {
			$revoked = $this->shares->revokeForTeamFolder(teamFolderId: $teamFolderId);

			$this->memberMapper->deleteByTeamFolder(teamFolderId: $teamFolderId);
			$this->mapper->delete($teamFolder);
			$this->db->commit();
		} catch (Throwable $exception) {
			$this->db->rollBack();
			throw $exception;
		}

		$this->audit->folderUnshared(
			actorId: $userId,
			teamFolderId: $teamFolderId,
			folderId: $teamFolder->getFolderId(),
			revoked: $revoked,
		);

		return $revoked;
	}//end unshareFolder()

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
		return $this->queries->listForUser(userId: $userId);
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
		return $this->queries->listMembers(teamFolderId: $teamFolderId, userId: $userId);
	}//end listMembers()

	/**
	 * Add a member (user or group) to a team folder and return the
	 * fan-out payload the browser needs: the newly covered eligible
	 * recipients (with their public certificates) and the secrets in the
	 * folder subtree to encrypt for them.
	 *
	 * Idempotent: re-adding an existing membership returns a payload with
	 * no new recipients.
	 *
	 * @param string $teamFolderId The TeamFolder UUID
	 * @param string $memberType The member type (`user`|`group`)
	 * @param string $memberId The Nextcloud user or group ID
	 * @param string $userId The caller (the owner or a manager)
	 * @param ShareRestriction|null $restriction Use-only (read grade only) and end date of the membership
	 *
	 * @return array{member:TeamFolderMember,recipients:array<int,array{userId:string,certificate:string}>,secrets:array<int,array{id:string,name:string}>}
	 *
	 * @throws InvalidArgumentException On invalid input / not authorized
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.2
	 * @spec openspec/changes/sharing-use-only-and-expiring-shares/tasks.md#task-2.2
	 */
	public function addMember(
		string $teamFolderId,
		string $memberType,
		string $memberId,
		string $userId,
		?ShareRestriction $restriction = null,
	): array {
		$teamFolder = $this->queries->loadManageableTeamFolder(teamFolderId: $teamFolderId, userId: $userId);
		$this->memberships->assertMemberAddable(
			teamFolder: $teamFolder,
			memberType: $memberType,
			memberId: $memberId
		);

		$coveredBefore = $this->memberships->effectiveUsers(teamFolderId: $teamFolderId);
		$membership = $this->findOrCreateMembership(
			teamFolderId: $teamFolderId,
			memberType: $memberType,
			memberId: $memberId,
			userId: $userId
		);
		if ($restriction !== null) {
			$membership = $this->applyRestriction(membership: $membership, restriction: $restriction);
		}

		$newUsers = array_values(
			array_diff(
				$this->memberships->expandMember(memberType: $memberType, memberId: $memberId),
				$coveredBefore,
				[$teamFolder->getOwnerId()]
			)
		);

		return [
			'member' => $membership,
			'recipients' => $this->memberships->eligibleRecipients(userIds: $newUsers),
			'secrets' => $this->withCallerCopies(
				refs: $this->memberships->subtreeSecretRefs(teamFolder: $teamFolder),
				teamFolder: $teamFolder,
				userId: $userId
			),
		];
	}//end addMember()

	/**
	 * Return the existing membership row, or create (and audit) a new one.
	 *
	 * @param string $teamFolderId The TeamFolder UUID
	 * @param string $memberType The member type (`user`|`group`)
	 * @param string $memberId The Nextcloud user or group ID
	 * @param string $userId The caller adding the member
	 *
	 * @return TeamFolderMember
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.2
	 */
	private function findOrCreateMembership(
		string $teamFolderId,
		string $memberType,
		string $memberId,
		string $userId,
	): TeamFolderMember {
		try {
			return $this->memberMapper->findMembership(
				teamFolderId: $teamFolderId,
				memberType: $memberType,
				memberId: $memberId
			);
		} catch (DoesNotExistException) {
			// No membership yet — create it below.
		}

		$membership = new TeamFolderMember();
		$membership->setId(Uuid::uuid4()->toString());
		$membership->setTeamFolderId($teamFolderId);
		$membership->setMemberType($memberType);
		$membership->setMemberId($memberId);
		$membership->setAddedBy($userId);
		$membership->setCreatedAt(new DateTime());
		$membership = $this->memberMapper->insert($membership);

		$this->audit->memberAdded(
			actorId: $userId,
			teamFolderId: $teamFolderId,
			memberType: $memberType,
			memberId: $memberId,
		);

		return $membership;
	}//end findOrCreateMembership()

	/**
	 * Remove a membership row — revokes the derived shares of every user
	 * that is no longer covered by any remaining membership.
	 *
	 * @param string $teamFolderId The TeamFolder UUID
	 * @param string $membershipId The membership row UUID
	 * @param string $userId The caller (the owner or a manager)
	 *
	 * @return int Number of derived shares revoked
	 *
	 * @throws InvalidArgumentException On not found / not authorized
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.2
	 */
	public function removeMember(string $teamFolderId, string $membershipId, string $userId): int {
		$teamFolder = $this->queries->loadManageableTeamFolder(teamFolderId: $teamFolderId, userId: $userId);

		try {
			$membership = $this->memberMapper->findById(id: $membershipId);
		} catch (DoesNotExistException) {
			throw new InvalidArgumentException(message: 'Membership not found');
		}

		if ($membership->getTeamFolderId() !== $teamFolderId) {
			throw new InvalidArgumentException(message: 'Membership does not belong to this team folder');
		}

		$this->assertManagerMayTouch(teamFolder: $teamFolder, membership: $membership, userId: $userId, leaving: true);
		$this->memberMapper->delete($membership);

		$coveredAfter = $this->memberships->effectiveUsers(teamFolderId: $teamFolderId);
		$dropped = array_diff(
			$this->memberships->expandMember(
				memberType: $membership->getMemberType(),
				memberId: $membership->getMemberId()
			),
			$coveredAfter
		);

		$revoked = 0;
		foreach ($dropped as $droppedUserId) {
			$revoked += $this->shares->revokeForMember(
				teamFolderId: $teamFolderId,
				targetUserId: $droppedUserId
			);
		}

		// Users still covered by another grant may have lost the one that
		// lifted use-only or extended their access.
		$this->resolveCopiesOf(membership: $membership);

		$this->audit->memberRemoved(
			actorId: $userId,
			teamFolderId: $teamFolderId,
			memberType: $membership->getMemberType(),
			memberId: $membership->getMemberId(),
			revoked: $revoked,
		);

		return $revoked;
	}//end removeMember()

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
		return $this->queries->resolveRecipients(secretId: $secretId);
	}//end resolveRecipients()

	/**
	 * Reconciliation: re-derive the expected (secret × recipient) set for
	 * a team folder and return the pairs that are missing a ShareTarget,
	 * together with recipient certificates so the browser can encrypt
	 * them. Idempotent server writes make a partial fan-out self-heal.
	 *
	 * @param string $teamFolderId The TeamFolder UUID
	 * @param string $userId The caller (the owner or a manager)
	 *
	 * @return array{secrets:array<int,array{id:string,name:string}>,recipients:array<int,array{userId:string,certificate:string}>,missing:array<int,array{secretId:string,userId:string}>}
	 *
	 * @throws InvalidArgumentException On not found / not authorized
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.4
	 */
	public function reconcile(string $teamFolderId, string $userId): array {
		$teamFolder = $this->queries->loadManageableTeamFolder(teamFolderId: $teamFolderId, userId: $userId);

		$secrets = $this->withCallerCopies(
			refs: $this->memberships->subtreeSecretRefs(teamFolder: $teamFolder),
			teamFolder: $teamFolder,
			userId: $userId
		);
		$recipients = $this->memberships->eligibleRecipients(
			userIds: array_values(
				array_diff(
					$this->memberships->effectiveUsers(teamFolderId: $teamFolderId),
					[$teamFolder->getOwnerId()]
				)
			)
		);

		return [
			'secrets' => $secrets,
			'recipients' => $recipients,
			'missing' => $this->shares->missingPairs(secrets: $secrets, recipients: $recipients),
		];
	}//end reconcile()

	/**
	 * Register a batch of browser-encrypted fan-out shares — the caller is
	 * authorized here, the ciphertext rows are materialised by
	 * TeamFolderShareService.
	 *
	 * @param string $teamFolderId The TeamFolder UUID
	 * @param array<int,array<string,mixed>> $shares Rows of sourceSecretId, targetUserId,
	 *                                               encryptedKey, encryptedLogin,
	 *                                               encryptedAdditionalFields
	 * @param string $userId The caller (the owner or a manager)
	 *
	 * @return array{created: int, rows: array<int,array{sourceSecretId: string, targetUserId: string, recipientSecretId: string}>}
	 *
	 * @throws InvalidArgumentException On not found / not authorized
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.4
	 */
	public function registerFanOutShares(string $teamFolderId, array $shares, string $userId): array {
		$teamFolder = $this->queries->loadManageableTeamFolder(teamFolderId: $teamFolderId, userId: $userId);

		$subtreeSecretIds = [];
		foreach ($this->memberships->subtreeSecretRefs(teamFolder: $teamFolder) as $secretRef) {
			$subtreeSecretIds[$secretRef['id']] = true;
		}

		return $this->shares->registerFanOutShares(
			teamFolder: $teamFolder,
			shares: $shares,
			subtreeSecretIds: $subtreeSecretIds,
			userId: $userId
		);
	}//end registerFanOutShares()

	/**
	 * Group-join propagation: a user joined a group that is a member of
	 * one or more team folders — notify each folder owner so they can
	 * approve the join (approval triggers the fan-out for that user).
	 *
	 * @param string $userId The user newly added to the group
	 * @param string $groupId The group that gained the member
	 *
	 * @return int Number of notifications dispatched
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#3.1
	 */
	public function handleGroupMemberJoin(string $userId, string $groupId): int {
		$dispatched = 0;
		foreach ($this->memberMapper->findGroupMemberships(groupId: $groupId) as $membership) {
			try {
				$teamFolder = $this->mapper->findById(id: $membership->getTeamFolderId());
			} catch (DoesNotExistException) {
				continue;
			}

			if ($teamFolder->getOwnerId() === $userId) {
				continue;
			}

			$this->notificationService->notify(
				subject: 'team_folder_join_request',
				recipientId: $teamFolder->getOwnerId(),
				params: [
					'newMemberId' => $userId,
					'groupId' => $groupId,
					'teamFolderId' => $teamFolder->getId(),
				],
				objectType: 'team_folder',
				objectId: $teamFolder->getId(),
			);
			++$dispatched;
		}//end foreach

		return $dispatched;
	}//end handleGroupMemberJoin()

	/**
	 * Owner approval of a group join: returns the fan-out payload for the
	 * approved user (their certificate + the subtree secrets) so the
	 * browser can encrypt and register the shares.
	 *
	 * @param string $teamFolderId The TeamFolder UUID
	 * @param string $newMemberId The approved user's Nextcloud user ID
	 * @param string $userId The approver (the owner or a manager)
	 *
	 * @return array{recipients:array<int,array{userId:string,certificate:string}>,secrets:array<int,array{id:string,name:string}>}
	 *
	 * @throws InvalidArgumentException On not found / not authorized / not covered
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#3.1
	 */
	public function approveJoin(string $teamFolderId, string $newMemberId, string $userId): array {
		$teamFolder = $this->queries->loadManageableTeamFolder(teamFolderId: $teamFolderId, userId: $userId);

		$covered = $this->memberships->effectiveUsers(teamFolderId: $teamFolderId);
		if (in_array($newMemberId, $covered, true) === false) {
			throw new InvalidArgumentException(message: 'User is not covered by this team folder\'s membership');
		}

		return [
			'recipients' => $this->memberships->eligibleRecipients(userIds: [$newMemberId]),
			'secrets' => $this->withCallerCopies(
				refs: $this->memberships->subtreeSecretRefs(teamFolder: $teamFolder),
				teamFolder: $teamFolder,
				userId: $userId
			),
		];
	}//end approveJoin()

	/**
	 * Group-leave propagation: revoke the departing user's derived shares
	 * for every team folder whose only coverage of the user was the group
	 * they just left. Direct shares and other memberships stay intact.
	 *
	 * @param string $userId The departing user
	 * @param string $groupId The group they left
	 *
	 * @return int Number of ShareTargets revoked
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#3.2
	 */
	public function handleGroupMemberLeave(string $userId, string $groupId): int {
		$revoked = 0;
		foreach ($this->memberMapper->findGroupMemberships(groupId: $groupId) as $membership) {
			$teamFolderId = $membership->getTeamFolderId();
			$covered = $this->memberships->effectiveUsers(teamFolderId: $teamFolderId);
			if (in_array($userId, $covered, true) === true) {
				// Still covered by a direct membership or another group.
				continue;
			}

			$revoked += $this->shares->revokeForMember(teamFolderId: $teamFolderId, targetUserId: $userId);
		}

		return $revoked;
	}//end handleGroupMemberLeave()

	/**
	 * Admin offboarding: revoke every team-folder-derived share held by
	 * the leaving user, then transfer each team secret the leaver OWNS to
	 * the successor. Run by TeamFolderOffboardingService.
	 *
	 * @param string $leavingUserId The user being offboarded
	 * @param string $successorUserId The user taking over owned team secrets
	 * @param string $adminId The caller (instance admin or vault_admin)
	 *
	 * @return array{revoked:int,transferred:int,skipped:array<int,string>}
	 *
	 * @throws InvalidArgumentException On invalid input / not authorized
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.5
	 */
	public function offboard(string $leavingUserId, string $successorUserId, string $adminId): array {
		return $this->offboarding->offboard(
			leavingUserId: $leavingUserId,
			successorUserId: $successorUserId,
			adminId: $adminId
		);
	}//end offboard()

	/**
	 * Set a membership's permission grade — owner-only; grade changes
	 * touch no ciphertext (folder-permission-grades §2.1).
	 *
	 * @param string $teamFolderId The team folder UUID
	 * @param string $memberId The membership row UUID
	 * @param string $grade The grade (`read`|`write`)
	 * @param string $ownerId The calling user (the owner or a manager)
	 * @param ShareRestriction|null $restriction New use-only flag and end date (null = leave them)
	 *
	 * @return TeamFolderMember
	 *
	 * @throws InvalidArgumentException On non-owner, unknown member, or invalid grade
	 *
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-team-folder-membership-carries-a-read-or-write-grade
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-grade-changes-and-non-owner-writes-are-audited
	 * @spec openspec/changes/sharing-use-only-and-expiring-shares/tasks.md#task-2.2
	 */
	public function setMemberGrade(
		string $teamFolderId,
		string $memberId,
		string $grade,
		string $ownerId,
		?ShareRestriction $restriction = null,
	): TeamFolderMember {
		if (in_array($grade, TeamFolderMember::GRADES, true) === false) {
			throw new InvalidArgumentException(message: 'grade must be read, write or manage');
		}

		$teamFolder = $this->queries->loadManageableTeamFolder(teamFolderId: $teamFolderId, userId: $ownerId);

		try {
			$member = $this->memberMapper->findById($memberId);
		} catch (DoesNotExistException) {
			throw new InvalidArgumentException(message: 'Membership not found');
		}

		if ($member->getTeamFolderId() !== $teamFolderId) {
			throw new InvalidArgumentException(message: 'Membership not found');
		}

		$this->assertManagerMayTouch(teamFolder: $teamFolder, membership: $member, userId: $ownerId, leaving: false);
		if ($grade === 'manage' && $teamFolder->getOwnerId() !== $ownerId) {
			throw new InvalidArgumentException(message: 'Only the owner can make a member a manager');
		}

		$member->setGrade($grade);
		if ($restriction === null && $grade !== 'read') {
			// Use-only is a read-grade option; an editor sees the value.
			$restriction = new ShareRestriction(useOnly: false, expiresAt: $member->getExpiresAt());
		}

		if ($restriction !== null) {
			$this->assertRestrictionFitsGrade(grade: $member->effectiveGrade(), restriction: $restriction);
			$member->setUseOnly($restriction->useOnly);
			$member->setExpiresAt($restriction->expiresAt);
		}

		$member = $this->memberMapper->update($member);
		$this->resolveCopiesOf(membership: $member);

		$this->audit->gradeChanged(
			actorId: $ownerId,
			teamFolderId: $teamFolderId,
			memberType: $member->getMemberType(),
			memberId: $member->getMemberId(),
			grade: $grade,
		);

		return $member;
	}//end setMemberGrade()

	/**
	 * Tell a manager's browser which of its own recipient copies to decrypt
	 * for each folder secret (sharing-team-folder-manager-role D3): each ref
	 * gains `copyId`, the caller's copy, or null when the caller holds none
	 * (that secret is then skipped and stays missing for the owner). The
	 * owner's refs carry their own id, since the owner decrypts the source.
	 *
	 * @param array<int,array{id:string,name:string}> $refs The subtree secret refs
	 * @param TeamFolder $teamFolder The team folder
	 * @param string $userId The caller
	 *
	 * @return array<int,array{id:string,name:string,copyId:string|null}>
	 *
	 * @spec openspec/changes/sharing-team-folder-manager-role/specs/folder-permission-grades/spec.md#requirement-managers-keep-the-membership-current
	 */
	private function withCallerCopies(array $refs, TeamFolder $teamFolder, string $userId): array {
		$isOwner = ($teamFolder->getOwnerId() === $userId);
		foreach ($refs as $index => $ref) {
			$copyId = null;
			if ($isOwner === true) {
				$copyId = $ref['id'];
			} elseif ($this->shareTargets !== null) {
				try {
					$copyId = $this->shareTargets
						->findBySourceSecretAndTargetUser(sourceSecretId: $ref['id'], targetUserId: $userId)
						->getSecretId();
				} catch (DoesNotExistException) {
					$copyId = null;
				}
			}

			$refs[$index]['copyId'] = $copyId;
		}

		return $refs;
	}//end withCallerCopies()

	/**
	 * Keep a manager below the owner (sharing-team-folder-manager-role D2):
	 * a manager who is not the owner may not change or remove a manager,
	 * except that a manager may remove their own membership (leave).
	 *
	 * @param TeamFolder       $teamFolder The team folder
	 * @param TeamFolderMember $membership The membership being changed or removed
	 * @param string           $userId     The caller
	 * @param bool             $leaving    Whether this is a removal (own removal allowed)
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a manager reaches above their role
	 *
	 * @spec openspec/changes/sharing-team-folder-manager-role/specs/folder-permission-grades/spec.md#requirement-only-the-owner-governs-managers-and-the-folder-itself
	 */
	private function assertManagerMayTouch(
		TeamFolder $teamFolder,
		TeamFolderMember $membership,
		string $userId,
		bool $leaving,
	): void {
		if ($teamFolder->getOwnerId() === $userId) {
			return;
		}

		$ownMembership = ($membership->getMemberType() === 'user' && $membership->getMemberId() === $userId);
		if ($leaving === true && $ownMembership === true) {
			return;
		}

		if ($membership->effectiveGrade() === 'manage') {
			throw new InvalidArgumentException(message: 'Only the owner can change or remove a manager');
		}
	}//end assertManagerMayTouch()

	/**
	 * Set a membership's use-only flag and end date, refusing use-only on a
	 * grade other than `read`, and recompute the covered copies.
	 *
	 * @param TeamFolderMember $membership The membership row
	 * @param ShareRestriction $restriction The new flag and end date
	 *
	 * @return TeamFolderMember
	 *
	 * @throws InvalidArgumentException When use-only is asked for a write grade
	 *
	 * @spec openspec/changes/sharing-use-only-and-expiring-shares/tasks.md#task-2.2
	 */
	private function applyRestriction(TeamFolderMember $membership, ShareRestriction $restriction): TeamFolderMember {
		$this->assertRestrictionFitsGrade(grade: $membership->effectiveGrade(), restriction: $restriction);
		$membership->setUseOnly($restriction->useOnly);
		$membership->setExpiresAt($restriction->expiresAt);
		$membership = $this->memberMapper->update($membership);
		$this->resolveCopiesOf(membership: $membership);

		return $membership;
	}//end applyRestriction()

	/**
	 * Refuse use-only on any grade but `read` (D2: editing a value you
	 * cannot see is not offered).
	 *
	 * @param string $grade The membership's effective grade
	 * @param ShareRestriction $restriction The requested flag and end date
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When use-only is asked for a write grade
	 *
	 * @spec openspec/changes/sharing-use-only-and-expiring-shares/specs/use-only-shares/spec.md#requirement-owners-can-share-a-secret-as-use-only
	 */
	private function assertRestrictionFitsGrade(string $grade, ShareRestriction $restriction): void {
		if ($restriction->useOnly === true && $grade !== 'read') {
			throw new InvalidArgumentException(message: 'Use only is available for the read grade only');
		}
	}//end assertRestrictionFitsGrade()

	/**
	 * Recompute the recipient copies of every user a membership covers.
	 *
	 * @param TeamFolderMember $membership The membership row
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sharing-use-only-and-expiring-shares/tasks.md#task-1.2
	 */
	private function resolveCopiesOf(TeamFolderMember $membership): void {
		if ($this->restrictions === null || $this->shareTargets === null) {
			return;
		}

		$users = $this->memberships->expandMember(
			memberType: $membership->getMemberType(),
			memberId: $membership->getMemberId()
		);
		foreach ($users as $coveredUserId) {
			$this->restrictions->resolveTargets(targets: $this->shareTargets->findByTargetUser($coveredUserId));
		}
	}//end resolveCopiesOf()

	/**
	 * The MAX grade any team-folder membership along a secret's folder
	 * ancestor chain grants a user (`write` outranks `read`), or null
	 * when nothing applies.
	 *
	 * @param Secret $secret The SOURCE secret
	 * @param string $userId The candidate user
	 *
	 * @return string|null `write`, `read`, or null
	 *
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-effective-grade-is-the-highest-grade-along-the-ancestor-folder-chain
	 */
	public function resolveGrade(Secret $secret, string $userId): ?string {
		return $this->queries->resolveGrade(secret: $secret, userId: $userId);
	}//end resolveGrade()
}//end class
