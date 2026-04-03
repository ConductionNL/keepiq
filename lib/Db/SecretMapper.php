<?php

/**
 * Doriath Secret Mapper
 *
 * Database mapper for secret entities.
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
 * Mapper for Secret entities.
 *
 * @extends QBMapper<Secret>
 */
class SecretMapper extends QBMapper
{
    /**
     * Constructor for SecretMapper.
     *
     * @param IDBConnection $db The database connection
     *
     * @return void
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(db: $db, tableName: 'doriath_secrets', entityClass: Secret::class);
    }//end __construct()

    /**
     * Find a secret by its ID.
     *
     * @param string $id The secret ID
     *
     * @return Secret
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function findById(string $id): Secret
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

        return $this->findEntity(query: $qb);
    }//end findById()

    /**
     * Find secrets for a given owner with optional folder filter, sorting and pagination.
     *
     * @param string      $ownerType The owner type
     * @param string      $ownerId   The owner ID
     * @param string|null $folderId  Optional folder ID to filter by
     * @param string      $sort      The field to sort by
     * @param string      $direction The sort direction (ASC/DESC)
     * @param int         $limit     The maximum number of results
     * @param int         $offset    The offset for pagination
     *
     * @return Secret[]
     */
    public function findByOwner(
        string $ownerType,
        string $ownerId,
        ?string $folderId=null,
        string $sort='name',
        string $direction='ASC',
        int $limit=50,
        int $offset=0
    ): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('owner_type', $qb->createNamedParameter($ownerType)))
            ->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)));

        if ($folderId === 'root') {
            $qb->andWhere($qb->expr()->isNull('folder_id'));
        } else if ($folderId !== null) {
            $qb->andWhere($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId)));
        }

        $qb->orderBy($sort, $direction)
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        return $this->findEntities(query: $qb);
    }//end findByOwner()

    /**
     * Count secrets for a given owner with optional folder filter.
     *
     * @param string      $ownerType The owner type
     * @param string      $ownerId   The owner ID
     * @param string|null $folderId  Optional folder ID to filter by
     *
     * @return int
     */
    public function countByOwner(string $ownerType, string $ownerId, ?string $folderId=null): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('owner_type', $qb->createNamedParameter($ownerType)))
            ->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)));

        if ($folderId === 'root') {
            $qb->andWhere($qb->expr()->isNull('folder_id'));
        } else if ($folderId !== null) {
            $qb->andWhere($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId)));
        }

        $result = $qb->executeQuery();
        $count  = (int) $result->fetchOne();
        $result->closeCursor();

        return $count;
    }//end countByOwner()

    /**
     * Find all secrets in a given folder.
     *
     * @param string $folderId The folder ID
     *
     * @return Secret[]
     */
    public function findByFolder(string $folderId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId)));

        return $this->findEntities(query: $qb);
    }//end findByFolder()

    /**
     * Count secrets in a given folder.
     *
     * @param string $folderId The folder ID
     *
     * @return int
     */
    public function countByFolder(string $folderId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId)));

        $result = $qb->executeQuery();
        $count  = (int) $result->fetchOne();
        $result->closeCursor();

        return $count;
    }//end countByFolder()

    /**
     * Search secrets by name or URL for a given user using a case-insensitive match.
     *
     * @param string $userId The Nextcloud user ID
     * @param string $term   The search term
     *
     * @return Secret[]
     */
    public function searchByNameOrUrl(string $userId, string $term): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('owner_id', $qb->createNamedParameter($userId)))
            ->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->iLike('name', $qb->createNamedParameter('%'.$term.'%')),
                    $qb->expr()->iLike('url', $qb->createNamedParameter('%'.$term.'%'))
                )
            );

        return $this->findEntities(query: $qb);
    }//end searchByNameOrUrl()

    /**
     * Update the folder_id for all secrets currently in the given folder.
     *
     * @param string      $oldFolderId The folder ID to replace
     * @param string|null $newFolderId The new folder ID (null to unset)
     *
     * @return int The number of affected rows
     */
    public function updateFolderForSecrets(string $oldFolderId, ?string $newFolderId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('folder_id', $qb->createNamedParameter($newFolderId))
            ->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($oldFolderId)));

        return $qb->executeStatement();
    }//end updateFolderForSecrets()

    /**
     * Find all secrets with a given type ID.
     *
     * @param string $typeId The secret type ID
     *
     * @return Secret[]
     */
    public function findByTypeId(string $typeId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('type_id', $qb->createNamedParameter($typeId)));

        return $this->findEntities(query: $qb);
    }//end findByTypeId()

    /**
     * Delete all secrets in a given folder.
     *
     * @param string $folderId The folder ID
     *
     * @return int The number of deleted rows
     */
    public function deleteByFolderId(string $folderId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId)));

        return $qb->executeStatement();
    }//end deleteByFolderId()
}//end class
