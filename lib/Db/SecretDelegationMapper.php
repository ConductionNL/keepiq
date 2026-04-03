<?php

/**
 * Doriath Secret Delegation Mapper
 *
 * Database mapper for secret delegation entities.
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
 * Mapper for SecretDelegation entities.
 *
 * @extends QBMapper<SecretDelegation>
 */
class SecretDelegationMapper extends QBMapper
{
    /**
     * Constructor for SecretDelegationMapper.
     *
     * @param IDBConnection $db The database connection
     *
     * @return void
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(db: $db, tableName: 'doriath_sec_deleg', entityClass: SecretDelegation::class);
    }//end __construct()

    /**
     * Find a delegation by its ID.
     *
     * @param string $id The delegation ID
     *
     * @return SecretDelegation
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function findById(string $id): SecretDelegation
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

        return $this->findEntity(query: $qb);
    }//end findById()

    /**
     * Find all delegations for a given secret.
     *
     * @param string $secretId The secret ID
     *
     * @return SecretDelegation[]
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
     * Find the active (non-permanent) delegation for a specific secret and delegated user.
     *
     * @param string $secretId    The secret ID
     * @param string $delegatedTo The delegated user ID
     *
     * @return SecretDelegation
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function findActiveBySecretAndUser(string $secretId, string $delegatedTo): SecretDelegation
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)))
            ->andWhere($qb->expr()->eq('delegated_to', $qb->createNamedParameter($delegatedTo)))
            ->andWhere($qb->expr()->eq('is_permanent', $qb->createNamedParameter(false, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL)));

        return $this->findEntity(query: $qb);
    }//end findActiveBySecretAndUser()

    /**
     * Find all delegations where the given user is the original owner.
     *
     * @param string $originalOwnerId The original owner user ID
     *
     * @return SecretDelegation[]
     */
    public function findByOriginalOwner(string $originalOwnerId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('original_owner_id', $qb->createNamedParameter($originalOwnerId)));

        return $this->findEntities(query: $qb);
    }//end findByOriginalOwner()

    /**
     * Find all temporary (non-permanent) delegations for a given original owner.
     *
     * @param string $originalOwnerId The original owner user ID
     *
     * @return SecretDelegation[]
     */
    public function findTemporaryByOriginalOwner(string $originalOwnerId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('original_owner_id', $qb->createNamedParameter($originalOwnerId)))
            ->andWhere($qb->expr()->eq('is_permanent', $qb->createNamedParameter(false, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL)));

        return $this->findEntities(query: $qb);
    }//end findTemporaryByOriginalOwner()

    /**
     * Delete all delegations for a given secret.
     *
     * @param string $secretId The secret ID
     *
     * @return int The number of deleted rows
     */
    public function deleteBySecret(string $secretId): int
    {
        $sql  = 'DELETE FROM *PREFIX*doriath_sec_deleg WHERE secret_id = ?';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$secretId]);
        $count = $stmt->rowCount();
        $stmt->closeCursor();

        return $count;
    }//end deleteBySecret()

    /**
     * Mark all temporary delegations for a given original owner as permanent.
     *
     * Sets is_permanent = TRUE and records the current timestamp in made_permanent_at.
     *
     * @param string $originalOwnerId The original owner user ID
     *
     * @return int The number of updated rows
     */
    public function makePermanentByOriginalOwner(string $originalOwnerId): int
    {
        $now  = (new \DateTime())->format('Y-m-d H:i:s');
        $sql  = 'UPDATE *PREFIX*doriath_sec_deleg'
            .' SET is_permanent = TRUE, made_permanent_at = ?'
            .' WHERE original_owner_id = ? AND is_permanent = FALSE';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$now, $originalOwnerId]);
        $count = $stmt->rowCount();
        $stmt->closeCursor();

        return $count;
    }//end makePermanentByOriginalOwner()
}//end class
