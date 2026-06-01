<?php

/**
 * Doriath Secret Delegation Mapper
 *
 * Database mapper for SecretDelegation entities.
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

use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
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
        parent::__construct(db: $db, tableName: 'doriath_secret_delegations', entityClass: SecretDelegation::class);
    }//end __construct()

    /**
     * Find a SecretDelegation by its ID.
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
     * Find the active delegation for a secret and delegate user, or null.
     *
     * @param string $secretId The secret ID
     * @param string $userId   The delegate user ID
     *
     * @return SecretDelegation|null
     */
    public function findActiveBySecretAndUser(string $secretId, string $userId): ?SecretDelegation
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)))
            ->andWhere($qb->expr()->eq('delegated_to', $qb->createNamedParameter($userId)))
            ->setMaxResults(1);

        try {
            return $this->findEntity(query: $qb);
        } catch (DoesNotExistException | MultipleObjectsReturnedException $e) {
            return null;
        }
    }//end findActiveBySecretAndUser()

    /**
     * Find all delegations created by a given original owner.
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
     * Find all temporary delegations created by a given original owner.
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
            ->andWhere($qb->expr()->eq('is_permanent', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)));

        return $this->findEntities(query: $qb);
    }//end findTemporaryByOriginalOwner()

    /**
     * Delete all delegations for a given secret.
     *
     * @param string $secretId The secret ID
     *
     * @return SecretDelegation[] The deleted delegations
     */
    public function deleteBySecret(string $secretId): array
    {
        $delegations = $this->findBySecret($secretId);
        foreach ($delegations as $delegation) {
            $this->delete($delegation);
        }

        return $delegations;
    }//end deleteBySecret()

    /**
     * Mark all temporary delegations for an original owner as permanent.
     *
     * @param string $originalOwnerId The original owner user ID
     *
     * @return SecretDelegation[] The delegations that were made permanent
     */
    public function makePermanentByOriginalOwner(string $originalOwnerId): array
    {
        $delegations = $this->findTemporaryByOriginalOwner($originalOwnerId);
        $now         = new DateTime();
        foreach ($delegations as $delegation) {
            $delegation->setIsPermanent(true);
            $delegation->setMadePermanentAt($now);
            $this->update($delegation);
        }

        return $delegations;
    }//end makePermanentByOriginalOwner()
}//end class
