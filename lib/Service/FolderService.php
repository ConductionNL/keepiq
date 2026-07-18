<?php

/**
 * Doriath Folder Service
 *
 * Business logic for the per-owner folder tree: create, rename, move, the
 * children endpoint, and the three-mode deletion protocol (empty,
 * cascade=delete/move for leaf folders, and per-subfolder resolution for
 * folders that contain subfolders).
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
use OCA\Doriath\Event\Audit\AuditEvent;
use OCA\Doriath\Event\Audit\AuditEventTypes;
use OCA\Doriath\Exception\ConflictException;
use OCA\Doriath\Exception\DuplicateFolderNameException;
use OCA\Doriath\Exception\ForbiddenException;
use OCA\Doriath\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Business logic for the folder tree.
 */
class FolderService
{
    /**
     * Constructor for FolderService.
     *
     * @param FolderMapper           $mapper            The folder mapper
     * @param SecretMapper           $secretMapper      The secret mapper (for cascade operations)
     * @param LoggerInterface        $logger            The logger interface
     * @param IEventDispatcher|null  $eventDispatcher   The event dispatcher
     * @param AttachmentService|null $attachmentService The attachment service (delete cascade)
     *
     * @return void
     */
    public function __construct(
        private FolderMapper $mapper,
        private SecretMapper $secretMapper,
        private LoggerInterface $logger,
        private ?IEventDispatcher $eventDispatcher=null,
        private ?AttachmentService $attachmentService=null,
    ) {
    }//end __construct()

    /**
     * Attachments cascade for a folder's direct secrets
     * (encrypted-attachments §3.1): before a bulk secret delete, remove
     * each secret's attachments and any grants it holds as a copy.
     *
     * @param string $folderId The folder whose direct secrets are deleted
     *
     * @return void
     */
    private function cascadeAttachmentsForFolder(string $folderId): void
    {
        if ($this->attachmentService === null) {
            return;
        }

        foreach ($this->secretMapper->findByFolderId(folderId: $folderId) as $secret) {
            $this->attachmentService->deleteForSecret($secret->getId());
            $this->attachmentService->deleteGrantsForSecretCopy($secret->getId());
        }
    }//end cascadeAttachmentsForFolder()

    /**
     * Dispatch a typed audit event, fail-soft.
     *
     * @param AuditEvent $event The audit event
     *
     * @return void
     *
     * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3
     */
    private function dispatchAudit(AuditEvent $event): void
    {
        $this->eventDispatcher?->dispatchTyped($event);
    }//end dispatchAudit()

    /**
     * List the folders owned by a user.
     *
     * @param string $userId The Nextcloud user ID
     *
     * @return Folder[]
     */
    public function listForUser(string $userId): array
    {
        return $this->mapper->findByOwner('user', $userId);
    }//end listForUser()

    /**
     * Load a folder and verify the requester owns it.
     *
     * @param string $id     The folder ID
     * @param string $userId The requesting Nextcloud user ID
     *
     * @return Folder
     *
     * @throws NotFoundException When the folder does not exist
     * @throws ForbiddenException When the folder belongs to another user
     */
    public function getOwned(string $id, string $userId): Folder
    {
        try {
            $folder = $this->mapper->findById($id);
        } catch (DoesNotExistException | MultipleObjectsReturnedException) {
            throw new NotFoundException(message: 'Folder not found');
        }

        if ($folder->getOwnerType() !== 'user' || $folder->getOwnerId() !== $userId) {
            throw new ForbiddenException(message: 'Folder belongs to another user');
        }

        return $folder;
    }//end getOwned()

    /**
     * Create a folder.
     *
     * @param string      $name     The folder name (no slashes)
     * @param string|null $parentId The parent folder ID (null = root)
     * @param string      $userId   The owning Nextcloud user ID
     *
     * @return Folder
     *
     * @throws InvalidArgumentException When the name is invalid
     * @throws ForbiddenException When the parent is not owned
     * @throws DuplicateFolderNameException When a sibling folder already uses the name
     *
     * @spec exclude Rescued bugfix (PR #22) — folder-name uniqueness has no dedicated spec requirement yet.
     */
    public function create(string $name, ?string $parentId, string $userId): Folder
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Folder name is required');
        }

        if (str_contains($name, '/') === true) {
            throw new InvalidArgumentException('Folder names cannot contain slashes');
        }

        if ($parentId === '') {
            $parentId = null;
        }

        if ($parentId !== null) {
            // Verifies ownership; throws ForbiddenException if not owned.
            $this->getOwned(id: $parentId, userId: $userId);
        }

        $this->assertNameUnique(
            ownerType: 'user',
            ownerId: $userId,
            parentId: $parentId,
            name: $name
        );

        $now    = new DateTime();
        $folder = new Folder();
        $folder->setId(Uuid::uuid4()->toString());
        $folder->setName($name);
        $folder->setParentId($parentId);
        $folder->setOwnerType('user');
        $folder->setOwnerId($userId);
        $folder->setCreatedAt($now);
        $folder->setUpdatedAt($now);

        $this->mapper->insert($folder);
        $this->logger->info("Doriath: folder '{$name}' created by {$userId}");

        return $folder;
    }//end create()

    /**
     * Rename a folder.
     *
     * @param string $id     The folder ID
     * @param string $name   The new name
     * @param string $userId The requesting Nextcloud user ID
     *
     * @return Folder
     *
     * @throws InvalidArgumentException When the name is invalid
     * @throws ForbiddenException When not owned
     * @throws DuplicateFolderNameException When a sibling folder already uses the name
     *
     * @spec exclude Rescued bugfix (PR #22) — folder-name uniqueness has no dedicated spec requirement yet.
     */
    public function rename(string $id, string $name, string $userId): Folder
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Folder name is required');
        }

        if (str_contains($name, '/') === true) {
            throw new InvalidArgumentException('Folder names cannot contain slashes');
        }

        $folder = $this->getOwned(id: $id, userId: $userId);

        $this->assertNameUnique(
            ownerType: $folder->getOwnerType(),
            ownerId: $folder->getOwnerId(),
            parentId: $folder->getParentId(),
            name: $name,
            excludeId: $id
        );

        $folder->setName($name);
        $folder->setUpdatedAt(new DateTime());
        $this->mapper->update($folder);

        return $folder;
    }//end rename()

    /**
     * Move a folder to a different parent (or to root).
     *
     * @param string      $id          The folder ID
     * @param string|null $newParentId The target parent ID (null = root)
     * @param string      $userId      The requesting Nextcloud user ID
     *
     * @return Folder
     *
     * @throws InvalidArgumentException When the move would create a cycle
     * @throws ForbiddenException When the folder or target is not owned
     * @throws DuplicateFolderNameException When the target parent already contains the name
     *
     * @spec exclude Rescued bugfix (PR #22) — folder-name uniqueness has no dedicated spec requirement yet.
     */
    public function move(string $id, ?string $newParentId, string $userId): Folder
    {
        $folder = $this->getOwned(id: $id, userId: $userId);

        if ($newParentId === '') {
            $newParentId = null;
        }

        if ($newParentId !== null) {
            $this->getOwned(id: $newParentId, userId: $userId);

            if ($newParentId === $id || in_array($newParentId, $this->mapper->getSubtreeIds($id), true) === true) {
                throw new InvalidArgumentException('Cannot move a folder into itself or its own subtree');
            }
        }

        $this->assertNameUnique(
            ownerType: $folder->getOwnerType(),
            ownerId: $folder->getOwnerId(),
            parentId: $newParentId,
            name: $folder->getName(),
            excludeId: $id
        );

        $folder->setParentId($newParentId);
        $folder->setUpdatedAt(new DateTime());
        $this->mapper->update($folder);

        return $folder;
    }//end move()

    /**
     * Build the children payload for the resolution dialog: the folder's
     * direct secret count and its direct subfolders with recursive counts.
     *
     * @param string $id     The folder ID
     * @param string $userId The requesting Nextcloud user ID
     *
     * @return array<string,mixed>
     *
     * @throws NotFoundException When the folder does not exist
     * @throws ForbiddenException When the folder is not owned
     */
    public function getChildren(string $id, string $userId): array
    {
        $this->getOwned(id: $id, userId: $userId);

        $subfolders = [];
        foreach ($this->mapper->findChildren($id) as $child) {
            $subtreeIds   = $this->mapper->getSubtreeIds($child->getId());
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
     * Delete a folder, applying the appropriate protocol.
     *
     * @param string                   $id         The folder ID
     * @param string|null              $cascade    The shorthand cascade mode (delete/move) for leaf folders
     * @param array<string,mixed>|null $resolution The per-subfolder resolution body
     * @param string                   $userId     The requesting Nextcloud user ID
     *
     * @return void
     *
     * @throws NotFoundException When the folder does not exist
     * @throws ForbiddenException When not owned
     * @throws ConflictException When a non-empty folder is deleted without a plan
     * @throws InvalidArgumentException When the resolution body is incomplete
     *
     * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.2
     */
    public function delete(string $id, ?string $cascade, ?array $resolution, string $userId): void
    {
        $folder      = $this->getOwned(id: $id, userId: $userId);
        $children    = $this->mapper->findChildren($id);
        $secretCount = $this->secretMapper->countByFolder($id);
        $hasSecrets  = ($secretCount > 0);

        if ($children === [] && $hasSecrets === false) {
            // Empty folder — direct delete. No cascade event.
            $this->mapper->delete($folder);
            $this->logger->info("Doriath: empty folder {$id} deleted by {$userId}");
            return;
        }

        $folderName     = $folder->getName();
        $subfolderCount = count($children);

        if ($children !== []) {
            $this->deleteWithSubfolders(folder: $folder, children: $children, resolution: $resolution, userId: $userId);

            $this->dispatchAudit(
                event: AuditEvent::forUser(
                    actorId: $userId,
                    eventType: AuditEventTypes::FOLDER_DELETED_CASCADE,
                    objectType: 'folder',
                    objectId: $id,
                    objectName: $folderName,
                    metadata: [
                        'secretCount'    => $secretCount,
                        'subfolderCount' => $subfolderCount,
                    ],
                )
            );
            return;
        }

        // Non-empty leaf folder (secrets, no subfolders) — requires cascade.
        $this->deleteLeafWithSecrets(folder: $folder, cascade: $cascade);

        $this->dispatchAudit(
            event: AuditEvent::forUser(
                actorId: $userId,
                eventType: AuditEventTypes::FOLDER_DELETED_CASCADE,
                objectType: 'folder',
                objectId: $id,
                objectName: $folderName,
                metadata: [
                    'secretCount'    => $secretCount,
                    'subfolderCount' => 0,
                ],
            )
        );
    }//end delete()

    /**
     * Delete a leaf folder that contains secrets but no subfolders.
     *
     * @param Folder      $folder  The folder to delete
     * @param string|null $cascade The cascade mode (delete or move)
     *
     * @return void
     *
     * @throws ConflictException When no cascade mode is given
     */
    private function deleteLeafWithSecrets(Folder $folder, ?string $cascade): void
    {
        if ($cascade !== 'delete' && $cascade !== 'move') {
            throw new ConflictException(message: 'Folder is not empty');
        }

        if ($cascade === 'delete') {
            $this->cascadeAttachmentsForFolder(folderId: $folder->getId());
            $this->secretMapper->deleteByFolderId($folder->getId());
        }

        if ($cascade === 'move') {
            $this->secretMapper->updateFolderForSecrets($folder->getId(), $folder->getParentId());
        }

        $this->mapper->delete($folder);
        $this->logger->info("Doriath: leaf folder {$folder->getId()} deleted (cascade={$cascade})");
    }//end deleteLeafWithSecrets()

    /**
     * Delete a folder that contains subfolders, using the resolution plan.
     *
     * @param Folder                   $folder     The folder to delete
     * @param Folder[]                 $children   The direct subfolders
     * @param array<string,mixed>|null $resolution The resolution body
     * @param string                   $userId     The requesting Nextcloud user ID
     *
     * @return void
     *
     * @throws ConflictException When no resolution body is supplied
     * @throws InvalidArgumentException When the plan is incomplete or invalid
     */
    private function deleteWithSubfolders(Folder $folder, array $children, ?array $resolution, string $userId): void
    {
        if ($resolution === null || isset($resolution['subfolders']) === false) {
            throw new ConflictException(message: 'Folder contains subfolders — resolution required');
        }

        $plan = $resolution['subfolders'];
        if (is_array($plan) === false) {
            throw new InvalidArgumentException('Invalid resolution body');
        }

        // Every direct subfolder must be accounted for.
        foreach ($children as $child) {
            if (array_key_exists($child->getId(), $plan) === false) {
                throw new InvalidArgumentException('Resolution body is missing one or more subfolders');
            }
        }

        $parentId = $folder->getParentId();

        // Direct secrets of the deleted folder follow the directSecrets action.
        $directAction = $resolution['directSecrets'] ?? 'move';
        if ($directAction === 'delete') {
            $this->cascadeAttachmentsForFolder(folderId: $folder->getId());
            $this->secretMapper->deleteByFolderId($folder->getId());
        }

        if ($directAction !== 'delete') {
            $this->secretMapper->updateFolderForSecrets($folder->getId(), $parentId);
        }

        foreach ($children as $child) {
            $action = $plan[$child->getId()];
            $this->applySubfolderAction(subfolder: $child, action: (string) $action, deletedParentId: $parentId);
        }

        $this->mapper->delete($folder);
        $this->logger->info("Doriath: folder {$folder->getId()} deleted with resolution by {$userId}");
    }//end deleteWithSubfolders()

    /**
     * Apply a single per-subfolder resolution action (delete/move/keep).
     *
     * @param Folder      $subfolder       The direct subfolder
     * @param string      $action          The action: delete, move, or keep
     * @param string|null $deletedParentId The parent of the deleted folder (re-parent target)
     *
     * @return void
     *
     * @throws InvalidArgumentException When the action is unknown
     * @throws DuplicateFolderNameException When a kept subfolder collides with a name in the destination parent
     */
    private function applySubfolderAction(Folder $subfolder, string $action, ?string $deletedParentId): void
    {
        switch ($action) {
            case 'delete':
                $subtreeIds = $this->mapper->getSubtreeIds($subfolder->getId());
                foreach ($subtreeIds as $folderId) {
                    $this->cascadeAttachmentsForFolder(folderId: (string) $folderId);
                    $this->secretMapper->deleteByFolderId($folderId);
                }

                $this->deleteSubtreeFolders(folderIds: $subtreeIds);
                break;

            case 'move':
                $subtreeIds = $this->mapper->getSubtreeIds($subfolder->getId());
                foreach ($subtreeIds as $folderId) {
                    $this->secretMapper->updateFolderForSecrets($folderId, $deletedParentId);
                }

                $this->deleteSubtreeFolders(folderIds: $subtreeIds);
                break;

            case 'keep':
                $this->assertNameUnique(
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
                throw new InvalidArgumentException('Unknown subfolder action: '.$action);
        }//end switch
    }//end applySubfolderAction()

    /**
     * Assert that a folder name is unique among its siblings.
     *
     * Siblings share the same owner and the same parent. Pass $excludeId to
     * ignore the folder being renamed, moved, or re-parented so it does not
     * conflict with itself.
     *
     * @param string      $ownerType The owner type
     * @param string      $ownerId   The owner ID
     * @param string|null $parentId  The parent folder ID, or null for root level
     * @param string      $name      The folder name to check
     * @param string|null $excludeId A folder ID to exclude from the check, or null
     *
     * @return void
     *
     * @throws DuplicateFolderNameException When a sibling with the same name exists
     */
    private function assertNameUnique(
        string $ownerType,
        string $ownerId,
        ?string $parentId,
        string $name,
        ?string $excludeId=null,
    ): void {
        $exists = $this->mapper->existsInParent(
            ownerType: $ownerType,
            ownerId: $ownerId,
            parentId: $parentId,
            name: $name,
            excludeId: $excludeId
        );

        if ($exists === true) {
            throw new DuplicateFolderNameException(
                message: "A folder named '{$name}' already exists in this location"
            );
        }
    }//end assertNameUnique()

    /**
     * Delete a set of folder rows by ID (leaves deepest first to avoid
     * orphaning, though parent_id has no FK constraint).
     *
     * @param string[] $folderIds The folder IDs to delete
     *
     * @return void
     */
    private function deleteSubtreeFolders(array $folderIds): void
    {
        // Reverse so descendants are removed before ancestors.
        foreach (array_reverse($folderIds) as $folderId) {
            try {
                $this->mapper->delete($this->mapper->findById($folderId));
            } catch (DoesNotExistException | MultipleObjectsReturnedException) {
                continue;
            }
        }
    }//end deleteSubtreeFolders()
}//end class
