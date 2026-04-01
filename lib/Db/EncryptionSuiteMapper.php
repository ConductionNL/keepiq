<?php

declare(strict_types=1);

namespace OCA\Doriath\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<EncryptionSuite>
 */
class EncryptionSuiteMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'doriath_encryption_suites', EncryptionSuite::class);
    }//end __construct()

    /**
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function findById(string $id): EncryptionSuite
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

        return $this->findEntity($qb);
    }//end findById()

    /**
     * @return EncryptionSuite[]
     */
    public function findByOwner(string $ownerType, string $ownerId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('owner_type', $qb->createNamedParameter($ownerType)))
            ->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)));

        return $this->findEntities($qb);
    }//end findByOwner()

    /**
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

        return $this->findEntity($qb);
    }//end findActiveByOwner()

    /**
     * @return EncryptionSuite[]
     */
    public function findAllActive(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter('active')));

        return $this->findEntities($qb);
    }//end findAllActive()

    /**
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

        return $this->findEntities($qb);
    }//end findAllActiveWithLimit()
}//end class
