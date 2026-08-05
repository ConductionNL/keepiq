<?php

/**
 * Doriath Encryption Suite Mapper
 *
 * Database mapper for encryption suite entities.
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
 * Mapper for EncryptionSuite entities.
 *
 * @extends QBMapper<EncryptionSuite>
 */
class EncryptionSuiteMapper extends QBMapper
{
    /**
     * Constructor for EncryptionSuiteMapper.
     *
     * @param IDBConnection $db The database connection
     *
     * @return void
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(db: $db, tableName: 'doriath_enc_suites', entityClass: EncryptionSuite::class);
    }//end __construct()

    /**
     * Find an encryption suite by its ID.
     *
     * @param string $id The suite ID
     *
     * @return EncryptionSuite
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function findById(string $id): EncryptionSuite
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

        return $this->findEntity(query: $qb);
    }//end findById()

    /**
     * Find all encryption suites for a given owner.
     *
     * @param string $ownerType The owner type
     * @param string $ownerId   The owner ID
     *
     * @return EncryptionSuite[]
     */
    public function findByOwner(string $ownerType, string $ownerId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('owner_type', $qb->createNamedParameter($ownerType)))
            ->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)));

        return $this->findEntities(query: $qb);
    }//end findByOwner()

    /**
     * Find the active encryption suite for a given owner.
     *
     * @param string $ownerType The owner type
     * @param string $ownerId   The owner ID
     *
     * @return EncryptionSuite
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function findActiveByOwner(string $ownerType, string $ownerId): EncryptionSuite
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('owner_type', $qb->createNamedParameter($ownerType)))
            ->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('active')));

        return $this->findEntity(query: $qb);
    }//end findActiveByOwner()

    /**
     * Find all active encryption suites.
     *
     * @return EncryptionSuite[]
     */
    public function findAllActive(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter('active')));

        return $this->findEntities(query: $qb);
    }//end findAllActive()

    /**
     * Find all active encryption suites with limit and offset.
     *
     * @param int $limit  The maximum number of results
     * @param int $offset The offset for pagination
     *
     * @return EncryptionSuite[]
     */
    public function findAllActiveWithLimit(int $limit, int $offset): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter('active')))
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        return $this->findEntities(query: $qb);
    }//end findAllActiveWithLimit()

    /**
     * Count active suites of one owner type (certificate-lifecycle §2.6
     * issued-cert counts).
     *
     * @param string $ownerType 'user' or 'application'
     *
     * @return int
     */
    public function countActiveByOwnerType(string $ownerType): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter('active')))
            ->andWhere($qb->expr()->eq('owner_type', $qb->createNamedParameter($ownerType)));

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        return (int) ($row['cnt'] ?? 0);
    }//end countActiveByOwnerType()

    /**
     * Delete every encryption suite owned by a user (account-deletion cascade).
     *
     * Removes the certificate AND the encrypted private-key blob. Idempotent.
     *
     * @param string $ownerId The Nextcloud user ID
     *
     * @return int The number of rows deleted
     *
     * @spec openspec/changes/secret-export-gdpr/specs/gdpr-compliance/spec.md
     */
    public function deleteByOwnerUser(string $ownerId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('owner_type', $qb->createNamedParameter('user')))
            ->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)));

        return $qb->executeStatement();
    }//end deleteByOwnerUser()
}//end class
