<?php

declare(strict_types=1);

namespace OCA\Doriath\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<CACertificate>
 */
class CACertificateMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'doriath_ca_certificates', CACertificate::class);
    }//end __construct()

    /**
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function findActiveIntermediate(): CACertificate
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('type', $qb->createNamedParameter('intermediate')))
            ->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL)));

        return $this->findEntity($qb);
    }//end findActiveIntermediate()

    /**
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function findRoot(): CACertificate
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('type', $qb->createNamedParameter('root')))
            ->orderBy('created_at', 'DESC')
            ->setMaxResults(1);

        return $this->findEntity($qb);
    }//end findRoot()

    /**
     * Find certificates expiring within the given number of days.
     *
     * @return CACertificate[]
     */
    public function findExpiringSoon(int $days): array
    {
        $qb = $this->db->getQueryBuilder();
        $threshold = new \DateTime("+{$days} days");

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->lte('expires_at', $qb->createNamedParameter($threshold, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_DATE)))
            ->andWhere($qb->expr()->isNull('revoked_at'));

        return $this->findEntities($qb);
    }//end findExpiringSoon()
}//end class
