<?php

/**
 * Doriath Suite Migration Mapper
 *
 * Database mapper for suite migration entities.
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
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for SuiteMigration entities.
 *
 * @extends QBMapper<SuiteMigration>
 */
class SuiteMigrationMapper extends QBMapper
{
    /**
     * Constructor for SuiteMigrationMapper.
     *
     * @param IDBConnection $db The database connection
     *
     * @return void
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(db: $db, tableName: 'doriath_suite_migr', entityClass: SuiteMigration::class);
    }//end __construct()

    /**
     * Find a suite migration by its ID.
     *
     * @param string $id The migration ID
     *
     * @return SuiteMigration
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function findById(string $id): SuiteMigration
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

        return $this->findEntity(query: $qb);
    }//end findById()

    /**
     * Find an in-progress migration for a given owner by checking suites.
     *
     * @param string $oldSuiteId The old suite ID to find migration for
     *
     * @return SuiteMigration
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

        return $this->findEntity(query: $qb);
    }//end findInProgressByOldSuiteId()

    /**
     * Find all migrations related to a suite ID.
     *
     * @param string $suiteId The suite ID to search for
     *
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

        return $this->findEntities(query: $qb);
    }//end findBySuiteId()

    /**
     * Check if any in-progress migration exists for the given suite IDs.
     *
     * @param string $oldSuiteId The old suite ID to check
     *
     * @return bool
     */
    public function hasInProgress(string $oldSuiteId): bool
    {
        try {
            $this->findInProgressByOldSuiteId(oldSuiteId: $oldSuiteId);
            return true;
        } catch (DoesNotExistException) {
            return false;
        }
    }//end hasInProgress()

    /**
     * Find the first in-progress migration whose old suite is one of the
     * given suite IDs.
     *
     * Used by the dashboard summary to surface a "migration in progress"
     * banner for the suites a user owns. Returns null when no in-progress
     * migration touches any of the supplied suites (including the empty
     * input case).
     *
     * @param string[] $suiteIds The suite IDs to scope the search to.
     *
     * @return SuiteMigration|null The in-progress migration, or null.
     */
    public function findInProgressBySuiteIds(array $suiteIds): ?SuiteMigration
    {
        if (empty($suiteIds) === true) {
            return null;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in('old_suite_id', $qb->createNamedParameter($suiteIds, IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('in_progress')))
            ->setMaxResults(1);

        try {
            return $this->findEntity(query: $qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }//end findInProgressBySuiteIds()
}//end class
