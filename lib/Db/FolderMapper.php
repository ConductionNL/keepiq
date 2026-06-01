<?php

/**
 * Doriath Folder Mapper
 *
 * Database mapper for folder entities, including tree traversal helpers.
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
 * Tree traversal (path, subtree, recursive counts) is implemented with
 * portable per-level queries rather than a vendor-specific recursive CTE,
 * so it works identically on PostgreSQL, MySQL/MariaDB and SQLite. Vault
 * trees are shallow (rarely beyond 5-10 levels), so the per-level walk is
 * cheap.
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
            ->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)))
            ->orderBy('name', 'ASC');

        return $this->findEntities(query: $qb);
    }//end findByOwner()

    /**
     * Find the direct children of a folder.
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
     * Find the root-level folders for an owner.
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
            ->where($qb->expr()->eq('owner_type', $qb->createNamedParameter($ownerType)))
            ->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)))
            ->andWhere($qb->expr()->isNull('parent_id'))
            ->orderBy('name', 'ASC');

        return $this->findEntities(query: $qb);
    }//end findRootFolders()

    /**
     * Derive the path of a folder by walking parent links.
     *
     * @param string $folderId The folder ID
     *
     * @return string The slash-joined path (e.g. personal/email/work)
     */
    public function getPath(string $folderId): string
    {
        $segments  = [];
        $currentId = $folderId;
        $guard     = 0;

        while ($currentId !== null && $guard < 100) {
            try {
                $folder = $this->findById(id: $currentId);
            } catch (DoesNotExistException) {
                break;
            }

            array_unshift($segments, $folder->getName());
            $currentId = $folder->getParentId();
            $guard++;
        }

        return implode('/', $segments);
    }//end getPath()

    /**
     * Collect all folder IDs in the subtree rooted at a folder (inclusive).
     *
     * @param string $folderId The root folder ID
     *
     * @return string[] All folder IDs in the subtree, including the root
     */
    public function getSubtreeIds(string $folderId): array
    {
        $ids   = [$folderId];
        $queue = [$folderId];

        while (empty($queue) === false) {
            $current = array_shift($queue);
            foreach ($this->findChildren(parentId: $current) as $child) {
                $childId = $child->getId();
                $ids[]   = $childId;
                $queue[] = $childId;
            }
        }

        return $ids;
    }//end getSubtreeIds()
}//end class
