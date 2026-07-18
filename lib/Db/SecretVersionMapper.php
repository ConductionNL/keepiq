<?php

/**
 * Doriath Secret Version Mapper
 *
 * Query-builder mapper for SecretVersion rows: next-version-number,
 * newest-first listing, count/age pruning, and the delete cascade.
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

use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Mapper for the doriath_secret_versions table.
 *
 * @template-extends QBMapper<SecretVersion>
 */
class SecretVersionMapper extends QBMapper
{
    /**
     * Constructor for SecretVersionMapper.
     *
     * @param IDBConnection $db The database connection
     *
     * @return void
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(db: $db, tableName: 'doriath_secret_versions', entityClass: SecretVersion::class);
    }//end __construct()

    /**
     * Find a version by its UUID.
     *
     * @param string $id The version UUID
     *
     * @return SecretVersion
     *
     * @throws DoesNotExistException When no row matches
     */
    public function findById(string $id): SecretVersion
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

        return $this->findEntity(query: $qb);
    }//end findById()

    /**
     * List a secret's versions, newest first.
     *
     * @param string $secretId The secret UUID
     * @param int    $limit    Maximum rows
     *
     * @return SecretVersion[]
     */
    public function findBySecret(string $secretId, int $limit=200): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)))
            ->orderBy('version_number', 'DESC')
            ->setMaxResults(max(1, $limit));

        return $this->findEntities(query: $qb);
    }//end findBySecret()

    /**
     * The next version number for a secret (1-based, monotonic).
     *
     * @param string $secretId The secret UUID
     *
     * @return int
     */
    public function nextVersionNumber(string $secretId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->max('version_number'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)));

        $result = $qb->executeQuery();
        $max    = $result->fetchOne();
        $result->closeCursor();

        if ($max === false || $max === null) {
            return 1;
        }

        return ((int) $max) + 1;
    }//end nextVersionNumber()

    /**
     * Prune a secret's oldest versions beyond a retention count. The live
     * head is a `doriath_secrets` row and is structurally untouchable here.
     *
     * @param string $secretId The secret UUID
     * @param int    $keep     Versions to keep (newest)
     *
     * @return int Rows pruned
     */
    public function pruneByCount(string $secretId, int $keep): int
    {
        $victims = [];
        foreach ($this->findBySecret(secretId: $secretId, limit: 100000) as $index => $version) {
            if ($index >= $keep) {
                $victims[] = $version;
            }
        }

        foreach ($victims as $version) {
            $this->delete(entity: $version);
        }

        return count($victims);
    }//end pruneByCount()

    /**
     * Prune versions older than a cutoff, in a bounded batch across all
     * secrets.
     *
     * @param DateTime $cutoff The age cutoff
     * @param int      $limit  Maximum rows to prune in one call
     *
     * @return int Rows pruned
     */
    public function pruneOlderThan(DateTime $cutoff, int $limit=500): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->lt('created_at', $qb->createNamedParameter($cutoff, 'datetime')))
            ->setMaxResults(max(1, $limit));

        $pruned = 0;
        foreach ($this->findEntities(query: $qb) as $version) {
            $this->delete(entity: $version);
            ++$pruned;
        }

        return $pruned;
    }//end pruneOlderThan()

    /**
     * Delete every version of a secret (delete cascade; idempotent).
     *
     * @param string $secretId The secret UUID
     *
     * @return void
     */
    public function deleteBySecret(string $secretId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)));
        $qb->executeStatement();
    }//end deleteBySecret()

    /**
     * The distinct secret ids that currently have versions (prune sweep).
     *
     * @param int $limit Maximum ids
     *
     * @return string[]
     */
    public function findSecretIdsWithVersions(int $limit=1000): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('secret_id')
            ->from($this->getTableName())
            ->setMaxResults(max(1, $limit));

        $result = $qb->executeQuery();
        $ids    = array_column($result->fetchAll(), 'secret_id');
        $result->closeCursor();

        return $ids;
    }//end findSecretIdsWithVersions()
}//end class
