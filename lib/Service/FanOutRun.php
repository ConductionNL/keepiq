<?php

/**
 * Keepiq Fan Out Run
 *
 * One fan-out of a team folder to its members: the folder, the secret ids
 * inside its subtree and the owner who runs it. Built by
 * TeamFolderShareService::registerFanOutShares() and never passed outside it.
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

use DateTime;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\TeamFolder;
use Ramsey\Uuid\Uuid;

/**
 * The state one team-folder fan-out shares between its steps.
 *
 * @spec openspec/changes/team-folder-sharing/tasks.md#2.4
 */
final class FanOutRun {

	/**
	 * Capture the fan-out.
	 *
	 * @param TeamFolder         $teamFolder       The team folder being fanned out
	 * @param array<string,bool> $subtreeSecretIds The secret ids inside the folder subtree
	 * @param string             $userId           The caller (the folder owner)
	 *
	 * @return void
	 */
	public function __construct(
		public readonly TeamFolder $teamFolder,
		public readonly array $subtreeSecretIds,
		public readonly string $userId,
	) {
	}//end __construct()

	/**
	 * Whether the row may carry this folder's provenance: its secret lies
	 * inside the folder's subtree, and it is no copy for the folder owner.
	 *
	 * @param FanOutShareRow $share The submitted row
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.4
	 */
	public function covers(FanOutShareRow $share): bool {
		return isset($this->subtreeSecretIds[$share->sourceSecretId]) === true
			&& $share->targetUserId !== $this->teamFolder->getOwnerId();
	}//end covers()

	/**
	 * The share row linking the source secret to the recipient's copy, made by this fan-out now.
	 *
	 * @param FanOutShareRow $share  The submitted row
	 * @param string         $copyId The recipient's copy
	 *
	 * @return ShareTarget
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.4
	 */
	public function shareTarget(FanOutShareRow $share, string $copyId): ShareTarget {
		$entity = new ShareTarget();
		$entity->setId(Uuid::uuid4()->toString());
		$entity->setSourceSecretId($share->sourceSecretId);
		$entity->setTargetUserId($share->targetUserId);
		$entity->setSecretId($copyId);
		$entity->setTeamFolderId($this->teamFolder->getId());
		$entity->setCreatedBy($this->userId);
		$entity->setCreatedAt(new DateTime());
		return $entity;
	}//end shareTarget()
}//end class
