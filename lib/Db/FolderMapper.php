<?php

/**
 * Doriath Folder Mapper
 *
 * Database mapper for folder entities, including tree-traversal helpers
 * (path derivation, subtree collection, recursive secret counts).
 *
 * Subtree traversal is implemented with iterative breadth-first queries
 * rather than a database-specific recursive CTE so the mapper works
 * identically on PostgreSQL, MySQL/MariaDB, and SQLite (used by the test
 * suite). Vault folder trees are shallow (typically <10 levels), so the
 * per-level query cost is negligible.
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
     * Find a folder by its UUID.
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
     * Find all folders owned by a given owner.
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
            ->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)))
            ->orderBy('name', 'ASC');

        return $this->findEntities(query: $qb);
    }//end findByOwner()

    /**
     * Find the direct child folders of a given parent.
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
            ->where($qb->expr()->eq('parent_id', $qb->createNamedParameter($parentId)))
            ->orderBy('name', 'ASC');

        return $this->findEntities(query: $qb);
    }//end findChildren()

    /**
     * Find the root-level folders of an owner (parent_id IS NULL).
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
            ->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)))
            ->orderBy('name', 'ASC');

        return $this->findEntities(query: $qb);
    }//end findRootFolders()

    /**
     * Derive the full slash-separated path of a folder by walking parent_id
     * links up to the root. Path strings are never stored.
     *
     * @param string $folderId The folder ID
     *
     * @return string The derived path (e.g. "personal/email/work")
     */
    public function getPath(string $folderId): string
    {
        $segments  = [];
        $currentId = $folderId;
        $guard     = 0;

        while ($currentId !== null && $guard < 100) {
            try {
                $folder = $this->findById(id: $currentId);
            } catch (DoesNotExistException | MultipleObjectsReturnedException) {
                break;
            }

            array_unshift($segments, $folder->getName());
            $currentId = $folder->getParentId();
            $guard++;
        }

        return implode('/', $segments);
    }//end getPath()

    /**
     * Collect the IDs of a folder and every folder beneath it (its subtree).
     *
     * @param string $folderId The root folder ID of the subtree
     *
     * @return string[] All folder IDs in the subtree, including the root
     */
    public function getSubtreeIds(string $folderId): array
    {
        $collected = [$folderId];
        $frontier  = [$folderId];
        $guard     = 0;

        while ($frontier !== [] && $guard < 10000) {
            $children = [];
            foreach ($frontier as $parentId) {
                foreach ($this->findChildren(parentId: $parentId) as $child) {
                    $childId = $child->getId();
                    if (in_array($childId, $collected, true) === false) {
                        $collected[] = $childId;
                        $children[]  = $childId;
                    }

                    $guard++;
                }
            }

            $frontier = $children;
        }

        return $collected;
    }//end getSubtreeIds()
}//end class
