<?php

/**
 * Doriath Secret Request Mapper
 *
 * Database mapper for secret request entities.
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
 * Mapper for SecretRequest entities.
 *
 * @extends QBMapper<SecretRequest>
 */
class SecretRequestMapper extends QBMapper
{
    /**
     * Constructor for SecretRequestMapper.
     *
     * @param IDBConnection $db The database connection
     *
     * @return void
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(db: $db, tableName: 'doriath_secret_requests', entityClass: SecretRequest::class);
    }//end __construct()

    /**
     * Find a secret request by its UUID.
     *
     * @param string $id The secret request ID
     *
     * @return SecretRequest
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function findById(string $id): SecretRequest
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

        return $this->findEntity(query: $qb);
    }//end findById()

    /**
     * Find a secret request by its public access token.
     *
     * @param string $token The access token
     *
     * @return SecretRequest
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function findByToken(string $token): SecretRequest
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('token', $qb->createNamedParameter($token)));

        return $this->findEntity(query: $qb);
    }//end findByToken()

    /**
     * Find all secret requests for a given Secret.
     *
     * @param string $secretId The Secret ID
     *
     * @return SecretRequest[]
     */
    public function findBySecretId(string $secretId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)));

        return $this->findEntities(query: $qb);
    }//end findBySecretId()

    /**
     * Find all secret requests created by a given user.
     *
     * @param string $userId The Nextcloud user ID
     *
     * @return SecretRequest[]
     */
    public function findByCreatedBy(string $userId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('created_by', $qb->createNamedParameter($userId)));

        return $this->findEntities(query: $qb);
    }//end findByCreatedBy()

    /**
     * Delete all secret requests for a given Secret (cascade on secret delete).
     *
     * @param string $secretId The Secret ID
     *
     * @return void
     */
    public function deleteBySecretId(string $secretId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)));

        $qb->executeStatement();
    }//end deleteBySecretId()
}//end class
