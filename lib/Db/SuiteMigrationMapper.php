<?php

declare(strict_types=1);

namespace OCA\Doriath\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<SuiteMigration>
 */
class SuiteMigrationMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'doriath_suite_migrations', SuiteMigration::class);
    }//end __construct()

    /**
     * Find an in-progress migration for a given owner by checking suites.
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function findInProgressByOldSuiteId(string $oldSuiteId): SuiteMigration
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('old_suite_id', $qb->createNamedParameter($oldSuiteId)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('in_progress')));

        return $this->findEntity($qb);
    }//end findInProgressByOldSuiteId()

    /**
     * @return SuiteMigration[]
     */
    public function findBySuiteId(string $suiteId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->orX(
                    $qb->expr()->eq('old_suite_id', $qb->createNamedParameter($suiteId)),
                    $qb->expr()->eq('new_suite_id', $qb->createNamedParameter($suiteId))
                )
            );

        return $this->findEntities($qb);
    }//end findBySuiteId()

    /**
     * Check if any in-progress migration exists for the given suite IDs.
     */
    public function hasInProgress(string $oldSuiteId): bool
    {
        try {
            $this->findInProgressByOldSuiteId($oldSuiteId);
            return true;
        } catch (DoesNotExistException) {
            return false;
        }
    }//end hasInProgress()
}//end class
