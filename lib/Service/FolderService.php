<?php

/**
 * Doriath Folder Service
 *
 * Tree operations and cascade/resolution logic for vault folders.
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
use OCA\Doriath\Exception\ConflictException;
use OCA\Doriath\Exception\ForbiddenException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Business logic for folder lifecycle and tree manipulation.
 */
class FolderService
{
    /**
     * Constructor for FolderService.
     *
     * @param FolderMapper    $mapper       The folder mapper
     * @param SecretMapper    $secretMapper The secret mapper (cascade operations)
     * @param LoggerInterface $logger       The logger
     *
     * @return void
     */
    public function __construct(
        private FolderMapper $mapper,
        private SecretMapper $secretMapper,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * List the folders owned by a user.
     *
     * @param string $userId The user UID
     *
     * @return Folder[]
     */
    public function list(string $userId): array
    {
        return $this->mapper->findByOwner('user', $userId);
    }//end list()

    /**
     * Load a folder, asserting the caller owns it.
     *
     * @param string $id     The folder ID
     * @param string $userId The user UID
     *
     * @return Folder
     *
     * @throws ForbiddenException When the folder belongs to another user
     */
    public function getOwned(string $id, string $userId): Folder
    {
        $folder = $this->mapper->findById(id: $id);
        $this->assertOwnership(folder: $folder, userId: $userId);
        return $folder;
    }//end getOwned()

    /**
     * Create a folder for a user.
     *
     * @param string      $name     The folder name (no slashes)
     * @param string|null $parentId The parent folder ID (null for root)
     * @param string      $userId   The user UID
     *
     * @return Folder
     *
     * @throws InvalidArgumentException When the name contains a slash
     * @throws ForbiddenException       When the parent is not owned by the user
     */
    public function create(string $name, ?string $parentId, string $userId): Folder
    {
        $this->assertValidName(name: $name);

        if ($parentId === '') {
            $parentId = null;
        }

        if ($parentId !== null) {
            // Ownership of the parent is required.
            $this->getOwned(id: $parentId, userId: $userId);
        }

        $folder = new Folder();
        $folder->setId(Uuid::uuid4()->toString());
        $folder->setName($name);
        $folder->setParentId($parentId);
        $folder->setOwnerType('user');
        $folder->setOwnerId($userId);
        $folder->setCreatedAt(new DateTime());

        $this->mapper->insert($folder);
        return $folder;
    }//end create()

    /**
     * Rename a folder.
     *
     * @param string $id     The folder ID
     * @param string $name   The new name
     * @param string $userId The user UID
     *
     * @return Folder
     *
     * @throws InvalidArgumentException When the name contains a slash
     * @throws ForbiddenException       When the folder is not owned by the user
     */
    public function rename(string $id, string $name, string $userId): Folder
    {
        $this->assertValidName(name: $name);
        $folder = $this->getOwned(id: $id, userId: $userId);

        $folder->setName($name);
        $folder->setUpdatedAt(new DateTime());
        $this->mapper->update($folder);

        return $folder;
    }//end rename()

    /**
     * Move a folder to a new parent (or root).
     *
     * @param string      $id          The folder ID
     * @param string|null $newParentId The destination parent ID (null = root)
     * @param string      $userId      The user UID
     *
     * @return Folder
     *
     * @throws ForbiddenException       When folder or destination is not owned
     * @throws InvalidArgumentException When the move would create a cycle
     */
    public function move(string $id, ?string $newParentId, string $userId): Folder
    {
        $folder = $this->getOwned(id: $id, userId: $userId);

        if ($newParentId === '') {
            $newParentId = null;
        }

        if ($newParentId !== null) {
            $this->getOwned(id: $newParentId, userId: $userId);

            // Reject moving a folder into itself or one of its descendants.
            if (in_array($newParentId, $this->mapper->getSubtreeIds(folderId: $id), true) === true) {
                throw new InvalidArgumentException('Cannot move a folder into its own subtree');
            }
        }

        $folder->setParentId($newParentId);
        $folder->setUpdatedAt(new DateTime());
        $this->mapper->update($folder);

        return $folder;
    }//end move()

    /**
     * Build the children summary used by the resolution dialog.
     *
     * @param string $id     The folder ID
     * @param string $userId The user UID
     *
     * @return array{directSecretCount:int,subfolders:array<int,array<string,mixed>>}
     *
     * @throws ForbiddenException When the folder is not owned by the user
     */
    public function getChildren(string $id, string $userId): array
    {
        $this->getOwned(id: $id, userId: $userId);

        $subfolders = [];
        foreach ($this->mapper->findChildren(parentId: $id) as $child) {
            $subtreeIds   = $this->mapper->getSubtreeIds(folderId: $child->getId());
            $subfolders[] = [
                'id'             => $child->getId(),
                'name'           => $child->getName(),
                'secretCount'    => $this->secretMapper->countByFolderIds($subtreeIds),
                'subfolderCount' => (count($subtreeIds) - 1),
            ];
        }

        return [
            'directSecretCount' => $this->secretMapper->countByFolder($id),
            'subfolders'        => $subfolders,
        ];
    }//end getChildren()

    /**
     * Delete a folder, applying cascade/resolution rules.
     *
     * @param string                    $id         The folder ID
     * @param string|null               $cascade    The cascade mode (delete|move) or null
     * @param array<string,string>|null $resolution Per-subfolder action map
     * @param string                    $userId     The user UID
     *
     * @return void
     *
     * @throws ForbiddenException When the folder is not owned by the user
     * @throws ConflictException  When the folder is non-empty and no plan is given
     * @throws InvalidArgumentException On an incomplete resolution map
     */
    public function delete(string $id, ?string $cascade, ?array $resolution, string $userId): void
    {
        $folder   = $this->getOwned(id: $id, userId: $userId);
        $parentId = $folder->getParentId();

        $children      = $this->mapper->findChildren(parentId: $id);
        $directSecrets = $this->secretMapper->countByFolder($id);

        // Empty folder — straight delete.
        if (empty($children) === true && $directSecrets === 0) {
            $this->mapper->delete($folder);
            return;
        }

        // Folder has subfolders — a resolution body is mandatory.
        if (empty($children) === false) {
            $this->deleteWithSubfolders(
                folder: $folder,
                children: $children,
                parentId: $parentId,
                cascade: $cascade,
                resolution: $resolution
            );
            return;
        }

        // Non-empty without subfolders — cascade param required.
        $this->deleteLeafWithSecrets(folder: $folder, parentId: $parentId, cascade: $cascade);
    }//end delete()

    /**
     * Handle deletion of a leaf folder that holds secrets.
     *
     * @param Folder      $folder   The folder
     * @param string|null $parentId The folder's parent
     * @param string|null $cascade  The cascade mode
     *
     * @return void
     *
     * @throws ConflictException When no cascade mode is supplied
     */
    private function deleteLeafWithSecrets(Folder $folder, ?string $parentId, ?string $cascade): void
    {
        if ($cascade === 'delete') {
            $this->secretMapper->deleteByFolderIds([$folder->getId()]);
            $this->mapper->delete($folder);
            return;
        }

        if ($cascade === 'move') {
            $this->secretMapper->moveSecretsToFolder([$folder->getId()], $parentId);
            $this->mapper->delete($folder);
            return;
        }

        throw new ConflictException(message: 'Folder is not empty');
    }//end deleteLeafWithSecrets()

    /**
     * Handle deletion of a folder that contains subfolders via a resolution plan.
     *
     * @param Folder                    $folder     The folder being deleted
     * @param Folder[]                  $children   The direct subfolders
     * @param string|null               $parentId   The folder's parent
     * @param string|null               $cascade    The (ignored here) cascade shorthand
     * @param array<string,string>|null $resolution The per-subfolder action map
     *
     * @return void
     *
     * @throws ConflictException        When no resolution body is supplied
     * @throws InvalidArgumentException When the map is incomplete or invalid
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private function deleteWithSubfolders(
        Folder $folder,
        array $children,
        ?string $parentId,
        ?string $cascade,
        ?array $resolution,
    ): void {
        if ($resolution === null || empty($resolution) === true) {
            throw new ConflictException(message: 'Folder contains subfolders -- resolution required');
        }

        // Every direct subfolder must be accounted for.
        foreach ($children as $child) {
            if (array_key_exists($child->getId(), $resolution) === false) {
                throw new InvalidArgumentException('Resolution is missing an entry for subfolder '.$child->getId());
            }
        }

        // First handle the folder's own direct secrets.
        $directAction = $resolution['directSecrets'] ?? 'delete';
        if ($directAction === 'move') {
            $this->secretMapper->moveSecretsToFolder([$folder->getId()], $parentId);
        }

        if ($directAction !== 'move') {
            $this->secretMapper->deleteByFolderIds([$folder->getId()]);
        }

        // Process each subfolder depth-first.
        foreach ($children as $child) {
            $action = $resolution[$child->getId()];
            $this->applySubfolderAction(child: $child, action: $action, parentId: $parentId);
        }

        $this->mapper->delete($folder);
    }//end deleteWithSubfolders()

    /**
     * Apply a single subfolder resolution action.
     *
     * @param Folder      $child    The subfolder
     * @param string      $action   delete|move|keep
     * @param string|null $parentId The deleted folder's parent (destination for move/keep)
     *
     * @return void
     *
     * @throws InvalidArgumentException On an unknown action
     */
    private function applySubfolderAction(Folder $child, string $action, ?string $parentId): void
    {
        $subtreeIds = $this->mapper->getSubtreeIds(folderId: $child->getId());

        switch ($action) {
            case 'delete':
                $this->secretMapper->deleteByFolderIds($subtreeIds);
                $this->deleteFolderIds(folderIds: $subtreeIds);
                break;

            case 'move':
                $this->secretMapper->moveSecretsToFolder($subtreeIds, $parentId);
                $this->deleteFolderIds(folderIds: $subtreeIds);
                break;

            case 'keep':
                $child->setParentId($parentId);
                $child->setUpdatedAt(new DateTime());
                $this->mapper->update($child);
                break;

            default:
                throw new InvalidArgumentException('Unknown subfolder action: '.$action);
        }
    }//end applySubfolderAction()

    /**
     * Delete a set of folders by ID (deepest first).
     *
     * @param string[] $folderIds The folder IDs to delete
     *
     * @return void
     */
    private function deleteFolderIds(array $folderIds): void
    {
        // Delete deepest-first so no FK-style orphan windows appear.
        foreach (array_reverse($folderIds) as $folderId) {
            try {
                $this->mapper->delete($this->mapper->findById($folderId));
            } catch (\OCP\AppFramework\Db\DoesNotExistException) {
                // Already gone — ignore.
                continue;
            }
        }
    }//end deleteFolderIds()

    /**
     * Get the derived path of a folder.
     *
     * @param string $id The folder ID
     *
     * @return string
     */
    public function getFolderPath(string $id): string
    {
        return $this->mapper->getPath($id);
    }//end getFolderPath()

    /**
     * Assert a folder name is a single path segment.
     *
     * @param string $name The folder name
     *
     * @return void
     *
     * @throws InvalidArgumentException When the name contains a slash or is empty
     */
    private function assertValidName(string $name): void
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Folder name cannot be empty');
        }

        if (str_contains($name, '/') === true || str_contains($name, '\\') === true) {
            throw new InvalidArgumentException('Folder names cannot contain slashes');
        }
    }//end assertValidName()

    /**
     * Assert the user owns the folder.
     *
     * @param Folder $folder The folder
     * @param string $userId The user UID
     *
     * @return void
     *
     * @throws ForbiddenException When ownership does not match
     */
    private function assertOwnership(Folder $folder, string $userId): void
    {
        if ($folder->getOwnerType() !== 'user' || $folder->getOwnerId() !== $userId) {
            throw new ForbiddenException(message: 'Access denied: folder belongs to another user');
        }
    }//end assertOwnership()
}//end class
