<?php

/**
 * Keepiq Folder Cascade Service
 *
 * The mechanics a folder delete cascades into: the per-subfolder resolution
 * actions (delete / move / keep) and the removal of a whole folder subtree's
 * rows. It knows how to move or destroy the secrets of a subtree and how to
 * unwind the folder rows deepest-first; it does NOT know when a delete is
 * allowed — that protocol lives in FolderDeletionService.
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
use OCA\Keepiq\Db\SecretMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;

/**
 * Applies the per-subfolder resolution actions of a folder delete.
 */
class FolderCascadeService {
	/**
	 * Constructor for FolderCascadeService.
	 *
	 * @param FolderMapper $mapper The folder mapper
	 * @param SecretMapper $secretMapper The secret mapper
	 * @param SecretChildDataCleaner $childData The attachment/version cascade
	 * @param FolderNameGuard $nameGuard The sibling-name guard
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private FolderMapper $mapper,
		private SecretMapper $secretMapper,
		private SecretChildDataCleaner $childData,
		private FolderNameGuard $nameGuard,
	) {
	}//end __construct()

	/**
	 * Hard-delete every secret directly in a folder, after removing the
	 * child data (attachments, grants, version history) they own.
	 *
	 * @param string $folderId The folder whose direct secrets are deleted
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.2
	 */
	public function purgeSecretsIn(string $folderId): void {
		$this->childData->purgeForFolder(folderId: $folderId);
		$this->secretMapper->deleteByFolderId($folderId);
	}//end purgeSecretsIn()

	/**
	 * Re-home every secret directly in a folder to another folder (or to
	 * root when the target is null).
	 *
	 * @param string $folderId The folder losing its secrets
	 * @param string|null $targetId The destination folder ID, or null for root
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.2
	 */
	public function moveSecretsFrom(string $folderId, ?string $targetId): void {
		$this->secretMapper->updateFolderForSecrets($folderId, $targetId);
	}//end moveSecretsFrom()

	/**
	 * Apply a single per-subfolder resolution action (delete/move/keep).
	 *
	 * @param Folder $subfolder The direct subfolder
	 * @param string $action The action: delete, move, or keep
	 * @param string|null $deletedParentId The parent of the deleted folder (re-parent target)
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the action is unknown
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.2
	 */
	public function applySubfolderAction(Folder $subfolder, string $action, ?string $deletedParentId): void {
		switch ($action) {
			case 'delete':
				$subtreeIds = $this->mapper->getSubtreeIds($subfolder->getId());
				foreach ($subtreeIds as $folderId) {
					$this->purgeSecretsIn(folderId: (string)$folderId);
				}

				$this->deleteSubtreeFolders(folderIds: $subtreeIds);
				break;

			case 'move':
				$subtreeIds = $this->mapper->getSubtreeIds($subfolder->getId());
				foreach ($subtreeIds as $folderId) {
					$this->moveSecretsFrom(folderId: (string)$folderId, targetId: $deletedParentId);
				}

				$this->deleteSubtreeFolders(folderIds: $subtreeIds);
				break;

			case 'keep':
				$this->nameGuard->assertNameUnique(
					ownerType: $subfolder->getOwnerType(),
					ownerId: $subfolder->getOwnerId(),
					parentId: $deletedParentId,
					name: $subfolder->getName(),
					excludeId: $subfolder->getId()
				);

				$subfolder->setParentId($deletedParentId);
				$subfolder->setUpdatedAt(new DateTime());
				$this->mapper->update($subfolder);
				break;

			default:
				throw new InvalidArgumentException('Unknown subfolder action: ' . $action);
		}//end switch
	}//end applySubfolderAction()

	/**
	 * Delete a set of folder rows by ID (leaves deepest first to avoid
	 * orphaning, though parent_id has no FK constraint).
	 *
	 * @param string[] $folderIds The folder IDs to delete
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.2
	 */
	public function deleteSubtreeFolders(array $folderIds): void {
		// Reverse so descendants are removed before ancestors.
		foreach (array_reverse($folderIds) as $folderId) {
			try {
				$this->mapper->delete($this->mapper->findById($folderId));
			} catch (DoesNotExistException|MultipleObjectsReturnedException) {
				continue;
			}
		}
	}//end deleteSubtreeFolders()
}//end class
