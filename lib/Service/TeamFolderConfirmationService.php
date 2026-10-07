<?php

/**
 * Keepiq Team Folder Confirmation Service
 *
 * Automatic confirmation of new team folder members
 * (admin-auto-confirm-members). With the admin switch
 * `team_folder_auto_confirm` on, the browser of any authorised confirmer, the
 * folder owner or a member with an effective `write` grade, hands a new member
 * their copies without a click.
 *
 * The server never decrypts. It tells a confirmer which pairs are missing and
 * which certificates to encrypt to, and it accepts a non-owner's row only when
 * the row is safe: the caller holds `write` on that secret, the target is a
 * covered, enabled member with an active suite who still misses the copy, and
 * the caller's own copy is not older than the source's last key change.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Service;

use InvalidArgumentException;
use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Db\TeamFolder;
use OCA\Keepiq\Db\TeamFolderMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;

/**
 * Serves pending confirmations and accepts a confirmer's rows.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The confirmation rules join
 *   the team folder, membership, grade, share and secret sides in one place.
 */
class TeamFolderConfirmationService {
	/**
	 * The admin policy switch (admin-auto-confirm-members D1).
	 */
	public const SWITCH_KEY = 'team_folder_auto_confirm';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config (policy switch)
	 * @param TeamFolderMapper $mapper The team folder mapper
	 * @param TeamFolderService $teamFolders The owner fan-out path
	 * @param TeamFolderMembershipResolver $memberships Coverage, recipients, subtree
	 * @param TeamFolderShareService $shares Missing pairs and row registration
	 * @param ConfirmerCopyResolver $copies The confirmer's own current write copy
	 * @param NotificationService $notificationService The owner notice
	 * @param TeamFolderAuditor $audit The confirmation audit event
	 * @param TeamFolderQueryService $queries Reads the caller's grade on the folder
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private TeamFolderMapper $mapper,
		private TeamFolderService $teamFolders,
		private TeamFolderMembershipResolver $memberships,
		private TeamFolderShareService $shares,
		private ConfirmerCopyResolver $copies,
		private NotificationService $notificationService,
		private TeamFolderAuditor $audit,
		private TeamFolderQueryService $queries,
	) {
	}//end __construct()

	/**
	 * Whether the administrator switched automatic confirmation on.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/team-folder-auto-confirm/spec.md#requirement-pending-confirmations-are-served-to-authorised-confirmers-only
	 */
	public function isEnabled(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, self::SWITCH_KEY, false);
	}//end isEnabled()

	/**
	 * The team folders where the user may confirm and members still wait.
	 *
	 * Owned folders list every missing pair. For a folder the user is a member
	 * of, only pairs whose source the user holds with `write` grade and a
	 * current copy of are listed, each with the id of that copy. Empty when
	 * the switch is off.
	 *
	 * @param string $userId The session user
	 *
	 * @return array<int,array{teamFolderId:string,role:string,missing:array<int,array<string,string>>,recipients:array<int,array{userId:string,certificate:string}>}>
	 *
	 * @spec openspec/specs/team-folder-auto-confirm/spec.md#requirement-pending-confirmations-are-served-to-authorised-confirmers-only
	 */
	public function pendingConfirmations(string $userId): array {
		if ($this->isEnabled() === false) {
			return [];
		}

		$pending = [];
		foreach ($this->candidateFolders(userId: $userId) as $teamFolder) {
			$entry = $this->pendingForFolder(teamFolder: $teamFolder, userId: $userId);
			if ($entry !== null) {
				$pending[] = $entry;
			}
		}

		return $pending;
	}//end pendingConfirmations()

	/**
	 * Register fan-out rows from the owner, a manager or a `write`-grade confirmer.
	 *
	 * The owner and a manager (effective grade `manage`) take the plain
	 * fan-out, which checks the manage grade and the subtree itself
	 * (sharing-team-folder-manager-role D2). Anyone else needs the switch on,
	 * and every row is checked before anything is stored; rows that fail a
	 * check are skipped, so the pair stays pending.
	 *
	 * @param string $teamFolderId The team folder
	 * @param array<int,array<string,mixed>> $rows The browser-encrypted rows
	 * @param string $userId The caller
	 *
	 * @return array{created:int,rows:array<int,array{sourceSecretId:string,targetUserId:string,recipientSecretId:string}>}
	 *
	 * @throws InvalidArgumentException When the folder is missing or the caller may not confirm
	 *
	 * @spec openspec/specs/team-folder-auto-confirm/spec.md#requirement-the-server-accepts-a-confirmers-row-only-when-it-is-safe
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-managers-keep-the-membership-current
	 */
	public function registerShares(string $teamFolderId, array $rows, string $userId): array {
		$teamFolder = $this->loadTeamFolder(teamFolderId: $teamFolderId);
		if ($teamFolder->getOwnerId() === $userId
			|| $this->queries->gradeOnTeamFolder(teamFolder: $teamFolder, userId: $userId) === 'manage'
		) {
			return $this->teamFolders->registerFanOutShares(teamFolderId: $teamFolderId, shares: $rows, userId: $userId);
		}

		if ($this->isEnabled() === false) {
			throw new InvalidArgumentException(message: 'Not authorized to manage this team folder');
		}

		$subtreeIds = $this->subtreeIds(teamFolder: $teamFolder);
		$eligible = $this->eligibleTargets(teamFolder: $teamFolder, confirmerId: $userId);

		$accepted = [];
		foreach ($rows as $row) {
			if ($this->rowIsSafe(row: $row, subtreeIds: $subtreeIds, eligible: $eligible, confirmerId: $userId) === true) {
				$accepted[] = $row;
			}
		}

		if ($accepted === []) {
			return ['created' => 0, 'rows' => []];
		}

		$result = $this->shares->registerFanOutShares(
			teamFolder: $teamFolder,
			shares: $accepted,
			subtreeSecretIds: $subtreeIds,
			userId: $userId
		);

		if ($result['created'] > 0) {
			$this->announce(teamFolder: $teamFolder, confirmerId: $userId, rows: $result['rows']);
		}

		return $result;
	}//end registerShares()

	/**
	 * Team folders the user owns or is covered by a membership row of.
	 *
	 * @param string $userId The session user
	 *
	 * @return array<string,TeamFolder> Keyed by team folder id
	 *
	 * @spec openspec/specs/team-folder-auto-confirm/spec.md#requirement-pending-confirmations-are-served-to-authorised-confirmers-only
	 */
	private function candidateFolders(string $userId): array {
		$folders = [];
		foreach ($this->mapper->findByOwner(ownerId: $userId) as $owned) {
			$folders[$owned->getId()] = $owned;
		}

		foreach ($this->memberships->membershipRowsForUser(userId: $userId) as $row) {
			$teamFolderId = (string)$row->getTeamFolderId();
			if (isset($folders[$teamFolderId]) === true) {
				continue;
			}

			try {
				$folders[$teamFolderId] = $this->mapper->findById(id: $teamFolderId);
			} catch (DoesNotExistException) {
				continue;
			}
		}

		return $folders;
	}//end candidateFolders()

	/**
	 * The pending entry of one folder for one confirmer, or null.
	 *
	 * @param TeamFolder $teamFolder The team folder
	 * @param string $userId The confirmer
	 *
	 * @return array{teamFolderId:string,role:string,missing:array<int,array<string,string>>,recipients:array<int,array{userId:string,certificate:string}>}|null
	 *
	 * @spec openspec/specs/team-folder-auto-confirm/spec.md#requirement-pending-confirmations-are-served-to-authorised-confirmers-only
	 */
	private function pendingForFolder(TeamFolder $teamFolder, string $userId): ?array {
		$isOwner = $teamFolder->getOwnerId() === $userId;
		$secrets = $this->memberships->subtreeSecretRefs(teamFolder: $teamFolder);
		$recipients = $this->memberships->eligibleRecipients(
			userIds: array_values(
				array_diff(
					$this->memberships->effectiveUsers(teamFolderId: $teamFolder->getId()),
					[$teamFolder->getOwnerId(), $userId]
				)
			)
		);

		$missing = [];
		$ownCopies = [];
		foreach ($this->shares->missingPairs(secrets: $secrets, recipients: $recipients) as $pair) {
			if ($isOwner === true) {
				$missing[] = $pair;
				continue;
			}

			$sourceId = $pair['secretId'];
			if (array_key_exists($sourceId, $ownCopies) === false) {
				$ownCopies[$sourceId] = $this->copies->currentWriteCopy(sourceId: $sourceId, confirmerId: $userId);
			}

			if ($ownCopies[$sourceId] !== null) {
				$missing[] = $pair + ['ownCopyId' => $ownCopies[$sourceId]];
			}
		}

		if ($missing === []) {
			return null;
		}

		$needed = array_flip(array_column($missing, 'userId'));
		$role = 'member';
		if ($isOwner === true) {
			$role = 'owner';
		}

		return [
			'teamFolderId' => $teamFolder->getId(),
			'role' => $role,
			'missing' => $missing,
			'recipients' => array_values(
				array_filter($recipients, static fn (array $recipient): bool => isset($needed[$recipient['userId']]))
			),
		];
	}//end pendingForFolder()

	/**
	 * Whether one row from a non-owner confirmer may be stored.
	 *
	 * @param array<string,mixed> $row The row
	 * @param array<string,bool> $subtreeIds The folder's subtree secret ids
	 * @param array<string,bool> $eligible The covered, enabled targets with a suite
	 * @param string $confirmerId The confirmer
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/team-folder-auto-confirm/spec.md#requirement-the-server-accepts-a-confirmers-row-only-when-it-is-safe
	 */
	private function rowIsSafe(array $row, array $subtreeIds, array $eligible, string $confirmerId): bool {
		$sourceId = (string)($row['sourceSecretId'] ?? '');
		$targetId = (string)($row['targetUserId'] ?? '');

		if (isset($subtreeIds[$sourceId]) === false || isset($eligible[$targetId]) === false) {
			return false;
		}

		return $this->copies->currentWriteCopy(sourceId: $sourceId, confirmerId: $confirmerId) !== null;
	}//end rowIsSafe()

	/**
	 * The users a confirmer may hand a copy to: covered by a membership row,
	 * enabled, with an active suite, never the owner or the confirmer.
	 *
	 * @param TeamFolder $teamFolder The team folder
	 * @param string $confirmerId The confirmer
	 *
	 * @return array<string,bool> Keyed by user id
	 *
	 * @spec openspec/specs/team-folder-auto-confirm/spec.md#requirement-the-server-accepts-a-confirmers-row-only-when-it-is-safe
	 */
	private function eligibleTargets(TeamFolder $teamFolder, string $confirmerId): array {
		$targets = [];
		$candidates = array_values(
			array_diff(
				$this->memberships->effectiveUsers(teamFolderId: $teamFolder->getId()),
				[$teamFolder->getOwnerId(), $confirmerId]
			)
		);
		foreach ($this->memberships->eligibleRecipients(userIds: $candidates) as $recipient) {
			$targets[$recipient['userId']] = true;
		}

		return $targets;
	}//end eligibleTargets()

	/**
	 * The subtree secret ids of a team folder.
	 *
	 * @param TeamFolder $teamFolder The team folder
	 *
	 * @return array<string,bool>
	 *
	 * @spec openspec/specs/team-folder-auto-confirm/spec.md#requirement-the-server-accepts-a-confirmers-row-only-when-it-is-safe
	 */
	private function subtreeIds(TeamFolder $teamFolder): array {
		$ids = [];
		foreach ($this->memberships->subtreeSecretRefs(teamFolder: $teamFolder) as $ref) {
			$ids[$ref['id']] = true;
		}

		return $ids;
	}//end subtreeIds()

	/**
	 * Tell the owner who confirmed whom, and audit with the confirmer as actor.
	 *
	 * @param TeamFolder $teamFolder The team folder
	 * @param string $confirmerId The confirmer
	 * @param array<int,array{sourceSecretId:string,targetUserId:string,recipientSecretId:string}> $rows The created rows
	 *
	 * @return void
	 *
	 * @spec openspec/specs/team-folder-auto-confirm/spec.md#requirement-the-server-accepts-a-confirmers-row-only-when-it-is-safe
	 */
	private function announce(TeamFolder $teamFolder, string $confirmerId, array $rows): void {
		$memberIds = array_values(array_unique(array_column($rows, 'targetUserId')));

		$this->notificationService->notify(
			subject: 'team_folder_member_confirmed',
			recipientId: $teamFolder->getOwnerId(),
			params: [
				'teamFolderId' => $teamFolder->getId(),
				'confirmedBy' => $confirmerId,
				'memberIds' => $memberIds,
			],
			objectType: 'team_folder',
			objectId: $teamFolder->getId(),
		);

		$this->audit->membersConfirmed(
			actorId: $confirmerId,
			teamFolderId: $teamFolder->getId(),
			confirmedCount: count($rows),
			memberCount: count($memberIds),
		);
	}//end announce()

	/**
	 * Load a team folder by id.
	 *
	 * @param string $teamFolderId The team folder id
	 *
	 * @return TeamFolder
	 *
	 * @throws InvalidArgumentException When it does not exist
	 *
	 * @spec openspec/specs/team-folder-auto-confirm/spec.md#requirement-the-server-accepts-a-confirmers-row-only-when-it-is-safe
	 */
	private function loadTeamFolder(string $teamFolderId): TeamFolder {
		try {
			return $this->mapper->findById(id: $teamFolderId);
		} catch (DoesNotExistException) {
			throw new InvalidArgumentException(message: 'Team folder not found');
		}
	}//end loadTeamFolder()
}//end class
