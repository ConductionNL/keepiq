<?php

/**
 * Doriath Folder Service
 *
 * Business logic for Folder lifecycle: create, rename, move, delete.
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

use DateTime;
use InvalidArgumentException;
use OCA\Doriath\Db\Folder;
use OCA\Doriath\Db\FolderMapper;
use OCA\Doriath\Db\SecretMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Business logic for Folder lifecycle: create, rename, move, delete.
 */
class FolderService
{
    /**
     * Constructor for FolderService.
     *
     * @param FolderMapper    $folderMapper The folder mapper
     * @param SecretMapper    $secretMapper The secret mapper (for cascade operations)
     * @param LoggerInterface $logger       The logger interface
     *
     * @return void
     */
    public function __construct(
        private FolderMapper $folderMapper,
        private SecretMapper $secretMapper,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Create a new folder.
     *
     * The folder name must not contain slashes. If a parent ID is supplied the
     * parent must exist and belong to the same owner.
     *
     * @param string      $name      The folder name
     * @param string|null $parentId  The parent folder ID, or null for a root folder
     * @param string      $ownerType The owner type
     * @param string      $ownerId   The owner ID
     *
     * @return Folder
     *
     * @throws InvalidArgumentException When the name contains slashes or the parent is invalid
     * @throws DoesNotExistException    When the parent folder does not exist
     */
    public function create(string $name, ?string $parentId, string $ownerType, string $ownerId): Folder
    {
        if (str_contains(haystack: $name, needle: '/') === true) {
            throw new InvalidArgumentException('Folder name must not contain slashes');
        }

        if ($parentId !== null) {
            $parent = $this->folderMapper->findById(id: $parentId);
            if ($parent->getOwnerId() !== $ownerId || $parent->getOwnerType() !== $ownerType) {
                throw new InvalidArgumentException('Parent folder does not belong to the same owner');
            }
        }

        $folder = new Folder();
        $folder->setId(Uuid::uuid4()->toString());
        $folder->setName($name);
        $folder->setParentId($parentId);
        $folder->setOwnerType($ownerType);
        $folder->setOwnerId($ownerId);
        $folder->setCreatedAt(new DateTime());
        $folder->setUpdatedAt(new DateTime());

        $this->folderMapper->insert($folder);

        $this->logger->info("Doriath: Folder '{$name}' created for {$ownerType}/{$ownerId}");

        return $folder;
    }//end create()

    /**
     * Rename a folder.
     *
     * Only the owner may rename their folder. The new name must not contain slashes.
     *
     * @param string $id     The folder ID
     * @param string $name   The new folder name
     * @param string $userId The Nextcloud user ID performing the rename
     *
     * @return Folder
     *
     * @throws InvalidArgumentException When the name contains slashes or the user is not the owner
     * @throws DoesNotExistException    When the folder does not exist
     */
    public function rename(string $id, string $name, string $userId): Folder
    {
        if (str_contains(haystack: $name, needle: '/') === true) {
            throw new InvalidArgumentException('Folder name must not contain slashes');
        }

        $folder = $this->validateOwnership(id: $id, userId: $userId);

        $folder->setName($name);
        $folder->setUpdatedAt(new DateTime());

        $this->folderMapper->update($folder);

        $this->logger->info("Doriath: Folder {$id} renamed to '{$name}' by {$userId}");

        return $folder;
    }//end rename()

    /**
     * Move a folder to a new parent (or to the root level).
     *
     * Only the owner may move their folder. The new parent (if supplied) must
     * exist and belong to the same owner.
     *
     * @param string      $id          The folder ID
     * @param string|null $newParentId The new parent folder ID, or null to move to root
     * @param string      $userId      The Nextcloud user ID performing the move
     *
     * @return Folder
     *
     * @throws InvalidArgumentException When the new parent is invalid or the user is not the owner
     * @throws DoesNotExistException    When the folder or new parent does not exist
     */
    public function move(string $id, ?string $newParentId, string $userId): Folder
    {
        $folder = $this->validateOwnership(id: $id, userId: $userId);

        if ($newParentId !== null) {
            $newParent = $this->folderMapper->findById(id: $newParentId);
            if ($newParent->getOwnerId() !== $userId) {
                throw new InvalidArgumentException('New parent folder does not belong to the same owner');
            }
        }

        $folder->setParentId($newParentId);
        $folder->setUpdatedAt(new DateTime());

        $this->folderMapper->update($folder);

        $this->logger->info("Doriath: Folder {$id} moved to parent '{$newParentId}' by {$userId}");

        return $folder;
    }//end move()

    /**
     * Delete a folder with configurable cascade behaviour.
     *
     * Deletion logic depends on whether the folder contains secrets or subfolders:
     * - Empty folder: deleted directly.
     * - Has secrets but no subfolders: requires $cascade ('delete' or 'move').
     * - Has subfolders: requires $resolution array mapping subfolder IDs to actions
     *   ('delete', 'move', or 'keep').
     *
     * @param string      $id         The folder ID
     * @param string|null $cascade    How to handle direct secrets ('delete' or 'move')
     * @param array|null  $resolution Map of subfolder IDs to actions for subfolders
     * @param string      $userId     The Nextcloud user ID performing the deletion
     *
     * @return void
     *
     * @throws InvalidArgumentException When cascade/resolution params are missing or invalid
     * @throws DoesNotExistException    When the folder does not exist
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function delete(string $id, ?string $cascade, ?array $resolution, string $userId): void
    {
        $folder = $this->validateOwnership(id: $id, userId: $userId);

        $directSecretCount = $this->folderMapper->countSecrets(folderId: $id);
        $subfolders        = $this->folderMapper->findChildren(parentId: $id);
        $subfolderCount    = count($subfolders);

        if ($directSecretCount === 0 && $subfolderCount === 0) {
            // Empty folder — delete directly.
            $this->folderMapper->delete($folder);
            $this->logger->info("Doriath: Empty folder {$id} deleted by {$userId}");
            return;
        }

        if ($subfolderCount === 0) {
            // Has secrets but no subfolders — handle via cascade param.
            if ($cascade === null) {
                throw new InvalidArgumentException(
                    'Folder contains secrets: provide cascade="delete" or cascade="move"'
                );
            }

            $this->applySecretCascade(
                cascade: $cascade,
                folderId: $id,
                parentId: $folder->getParentId()
            );

            $this->folderMapper->delete($folder);
            $this->logger->info("Doriath: Folder {$id} deleted with cascade='{$cascade}' by {$userId}");
            return;
        }

        // Has subfolders — require resolution.
        if ($resolution === null) {
            throw new InvalidArgumentException(
                'Folder contains subfolders: provide a resolution map'
            );
        }

        foreach ($subfolders as $subfolder) {
            $subId  = $subfolder->getId();
            $action = $resolution[$subId] ?? null;

            if ($action === null) {
                throw new InvalidArgumentException("No resolution action provided for subfolder {$subId}");
            }

            $this->applySubfolderAction(
                action: $action,
                subfolder: $subfolder,
                parentId: $folder->getParentId()
            );
        }//end foreach

        // Handle direct secrets of the folder being deleted via resolution's cascade key.
        if ($directSecretCount > 0) {
            $directCascade = $resolution['_secrets'] ?? $cascade;
            if ($directCascade === null) {
                throw new InvalidArgumentException(
                    'Folder has direct secrets: provide resolution["_secrets"]="delete" or "move"'
                );
            }

            $this->applySecretCascade(
                cascade: $directCascade,
                folderId: $id,
                parentId: $folder->getParentId()
            );
        }

        $this->folderMapper->delete($folder);
        $this->logger->info("Doriath: Folder {$id} deleted with resolution by {$userId}");
    }//end delete()

    /**
     * Get the direct children summary for a folder.
     *
     * Returns the direct secret count and an array of subfolder summaries, each
     * containing id, name, secretCount (recursive) and subfolderCount.
     *
     * @param string $id     The folder ID
     * @param string $userId The Nextcloud user ID
     *
     * @return array{directSecretCount: int, subfolders: array<int,array<string,mixed>>}
     *
     * @throws InvalidArgumentException When the user does not own the folder
     * @throws DoesNotExistException    When the folder does not exist
     */
    public function getChildren(string $id, string $userId): array
    {
        $this->validateOwnership(id: $id, userId: $userId);

        $directSecretCount = $this->folderMapper->countSecrets(folderId: $id);
        $subfolders        = $this->folderMapper->findChildren(parentId: $id);

        $subfolderSummaries = [];
        foreach ($subfolders as $subfolder) {
            $subfolderSummaries[] = [
                'id'             => $subfolder->getId(),
                'name'           => $subfolder->getName(),
                'secretCount'    => $this->folderMapper->countSecretsRecursive(folderId: $subfolder->getId()),
                'subfolderCount' => count($this->folderMapper->findChildren(parentId: $subfolder->getId())),
            ];
        }//end foreach

        return [
            'directSecretCount' => $directSecretCount,
            'subfolders'        => $subfolderSummaries,
        ];
    }//end getChildren()

    /**
     * Build a slash-separated path for a folder by traversing parent links.
     *
     * Returns a string like "Work/Projects/Active". Returns an empty string for
     * root folders.
     *
     * @param string $id The folder ID
     *
     * @return string
     *
     * @throws DoesNotExistException When a folder in the chain does not exist
     */
    public function getFolderPath(string $id): string
    {
        $parts     = [];
        $currentId = $id;

        while ($currentId !== null) {
            $folder = $this->folderMapper->findById(id: $currentId);
            array_unshift($parts, $folder->getName());
            $currentId = $folder->getParentId();
        }

        return implode(separator: '/', array: $parts);
    }//end getFolderPath()

    /**
     * Validate that the given user owns a folder.
     *
     * @param string $id     The folder ID
     * @param string $userId The Nextcloud user ID
     *
     * @return Folder
     *
     * @throws InvalidArgumentException When the user does not own the folder
     * @throws DoesNotExistException    When the folder does not exist
     */
    public function validateOwnership(string $id, string $userId): Folder
    {
        $folder = $this->folderMapper->findById(id: $id);

        if ($folder->getOwnerType() !== 'user' || $folder->getOwnerId() !== $userId) {
            throw new InvalidArgumentException('You do not own this folder');
        }

        return $folder;
    }//end validateOwnership()

    /**
     * Apply cascade action to direct secrets in a folder.
     *
     * @param string      $cascade  The cascade action ('delete' or 'move')
     * @param string      $folderId The source folder ID
     * @param string|null $parentId The parent folder ID to move secrets to (null = unset folder)
     *
     * @return void
     *
     * @throws InvalidArgumentException When cascade value is unrecognised
     */
    private function applySecretCascade(string $cascade, string $folderId, ?string $parentId): void
    {
        if ($cascade === 'delete') {
            $this->secretMapper->deleteByFolderId(folderId: $folderId);
        } else if ($cascade === 'move') {
            $this->secretMapper->updateFolderForSecrets(
                oldFolderId: $folderId,
                newFolderId: $parentId
            );
        } else {
            throw new InvalidArgumentException("Unknown cascade action '{$cascade}': use 'delete' or 'move'");
        }
    }//end applySecretCascade()

    /**
     * Apply a resolution action to a subfolder during parent deletion.
     *
     * @param string      $action    The action ('delete', 'move', or 'keep')
     * @param Folder      $subfolder The subfolder entity
     * @param string|null $parentId  The parent folder ID of the folder being deleted
     *
     * @return void
     *
     * @throws InvalidArgumentException When the action is unrecognised
     */
    private function applySubfolderAction(string $action, Folder $subfolder, ?string $parentId): void
    {
        $subId = $subfolder->getId();

        if ($action === 'delete') {
            // Delete all secrets in the subtree, then delete all folders in the subtree.
            $subtreeIds = $this->folderMapper->getSubtreeIds(folderId: $subId);
            foreach ($subtreeIds as $treeFolderId) {
                $this->secretMapper->deleteByFolderId(folderId: $treeFolderId);
            }//end foreach

            foreach (array_reverse($subtreeIds) as $treeFolderId) {
                try {
                    $treeFolder = $this->folderMapper->findById(id: $treeFolderId);
                    $this->folderMapper->delete($treeFolder);
                } catch (DoesNotExistException) {
                    // Already deleted.
                }
            }//end foreach
        } else if ($action === 'move') {
            // Move all secrets to the parent of the deleted folder, then delete the subtree.
            $subtreeIds = $this->folderMapper->getSubtreeIds(folderId: $subId);
            foreach ($subtreeIds as $treeFolderId) {
                $this->secretMapper->updateFolderForSecrets(
                    oldFolderId: $treeFolderId,
                    newFolderId: $parentId
                );
            }//end foreach

            foreach (array_reverse($subtreeIds) as $treeFolderId) {
                try {
                    $treeFolder = $this->folderMapper->findById(id: $treeFolderId);
                    $this->folderMapper->delete($treeFolder);
                } catch (DoesNotExistException) {
                    // Already deleted.
                }
            }//end foreach
        } else if ($action === 'keep') {
            // Re-parent the subfolder to the deleted folder's parent.
            $subfolder->setParentId($parentId);
            $subfolder->setUpdatedAt(new DateTime());
            $this->folderMapper->update($subfolder);
        } else {
            throw new InvalidArgumentException(
                "Unknown resolution action '{$action}' for subfolder {$subId}: use 'delete', 'move', or 'keep'"
            );
        }//end if
    }//end applySubfolderAction()
}//end class
