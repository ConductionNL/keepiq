<?php

/**
 * Doriath Secret Share Mapper
 *
 * Database mapper for SecretShare entities.
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
        parent::__construct(db: $db, tableName: 'doriath_secret_shares', entityClass: SecretShare::class);
    }//end __construct()

    /**
     * Find a SecretShare by its ID.
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
     * Find all shares for a given source secret.
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
     * @param string $targetUserId The recipient user ID
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
     * Find all shares derived from a given group share.
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
     * Find a single share for a source secret and target user, or null.
     *
     * @param string $sourceSecretId The source secret ID
     * @param string $targetUserId   The recipient user ID
     *
     * @return SecretShare|null
     */
    public function findBySourceSecretAndTargetUser(string $sourceSecretId, string $targetUserId): ?SecretShare
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('source_secret_id', $qb->createNamedParameter($sourceSecretId)))
            ->andWhere($qb->expr()->eq('target_user_id', $qb->createNamedParameter($targetUserId)))
            ->setMaxResults(1);

        try {
            return $this->findEntity(query: $qb);
        } catch (DoesNotExistException | MultipleObjectsReturnedException $e) {
            return null;
        }
    }//end findBySourceSecretAndTargetUser()

    /**
     * Delete all shares for a given source secret.
     *
     * @param string $sourceSecretId The source secret ID
     *
     * @return SecretShare[] The deleted shares (for cascade copy cleanup)
     */
    public function deleteBySourceSecret(string $sourceSecretId): array
    {
        $shares = $this->findBySourceSecret($sourceSecretId);
        foreach ($shares as $share) {
            $this->delete($share);
        }

        return $shares;
    }//end deleteBySourceSecret()

    /**
     * Delete all shares targeting a given user.
     *
     * @param string $targetUserId The recipient user ID
     *
     * @return SecretShare[] The deleted shares (for cascade copy cleanup)
     */
    public function deleteByTargetUser(string $targetUserId): array
    {
        $shares = $this->findByTargetUser($targetUserId);
        foreach ($shares as $share) {
            $this->delete($share);
        }

        return $shares;
    }//end deleteByTargetUser()

    /**
     * Delete all shares derived from a given group share.
     *
     * @param string $groupShareId The group share ID
     *
     * @return SecretShare[] The deleted shares (for cascade copy cleanup)
     */
    public function deleteByGroupShare(string $groupShareId): array
    {
        $shares = $this->findByGroupShare($groupShareId);
        foreach ($shares as $share) {
            $this->delete($share);
        }

        return $shares;
    }//end deleteByGroupShare()
}//end class
