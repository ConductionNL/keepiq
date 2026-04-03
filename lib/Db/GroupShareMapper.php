<?php

/**
 * Doriath Group Share Mapper
 *
 * Database mapper for group share entities.
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
 * Mapper for GroupShare entities.
 *
 * @extends QBMapper<GroupShare>
 */
class GroupShareMapper extends QBMapper
{
    /**
     * Constructor for GroupShareMapper.
     *
     * @param IDBConnection $db The database connection
     *
     * @return void
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(db: $db, tableName: 'doriath_grp_shares', entityClass: GroupShare::class);
    }//end __construct()

    /**
     * Find a group share by its ID.
     *
     * @param string $id The group share ID
     *
     * @return GroupShare
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function findById(string $id): GroupShare
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

        return $this->findEntity(query: $qb);
    }//end findById()

    /**
     * Find all group shares for a given secret.
     *
     * @param string $secretId The secret ID
     *
     * @return GroupShare[]
     */
    public function findBySecret(string $secretId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)));

        return $this->findEntities(query: $qb);
    }//end findBySecret()

    /**
     * Find all group shares for a given group.
     *
     * @param string $groupId The group ID
     *
     * @return GroupShare[]
     */
    public function findByGroup(string $groupId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('group_id', $qb->createNamedParameter($groupId)));

        return $this->findEntities(query: $qb);
    }//end findByGroup()

    /**
     * Find the group share for a specific secret and group combination.
     *
     * @param string $secretId The secret ID
     * @param string $groupId  The group ID
     *
     * @return GroupShare
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function findBySecretAndGroup(string $secretId, string $groupId): GroupShare
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)))
            ->andWhere($qb->expr()->eq('group_id', $qb->createNamedParameter($groupId)));

        return $this->findEntity(query: $qb);
    }//end findBySecretAndGroup()

    /**
     * Delete all group shares for a given secret.
     *
     * @param string $secretId The secret ID
     *
     * @return int The number of deleted rows
     */
    public function deleteBySecret(string $secretId): int
    {
        $sql  = 'DELETE FROM *PREFIX*doriath_grp_shares WHERE secret_id = ?';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$secretId]);
        $count = $stmt->rowCount();
        $stmt->closeCursor();

        return $count;
    }//end deleteBySecret()
}//end class
