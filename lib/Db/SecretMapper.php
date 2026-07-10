<?php

/**
 * Doriath Secret Mapper
 *
 * Database mapper for secret entities, including filtered/sorted/paginated
 * listing, plaintext-metadata search, and folder-cascade helpers.
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
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) Each method is a single,
 *   focused query the service layer composes (find/count/search/cascade);
 *   splitting the mapper would scatter the secrets table's access in one
 *   place across several classes for no benefit.
 */
class SecretMapper extends QBMapper
{
    /**
     * The columns a list may be sorted by (allow-list to prevent injection).
     *
     * @var string[]
     */
    private const SORTABLE_COLUMNS = [
        'name',
        'url',
        'created_at',
        'updated_at',
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
     * Find a secret by its UUID.
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
     * Resolve a sort column from the allow-list, defaulting to name.
     *
     * @param string|null $sort The requested sort column
     *
     * @return string A safe column name
     */
    private function resolveSortColumn(?string $sort): string
    {
        if ($sort !== null && in_array($sort, self::SORTABLE_COLUMNS, true) === true) {
            return $sort;
        }

        return 'name';
    }//end resolveSortColumn()

    /**
     * Find secrets owned by an owner, with optional folder filter, sort, and
     * pagination.
     *
     * @param string      $ownerType The owner type
     * @param string      $ownerId   The owner ID
     * @param string|null $folderId  Filter by folder ID (null = no folder filter)
     * @param string|null $sort      Sort column (name, url, created_at, updated_at)
     * @param string      $direction Sort direction (asc/desc)
     * @param int         $limit     Maximum rows
     * @param int         $offset    Row offset
     *
     * @return Secret[]
     */
    public function findByOwner(
        string $ownerType,
        string $ownerId,
        ?string $folderId=null,
        ?string $sort=null,
        string $direction='asc',
        int $limit=1000,
        int $offset=0,
    ): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('owner_type', $qb->createNamedParameter($ownerType)))
            ->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)));

        if ($folderId !== null) {
            $qb->andWhere($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId)));
        }

        $dir = 'ASC';
        if (strtolower($direction) === 'desc') {
            $dir = 'DESC';
        }

        $qb->orderBy($this->resolveSortColumn(sort: $sort), $dir)
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        return $this->findEntities(query: $qb);
    }//end findByOwner()

    /**
     * Count the secrets owned by an owner, with an optional folder filter.
     *
     * @param string      $ownerType The owner type
     * @param string      $ownerId   The owner ID
     * @param string|null $folderId  Filter by folder ID (null = no folder filter)
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

        if ($folderId !== null) {
            $qb->andWhere($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId)));
        }

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        return (int) ($row['cnt'] ?? 0);
    }//end countByOwner()

    /**
     * Count the secrets directly contained in a folder.
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
        $row    = $result->fetch();
        $result->closeCursor();

        return (int) ($row['cnt'] ?? 0);
    }//end countByFolder()

    /**
     * Count the secrets contained in any of the given folder IDs (used for
     * recursive subtree counts where the subtree IDs are pre-collected).
     *
     * @param string[] $folderIds The folder IDs
     *
     * @return int
     */
    public function countByFolderIds(array $folderIds): int
    {
        if ($folderIds === []) {
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
        $row    = $result->fetch();
        $result->closeCursor();

        return (int) ($row['cnt'] ?? 0);
    }//end countByFolderIds()

    /**
     * Search a user's secrets by plaintext name or url substring match.
     *
     * Performs the SQL pre-filter stage of the two-stage fuzzy search. The
     * Levenshtein post-filter is applied by the service over a bounded
     * window. The result set is capped by `$limit` so this stage itself
     * cannot return an unbounded row set regardless of vault size.
     *
     * @param string $ownerType The owner type
     * @param string $ownerId   The owner ID
     * @param string $term      The search term
     * @param int    $limit     The maximum number of rows to return
     *
     * @return Secret[]
     */
    public function searchByNameOrUrl(string $ownerType, string $ownerId, string $term, int $limit=200): array
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
            )
            ->setMaxResults(max(1, $limit));

        return $this->findEntities(query: $qb);
    }//end searchByNameOrUrl()

    /**
     * Find secrets whose name or url matches a query, for the Nextcloud
     * unified search provider. Scoped to a user, capped at a small limit.
     *
     * @param string $userId The Nextcloud user ID
     * @param string $term   The search term
     * @param int    $limit  Maximum rows
     *
     * @return Secret[]
     */
    public function findForUnifiedSearch(string $userId, string $term, int $limit): array
    {
        $like = '%'.$this->db->escapeLikeParameter($term).'%';

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('owner_type', $qb->createNamedParameter('user')))
            ->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($userId)))
            ->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->iLike('name', $qb->createNamedParameter($like)),
                    $qb->expr()->iLike('url', $qb->createNamedParameter($like))
                )
            )
            ->orderBy('name', 'ASC')
            ->setMaxResults($limit);

        return $this->findEntities(query: $qb);
    }//end findForUnifiedSearch()

    /**
     * Re-parent every secret in a folder to a new folder (or root when null).
     *
     * @param string      $oldFolderId The current folder ID
     * @param string|null $newFolderId The target folder ID (null = root)
     *
     * @return void
     */
    public function updateFolderForSecrets(string $oldFolderId, ?string $newFolderId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('folder_id', $qb->createNamedParameter($newFolderId))
            ->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($oldFolderId)));

        $qb->executeStatement();
    }//end updateFolderForSecrets()

    /**
     * Delete every secret directly contained in a folder.
     *
     * @param string $folderId The folder ID
     *
     * @return void
     */
    public function deleteByFolderId(string $folderId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId)));

        $qb->executeStatement();
    }//end deleteByFolderId()

    /**
     * Reassign every secret of a given type to a different type (used when a
     * custom SecretType is deleted and its secrets fall back to login).
     *
     * @param string $oldTypeId The type ID being removed
     * @param string $newTypeId The fallback type ID
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

    /**
     * Find every Secret encrypted under a given EncryptionSuite. Used by
     * compromise-recovery listeners to fan over the freshly re-suited
     * copies and surface the ones the migration flagged as possibly
     * compromised.
     *
     * @param string $encryptionSuiteId The suite ID
     *
     * @return Secret[]
     *
     * @spec openspec/changes/implement-user-sharing/tasks.md#8.4
     */
    public function findByEncryptionSuiteId(string $encryptionSuiteId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq(
                    'encryption_suite_id',
                    $qb->createNamedParameter($encryptionSuiteId)
                )
            );

        return $this->findEntities(query: $qb);
    }//end findByEncryptionSuiteId()
}//end class
