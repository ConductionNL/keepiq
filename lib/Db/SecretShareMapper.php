<?php

/**
 * Doriath Secret Share Mapper
 *
 * Database mapper for secret share entities.
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
 * Mapper for SecretShare entities.
 *
 * @extends QBMapper<SecretShare>
 */
class SecretShareMapper extends QBMapper
{
    /**
     * Constructor for SecretShareMapper.
     *
     * @param IDBConnection $db The database connection
     *
     * @return void
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(db: $db, tableName: 'doriath_sec_shares', entityClass: SecretShare::class);
    }//end __construct()

    /**
     * Find a secret share by its ID.
     *
     * @param string $id The share ID
     *
     * @return SecretShare
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function findById(string $id): SecretShare
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

        return $this->findEntity(query: $qb);
    }//end findById()

    /**
     * Find all shares originating from a given source secret.
     *
     * @param string $sourceSecretId The source secret ID
     *
     * @return SecretShare[]
     */
    public function findBySourceSecret(string $sourceSecretId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('source_secret_id', $qb->createNamedParameter($sourceSecretId)));

        return $this->findEntities(query: $qb);
    }//end findBySourceSecret()

    /**
     * Find all shares targeting a given user.
     *
     * @param string $targetUserId The target user ID
     *
     * @return SecretShare[]
     */
    public function findByTargetUser(string $targetUserId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('target_user_id', $qb->createNamedParameter($targetUserId)));

        return $this->findEntities(query: $qb);
    }//end findByTargetUser()

    /**
     * Find all shares that originated from a given group share.
     *
     * @param string $groupShareId The group share ID
     *
     * @return SecretShare[]
     */
    public function findByGroupShare(string $groupShareId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('group_share_id', $qb->createNamedParameter($groupShareId)));

        return $this->findEntities(query: $qb);
    }//end findByGroupShare()

    /**
     * Find a share for a specific source secret and target user combination.
     *
     * @param string $sourceSecretId The source secret ID
     * @param string $targetUserId   The target user ID
     *
     * @return SecretShare
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function findBySourceSecretAndTargetUser(string $sourceSecretId, string $targetUserId): SecretShare
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('source_secret_id', $qb->createNamedParameter($sourceSecretId)))
            ->andWhere($qb->expr()->eq('target_user_id', $qb->createNamedParameter($targetUserId)));

        return $this->findEntity(query: $qb);
    }//end findBySourceSecretAndTargetUser()

    /**
     * Delete all shares originating from a given source secret.
     *
     * @param string $sourceSecretId The source secret ID
     *
     * @return int The number of deleted rows
     */
    public function deleteBySourceSecret(string $sourceSecretId): int
    {
        $sql  = 'DELETE FROM *PREFIX*doriath_sec_shares WHERE source_secret_id = ?';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$sourceSecretId]);
        $count = $stmt->rowCount();
        $stmt->closeCursor();

        return $count;
    }//end deleteBySourceSecret()

    /**
     * Delete all shares targeting a given user.
     *
     * @param string $targetUserId The target user ID
     *
     * @return int The number of deleted rows
     */
    public function deleteByTargetUser(string $targetUserId): int
    {
        $sql  = 'DELETE FROM *PREFIX*doriath_sec_shares WHERE target_user_id = ?';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$targetUserId]);
        $count = $stmt->rowCount();
        $stmt->closeCursor();

        return $count;
    }//end deleteByTargetUser()

    /**
     * Delete all shares that originated from a given group share.
     *
     * @param string $groupShareId The group share ID
     *
     * @return int The number of deleted rows
     */
    public function deleteByGroupShare(string $groupShareId): int
    {
        $sql  = 'DELETE FROM *PREFIX*doriath_sec_shares WHERE group_share_id = ?';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$groupShareId]);
        $count = $stmt->rowCount();
        $stmt->closeCursor();

        return $count;
    }//end deleteByGroupShare()
}//end class
