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

    /**
     * Find a pending request for a given Secret, when one exists.
     *
     * @param string $secretId The Secret ID
     *
     * @return SecretRequest
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function findPendingBySecretId(string $secretId): SecretRequest
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(SecretRequest::STATUS_PENDING)));

        return $this->findEntity(query: $qb);
    }//end findPendingBySecretId()

    /**
     * Lock all pending requests bound to a given EncryptionSuite — used
     * by the compromise-recovery flow to freeze public fill links while
     * the recipient migrates to a new suite.
     *
     * @param string $encryptionSuiteId The recipient's old EncryptionSuite ID
     *
     * @return int The number of rows affected.
     */
    public function lockByEncryptionSuiteId(string $encryptionSuiteId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('status', $qb->createNamedParameter(SecretRequest::STATUS_LOCKED))
            ->where($qb->expr()->eq('encryption_suite_id', $qb->createNamedParameter($encryptionSuiteId)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(SecretRequest::STATUS_PENDING)));

        return $qb->executeStatement();
    }//end lockByEncryptionSuiteId()

    /**
     * Re-point all locked requests bound to the old EncryptionSuite at
     * the new EncryptionSuite + return them to pending.
     *
     * @param string $oldEncryptionSuiteId The old EncryptionSuite ID
     * @param string $newEncryptionSuiteId The new EncryptionSuite ID
     *
     * @return int The number of rows affected.
     */
    public function unlockAndUpdateSuite(string $oldEncryptionSuiteId, string $newEncryptionSuiteId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('encryption_suite_id', $qb->createNamedParameter($newEncryptionSuiteId))
            ->set('status', $qb->createNamedParameter(SecretRequest::STATUS_PENDING))
            ->where($qb->expr()->eq('encryption_suite_id', $qb->createNamedParameter($oldEncryptionSuiteId)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(SecretRequest::STATUS_LOCKED)));

        return $qb->executeStatement();
    }//end unlockAndUpdateSuite()

    /**
     * Delete every secret request created by a user (account-deletion cascade).
     *
     * Idempotent: a second call simply matches no rows.
     *
     * @param string $userId The Nextcloud user ID that created the requests
     *
     * @return int The number of rows deleted
     *
     * @spec openspec/changes/secret-export-gdpr/specs/gdpr-compliance/spec.md
     */
    public function deleteByCreatedBy(string $userId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('created_by', $qb->createNamedParameter($userId)));

        return $qb->executeStatement();
    }//end deleteByCreatedBy()
}//end class
