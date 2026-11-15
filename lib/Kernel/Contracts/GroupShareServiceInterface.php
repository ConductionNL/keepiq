<?php

/**
 * Contract of GroupShareService
 *
 * The public surface of GroupShareService that other components call, so they depend
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
use OCA\Keepiq\Db\GroupShare;
use OCA\Keepiq\Exception\ShareScanPendingException;
use OCA\Keepiq\Service\ShareRestriction;

/**
 * Contract of GroupShareService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface GroupShareServiceInterface {

	/**
	 * Create a group share — returns the per-member fan-out the browser
	 * needs (each entry has the recipient's user ID and PEM certificate so
	 * the browser can RSA-encrypt the source secret for that member). The
	 * owner and members without an active EncryptionSuite are filtered out
	 * server-side so the browser doesn't have to know who is "eligible".
	 *
	 * @param string $secretId The source Secret ID
	 * @param string $groupId The Nextcloud group ID
	 * @param string $userId The initiator (must be owner or delegate)
	 * @param ShareRestriction|null $restriction Use-only and end date for every member
	 *
	 * `skipped` counts the members (owner excluded) left out for want of an
	 * active EncryptionSuite, so the sharer can be told who did not get it.
	 *
	 * @return array{groupShare:GroupShare,members:array<int,array{userId:string,certificate:string}>,skipped:int}
	 *
	 * @throws InvalidArgumentException On unauthorized / missing secret / empty group
	 * @throws ShareScanPendingException When a restriction change reaches a row the pending quarantine scan cannot judge
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#4.2
	 * @spec openspec/specs/sharing-group/spec.md#requirement-share-with-a-group
	 * @spec openspec/changes/archive/2026-10-04-sharing-use-only-and-expiring-shares/tasks.md#task-2.1
	 */
	public function createGroupShare(
		string $secretId,
		string $groupId,
		string $userId,
		?ShareRestriction $restriction = null,
	): array;

	/**
	 * List the group shares for a given source secret — only visible to
	 * the owner or an active delegate.
	 *
	 * @param string $secretId The source Secret ID
	 * @param string $userId The requesting user
	 *
	 * @return GroupShare[]
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#4.1
	 */
	public function getGroupSharesForSecret(string $secretId, string $userId): array;

	/**
	 * Handle a member leaving a group — revoke only the ShareTargets that
	 * were fanned out from a GroupShare for that group; direct shares to
	 * the same user are NOT touched.
	 *
	 * @param string $userId The departing user
	 * @param string $groupId The group they left
	 *
	 * @return int Number of ShareTargets revoked
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#4.6
	 */
	public function handleMemberLeave(string $userId, string $groupId): int;

	/**
	 * Handle a new user being added to a group — notify every secret
	 * owner whose secret is shared with that group so they can approve
	 * the new member. A new member who already holds a live copy has
	 * nothing to approve: the owner is told when the group share lets that
	 * copy save for everyone, unless the member already saves for everyone
	 * other than through a group share: as the owner or a delegate, or
	 * through a team-folder write grade, even one that comes through this
	 * group. The owner is not notified otherwise. No group share counts as
	 * already granting it, not even a share with a group the member joined in
	 * the same directory sync or through a nested group.
	 *
	 * @param string $userId The user newly added to the group
	 * @param string $groupId The group that gained the member
	 *
	 * @return int Number of notifications dispatched
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#4.4
	 * @spec openspec/specs/sharing-group/spec.md#requirement-a-group-member-saves-for-everyone-it-is-shared-with
	 */
	public function handleNewGroupMember(string $userId, string $groupId): int;

	/**
	 * Revoke a group share: revoke every member share fanned out from it
	 * through the share revocation path (which deletes the member's Secret
	 * copy, not only the ShareTarget row), then delete the GroupShare row.
	 *
	 * @param string $groupShareId The GroupShare row ID
	 * @param string $userId The Nextcloud user requesting the revoke
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException On unauthorized / not found
	 * @throws ShareScanPendingException When the pending quarantine scan cannot judge one of its rows
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#4.3
	 * @spec openspec/specs/sharing-group/spec.md#requirement-share-with-a-group
	 */
	public function revokeGroupShare(string $groupShareId, string $userId): void;
}//end interface
