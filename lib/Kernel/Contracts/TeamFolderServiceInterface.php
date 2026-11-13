<?php

/**
 * Contract of TeamFolderService
 *
 * The public surface of TeamFolderService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Keepiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/keepiq
 *
 * @spec openspec/specs/app-shell/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Kernel\Contracts;

use InvalidArgumentException;
use OCA\Keepiq\Db\TeamFolder;
use OCA\Keepiq\Db\TeamFolderMember;
use OCA\Keepiq\Exception\OwnerOnlyException;
use OCA\Keepiq\Exception\ShareScanPendingException;
use OCA\Keepiq\Service\ShareRestriction;

/**
 * Contract of TeamFolderService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TeamFolderServiceInterface {

	/**
	 * Add a member (user or group) to a team folder and return the
	 * fan-out payload the browser needs: the newly covered eligible
	 * recipients (with their public certificates) and the secrets in the
	 * folder subtree to encrypt for them.
	 *
	 * Re-adding an existing membership adds no row and returns a payload
	 * with no new recipients; a restriction sent with it replaces the
	 * membership's use-only flag and end date, which is audited.
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
	 * @throws OwnerOnlyException When a manager adds or changes a membership that covers a manager, or changes a
	 *   manager's restriction
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.2
	 * @spec openspec/changes/archive/2026-10-04-sharing-use-only-and-expiring-shares/tasks.md#task-2.2
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-only-the-owner-governs-managers-and-the-folder-itself
	 */
	public function addMember(
		string $teamFolderId,
		string $memberType,
		string $memberId,
		string $userId,
		?ShareRestriction $restriction = null,
	): array;

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
	 * @throws OwnerOnlyException When a manager approves their own join
	 *
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-only-the-owner-governs-managers-and-the-folder-itself
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#3.1
	 */
	public function approveJoin(string $teamFolderId, string $newMemberId, string $userId): array;

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
	public function handleGroupMemberJoin(string $userId, string $groupId): int;

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
	public function handleGroupMemberLeave(string $userId, string $groupId): int;

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
	public function listForUser(string $userId): array;

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
	public function listMembers(string $teamFolderId, string $userId): array;

	/**
	 * Admin offboarding: revoke every team-folder-derived share held by
	 * the leaving user, then transfer each team secret the leaver OWNS to
	 * the successor. Run by TeamFolderOffboardingService.
	 *
	 * @param string $leavingUserId The user being offboarded
	 * @param string $successorUserId The user taking over owned team secrets
	 * @param string $adminId The caller (instance admin or People area holder)
	 *
	 * @return array{revoked:int,transferred:int,skipped:array<int,string>}
	 *
	 * @throws InvalidArgumentException On invalid input / not authorized
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.5
	 */
	public function offboard(string $leavingUserId, string $successorUserId, string $adminId): array;

	/**
	 * Reconciliation: re-derive the expected (secret × recipient) set for
	 * a team folder and return the pairs that are missing a ShareTarget,
	 * together with recipient certificates so the browser can encrypt
	 * them. Idempotent server writes make a partial fan-out self-heal.
	 *
	 * @param string $teamFolderId The TeamFolder UUID
	 * @param string $userId The caller (the owner or a manager)
	 *
	 * @return array{secrets:array<int,array{id:string,name:string}>,recipients:array<int,array{userId:string,certificate:string}>,missing:array<int,array{secretId:string,userId:string}>,confirmedBy:object}
	 *
	 * @throws InvalidArgumentException On not found / not authorized
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.4
	 * @spec openspec/specs/team-folder-auto-confirm/spec.md#requirement-an-unlocked-confirmers-browser-confirms-without-a-click
	 */
	public function reconcile(string $teamFolderId, string $userId): array;

	/**
	 * Remove a membership row — revokes the derived shares of every user
	 * that is no longer covered by any remaining membership. An owner or
	 * manager removing someone else is refused, before anything changes,
	 * when a share row it would revoke is one the pending share quarantine
	 * scan cannot judge; a member leaving the folder themselves is never
	 * refused (TeamFolderShareService::releaseForMember()).
	 *
	 * @param string $teamFolderId The TeamFolder UUID
	 * @param string $membershipId The membership row UUID
	 * @param string $userId The caller (the owner or a manager)
	 *
	 * @return int Number of derived shares revoked
	 *
	 * @throws InvalidArgumentException On not found / not authorized
	 * @throws ShareScanPendingException When a row it would revoke waits for the share quarantine scan
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.2
	 * @spec openspec/specs/user-sharing/spec.md#requirement-runtime-paths-follow-only-judged-share-rows
	 */
	public function removeMember(string $teamFolderId, string $membershipId, string $userId): int;

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
	 * @throws OwnerOnlyException When a manager changes a manager or a membership that covers a manager
	 *
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-team-folder-membership-carries-a-read-write-or-manage-grade
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-grade-changes-and-non-owner-writes-are-audited
	 * @spec openspec/changes/archive/2026-10-04-sharing-use-only-and-expiring-shares/tasks.md#task-2.2
	 */
	public function setMemberGrade(
		string $teamFolderId,
		string $memberId,
		string $grade,
		string $ownerId,
		?ShareRestriction $restriction = null,
	): TeamFolderMember;

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
	public function shareFolder(string $folderId, string $userId): TeamFolder;

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
	 * @throws ShareScanPendingException When a share row of the folder waits for the share quarantine scan
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.4
	 * @spec openspec/specs/user-sharing/spec.md#requirement-runtime-paths-follow-only-judged-share-rows
	 */
	public function unshareFolder(string $teamFolderId, string $userId): int;
}//end interface
