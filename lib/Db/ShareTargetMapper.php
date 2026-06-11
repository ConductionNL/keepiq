<?php

/**
 * Doriath Share Target Mapper
 *
 * Database mapper for share target entities.
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
 * Mapper for ShareTarget entities.
 *
 * @extends QBMapper<ShareTarget>
 */
class ShareTargetMapper extends QBMapper
{
    /**
     * Constructor for ShareTargetMapper.
     *
     * @param IDBConnection $db The database connection
     *
     * @return void
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(db: $db, tableName: 'doriath_share_targets', entityClass: ShareTarget::class);
    }//end __construct()

    /**
     * Find a share target by its UUID.
     *
     * @param string $id The share target ID
     *
     * @return ShareTarget
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function findById(string $id): ShareTarget
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

        return $this->findEntity(query: $qb);
    }//end findById()

    /**
     * Find all share targets for a given source secret.
     *
     * @param string $sourceSecretId The owner's source secret ID
     *
     * @return ShareTarget[]
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
     * Find all share targets for a given recipient user.
     *
     * @param string $targetUserId The recipient Nextcloud user ID
     *
     * @return ShareTarget[]
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
     * Delete all share targets for a given source secret (cascade).
     *
     * @param string $sourceSecretId The source secret ID
     *
     * @return void
     */
    public function deleteBySourceSecret(string $sourceSecretId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('source_secret_id', $qb->createNamedParameter($sourceSecretId)));

        $qb->executeStatement();
    }//end deleteBySourceSecret()
}//end class
