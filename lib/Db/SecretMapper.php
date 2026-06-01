<?php

/**
 * Doriath Secret Mapper
 *
 * Database mapper for secret entities, including list, search and cascade helpers.
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
 * Mapper for Secret entities.
 *
 * @extends QBMapper<Secret>
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) A data mapper legitimately
 *   exposes one query/mutation method per access pattern (find, count,
 *   search, folder-cascade, type-reassign); splitting would scatter the
 *   single-table query surface.
 */
class SecretMapper extends QBMapper
{
    /**
     * The sortable column whitelist mapping API field → DB column.
     *
     * @var array<string,string>
     */
    private const SORTABLE = [
        'name'       => 'name',
        'url'        => 'url',
        'created_at' => 'created_at',
        'updated_at' => 'updated_at',
    ];

    /**
     * Constructor for SecretMapper.
     *
     * @param IDBConnection $db The database connection
     *
     * @return void
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(db: $db, tableName: 'doriath_secrets', entityClass: Secret::class);
    }//end __construct()

    /**
     * Find a secret by its ID.
     *
     * @param string $id The secret ID
     *
     * @return Secret
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function findById(string $id): Secret
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

        return $this->findEntity(query: $qb);
    }//end findById()

    /**
     * Resolve the safe sort column + direction.
     *
     * @param string|null $sort      The requested sort field
     * @param string|null $direction The requested direction (asc/desc)
     *
     * @return array{0:string,1:string} The column and uppercase direction
     */
    private function resolveSort(?string $sort, ?string $direction): array
    {
        $column = self::SORTABLE[$sort] ?? 'name';

        $dir = 'ASC';
        if (strtolower((string) $direction) === 'desc') {
            $dir = 'DESC';
        }

        return [$column, $dir];
    }//end resolveSort()

    /**
     * Find secrets for an owner with optional folder filter, sorting and paging.
     *
     * @param string      $ownerType The owner type
     * @param string      $ownerId   The owner ID
     * @param string|null $folderId  Optional folder filter (use FILTER_ROOT for root only)
     * @param string|null $sort      Sort field
     * @param string|null $direction Sort direction
     * @param int         $limit     Page size
     * @param int         $offset    Page offset
     *
     * @return Secret[]
     */
    public function findByOwner(
        string $ownerType,
        string $ownerId,
        ?string $folderId=null,
        ?string $sort=null,
        ?string $direction=null,
        int $limit=50,
        int $offset=0,
    ): array {
        [$column, $dir] = $this->resolveSort(sort: $sort, direction: $direction);

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('owner_type', $qb->createNamedParameter($ownerType)))
            ->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)));

        $this->applyFolderFilter(qb: $qb, folderId: $folderId);

        $qb->orderBy($column, $dir)
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        return $this->findEntities(query: $qb);
    }//end findByOwner()

    /**
     * Count secrets for an owner with optional folder filter.
     *
     * @param string      $ownerType The owner type
     * @param string      $ownerId   The owner ID
     * @param string|null $folderId  Optional folder filter
     *
     * @return int
     */
    public function countByOwner(string $ownerType, string $ownerId, ?string $folderId=null): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('owner_type', $qb->createNamedParameter($ownerType)))
            ->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)));

        $this->applyFolderFilter(qb: $qb, folderId: $folderId);

        $result = $qb->executeQuery();
        $count  = (int) $result->fetchOne();
        $result->closeCursor();
        return $count;
    }//end countByOwner()

    /**
     * Apply the folder filter to a query builder. A null value means "all
     * folders"; the FILTER_ROOT sentinel restricts to root-level secrets.
     *
     * @param IQueryBuilder $qb       The query builder
     * @param string|null   $folderId The folder filter
     *
     * @return void
     */
    private function applyFolderFilter(IQueryBuilder $qb, ?string $folderId): void
    {
        if ($folderId === null) {
            return;
        }

        if ($folderId === self::FILTER_ROOT) {
            $qb->andWhere($qb->expr()->isNull('folder_id'));
            return;
        }

        $qb->andWhere($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId)));
    }//end applyFolderFilter()

    /**
     * Sentinel that filters the listing to root-level (folder_id IS NULL) secrets.
     *
     * @var string
     */
    public const FILTER_ROOT = '__root__';

    /**
     * Find all secrets for an owner (no paging) — used by search and cascade.
     *
     * @param string $ownerType The owner type
     * @param string $ownerId   The owner ID
     *
     * @return Secret[]
     */
    public function findAllByOwner(string $ownerType, string $ownerId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('owner_type', $qb->createNamedParameter($ownerType)))
            ->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)));

        return $this->findEntities(query: $qb);
    }//end findAllByOwner()

    /**
     * SQL substring pre-filter on name or url for a user.
     *
     * @param string $ownerType The owner type
     * @param string $ownerId   The owner ID
     * @param string $term      The search term
     *
     * @return Secret[]
     */
    public function searchByNameOrUrl(string $ownerType, string $ownerId, string $term): array
    {
        $like = '%'.$this->db->escapeLikeParameter($term).'%';

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('owner_type', $qb->createNamedParameter($ownerType)))
            ->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)))
            ->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->iLike('name', $qb->createNamedParameter($like)),
                    $qb->expr()->iLike('url', $qb->createNamedParameter($like))
                )
            );

        return $this->findEntities(query: $qb);
    }//end searchByNameOrUrl()

    /**
     * Count the secrets directly in a folder.
     *
     * @param string $folderId The folder ID
     *
     * @return int
     */
    public function countByFolder(string $folderId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId)));

        $result = $qb->executeQuery();
        $count  = (int) $result->fetchOne();
        $result->closeCursor();
        return $count;
    }//end countByFolder()

    /**
     * Count the secrets within any of the given folder IDs.
     *
     * @param string[] $folderIds The folder IDs (typically a subtree)
     *
     * @return int
     */
    public function countByFolderIds(array $folderIds): int
    {
        if (empty($folderIds) === true) {
            return 0;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from($this->getTableName())
            ->where(
                $qb->expr()->in(
                    'folder_id',
                    $qb->createNamedParameter($folderIds, IQueryBuilder::PARAM_STR_ARRAY)
                )
            );

        $result = $qb->executeQuery();
        $count  = (int) $result->fetchOne();
        $result->closeCursor();
        return $count;
    }//end countByFolderIds()

    /**
     * Re-parent every secret currently in one of the given folders to a new folder.
     *
     * @param string[]    $folderIds   The source folder IDs
     * @param string|null $newFolderId The destination folder ID (null = root)
     *
     * @return void
     */
    public function moveSecretsToFolder(array $folderIds, ?string $newFolderId): void
    {
        if (empty($folderIds) === true) {
            return;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('folder_id', $qb->createNamedParameter($newFolderId))
            ->where(
                $qb->expr()->in(
                    'folder_id',
                    $qb->createNamedParameter($folderIds, IQueryBuilder::PARAM_STR_ARRAY)
                )
            );

        $qb->executeStatement();
    }//end moveSecretsToFolder()

    /**
     * Delete every secret within the given folder IDs.
     *
     * @param string[] $folderIds The folder IDs
     *
     * @return void
     */
    public function deleteByFolderIds(array $folderIds): void
    {
        if (empty($folderIds) === true) {
            return;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where(
                $qb->expr()->in(
                    'folder_id',
                    $qb->createNamedParameter($folderIds, IQueryBuilder::PARAM_STR_ARRAY)
                )
            );

        $qb->executeStatement();
    }//end deleteByFolderIds()

    /**
     * Reassign every secret of one type to another type.
     *
     * @param string $oldTypeId The type being removed
     * @param string $newTypeId The fallback type
     *
     * @return void
     */
    public function reassignType(string $oldTypeId, string $newTypeId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('type_id', $qb->createNamedParameter($newTypeId))
            ->where($qb->expr()->eq('type_id', $qb->createNamedParameter($oldTypeId)));

        $qb->executeStatement();
    }//end reassignType()
}//end class
