<?php

/**
 * Doriath Folder Mapper
 *
 * Database mapper for folder entities.
 *
 * @category Db
 * @package  OCA\Doriath\Db
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

namespace OCA\Doriath\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Mapper for Folder entities.
 *
 * @extends QBMapper<Folder>
 */
class FolderMapper extends QBMapper
{
    /**
     * Constructor for FolderMapper.
     *
     * @param IDBConnection $db The database connection
     *
     * @return void
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(db: $db, tableName: 'doriath_folders', entityClass: Folder::class);
    }//end __construct()

    /**
     * Find a folder by its ID.
     *
     * @param string $id The folder ID
     *
     * @return Folder
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function findById(string $id): Folder
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

        return $this->findEntity(query: $qb);
    }//end findById()

    /**
     * Find all folders for a given owner.
     *
     * @param string $ownerType The owner type
     * @param string $ownerId   The owner ID
     *
     * @return Folder[]
     */
    public function findByOwner(string $ownerType, string $ownerId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('owner_type', $qb->createNamedParameter($ownerType)))
            ->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)));

        return $this->findEntities(query: $qb);
    }//end findByOwner()

    /**
     * Find all child folders of a given parent folder.
     *
     * @param string $parentId The parent folder ID
     *
     * @return Folder[]
     */
    public function findChildren(string $parentId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('parent_id', $qb->createNamedParameter($parentId)));

        return $this->findEntities(query: $qb);
    }//end findChildren()

    /**
     * Find root folders (no parent) for a given owner.
     *
     * @param string $ownerType The owner type
     * @param string $ownerId   The owner ID
     *
     * @return Folder[]
     */
    public function findRootFolders(string $ownerType, string $ownerId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->isNull('parent_id'))
            ->andWhere($qb->expr()->eq('owner_type', $qb->createNamedParameter($ownerType)))
            ->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)));

        return $this->findEntities(query: $qb);
    }//end findRootFolders()

    /**
     * Count secrets directly in a folder.
     *
     * @param string $folderId The folder ID
     *
     * @return int
     */
    public function countSecrets(string $folderId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from('doriath_secrets')
            ->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId)));

        $result = $qb->executeQuery();
        $count  = (int) $result->fetchOne();
        $result->closeCursor();

        return $count;
    }//end countSecrets()

    /**
     * Check whether a sibling folder with the given name already exists.
     *
     * A sibling shares the same owner and the same parent. Root folders
     * (parent_id IS NULL) are handled via an IS NULL comparison. An optional
     * folder ID can be excluded so a folder does not conflict with itself on
     * rename, move, or re-parent.
     *
     * @param string      $ownerType The owner type
     * @param string      $ownerId   The owner ID
     * @param string|null $parentId  The parent folder ID, or null for root level
     * @param string      $name      The folder name to check
     * @param string|null $excludeId A folder ID to exclude from the check, or null
     *
     * @return bool True when a conflicting sibling exists
     */
    public function existsInParent(
        string $ownerType,
        string $ownerId,
        ?string $parentId,
        string $name,
        ?string $excludeId=null,
    ): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('owner_type', $qb->createNamedParameter($ownerType)))
            ->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)))
            ->andWhere($qb->expr()->eq('name', $qb->createNamedParameter($name)));

        if ($parentId === null) {
            $qb->andWhere($qb->expr()->isNull('parent_id'));
        } else {
            $qb->andWhere($qb->expr()->eq('parent_id', $qb->createNamedParameter($parentId)));
        }

        if ($excludeId !== null) {
            $qb->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($excludeId)));
        }

        $result = $qb->executeQuery();
        $count  = (int) $result->fetchOne();
        $result->closeCursor();

        return $count > 0;
    }//end existsInParent()

    /**
     * Count all secrets in a folder subtree (including nested folders).
     *
     * Uses a recursive CTE to traverse the folder hierarchy.
     *
     * @param string $folderId The root folder ID
     *
     * @return int
     */
    public function countSecretsRecursive(string $folderId): int
    {
        $sql = 'WITH RECURSIVE folder_tree AS (
                    SELECT id FROM *PREFIX*doriath_folders WHERE id = ?
                    UNION ALL
                    SELECT f.id FROM *PREFIX*doriath_folders f
                    INNER JOIN folder_tree ft ON f.parent_id = ft.id
                )
                SELECT COUNT(*) FROM *PREFIX*doriath_secrets
                WHERE folder_id IN (SELECT id FROM folder_tree)';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$folderId]);
        $count = (int) $stmt->fetchOne();
        $stmt->closeCursor();

        return $count;
    }//end countSecretsRecursive()

    /**
     * Get all folder IDs in the subtree rooted at the given folder.
     *
     * Uses a recursive CTE to traverse the folder hierarchy.
     *
     * @param string $folderId The root folder ID
     *
     * @return string[]
     */
    public function getSubtreeIds(string $folderId): array
    {
        $sql = 'WITH RECURSIVE folder_tree AS (
                    SELECT id FROM *PREFIX*doriath_folders WHERE id = ?
                    UNION ALL
                    SELECT f.id FROM *PREFIX*doriath_folders f
                    INNER JOIN folder_tree ft ON f.parent_id = ft.id
                )
                SELECT id FROM folder_tree';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$folderId]);
        $rows = $stmt->fetchAll();
        $stmt->closeCursor();

        return array_column($rows, 'id');
    }//end getSubtreeIds()
}//end class
