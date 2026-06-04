<?php

/**
 * Doriath Application Mapper
 *
 * Database mapper for registered application entities.
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
 * Mapper for Application entities.
 *
 * @extends QBMapper<Application>
 */
class ApplicationMapper extends QBMapper
{
    /**
     * Constructor for ApplicationMapper.
     *
     * @param IDBConnection $db The database connection
     *
     * @return void
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(db: $db, tableName: 'doriath_applications', entityClass: Application::class);
    }//end __construct()

    /**
     * Find an application by its ID.
     *
     * @param string $id The application ID
     *
     * @return Application
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function findById(string $id): Application
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

        return $this->findEntity(query: $qb);
    }//end findById()

    /**
     * Find applications with optional status filtering, sorting and paging.
     *
     * @param array<string,string> $filters Optional filters; supported key: 'status'
     * @param string               $sort    Sort column (default 'created_at')
     * @param string               $order   Sort direction ('ASC' or 'DESC')
     * @param int|null             $limit   Maximum number of results
     * @param int|null             $offset  Result offset for pagination
     *
     * @return Application[]
     */
    public function findAll(
        array $filters=[],
        string $sort='created_at',
        string $order='DESC',
        ?int $limit=null,
        ?int $offset=null,
    ): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName());

        $this->applyFilters(qb: $qb, filters: $filters);

        $sortColumn = $this->sanitizeSortColumn(sort: $sort);
        $sortOrder  = 'DESC';
        if (strtoupper($order) === 'ASC') {
            $sortOrder = 'ASC';
        }

        $qb->orderBy($sortColumn, $sortOrder);

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        return $this->findEntities(query: $qb);
    }//end findAll()

    /**
     * Count applications matching the optional filters.
     *
     * @param array<string,string> $filters Optional filters; supported key: 'status'
     *
     * @return int
     */
    public function countAll(array $filters=[]): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))->from($this->getTableName());

        $this->applyFilters(qb: $qb, filters: $filters);

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        return (int) ($row['cnt'] ?? 0);
    }//end countAll()

    /**
     * Find all pending applications, oldest first.
     *
     * @return Application[]
     */
    public function findPending(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter('pending')))
            ->orderBy('created_at', 'ASC');

        return $this->findEntities(query: $qb);
    }//end findPending()

    /**
     * Count pending applications (for the dashboard summary).
     *
     * @return int
     */
    public function countPending(): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter('pending')));

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        return (int) ($row['cnt'] ?? 0);
    }//end countPending()

    /**
     * Find all applications registered by a given Nextcloud user.
     *
     * @param string $userId The registrant's Nextcloud user ID
     *
     * @return Application[]
     */
    public function findByRegistrant(string $userId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('registered_by', $qb->createNamedParameter($userId)))
            ->orderBy('created_at', 'DESC');

        return $this->findEntities(query: $qb);
    }//end findByRegistrant()

    /**
     * Find an active application by its name.
     *
     * @param string $name The application name
     *
     * @return Application
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function findActiveByName(string $name): Application
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('name', $qb->createNamedParameter($name)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('active')));

        return $this->findEntity(query: $qb);
    }//end findActiveByName()

    /**
     * Apply supported filters to a query builder.
     *
     * @param IQueryBuilder        $qb      The query builder
     * @param array<string,string> $filters The filters to apply
     *
     * @return void
     */
    private function applyFilters(IQueryBuilder $qb, array $filters): void
    {
        if (isset($filters['status']) === true && $filters['status'] !== '') {
            $qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($filters['status'])));
        }

        if (isset($filters['type']) === true && $filters['type'] !== '') {
            $qb->andWhere($qb->expr()->eq('type', $qb->createNamedParameter($filters['type'])));
        }
    }//end applyFilters()

    /**
     * Whitelist the sort column to prevent SQL injection via the sort param.
     *
     * @param string $sort The requested sort column
     *
     * @return string A safe column name
     */
    private function sanitizeSortColumn(string $sort): string
    {
        $allowed = ['created_at', 'approved_at', 'name', 'status', 'type'];
        if (in_array($sort, $allowed, true) === true) {
            return $sort;
        }

        return 'created_at';
    }//end sanitizeSortColumn()
}//end class
