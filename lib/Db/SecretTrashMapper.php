<?php

/**
 * Keepiq Secret Trash Mapper
 *
 * Reads the trash across all users for the daily purge
 * (vault-trash-and-archive D4). Same table and entity as SecretMapper.
 *
 * @category Db
 * @package  OCA\Keepiq\Db
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Db;

use DateTime;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Finds trashed secrets past a cutoff.
 *
 * @extends QBMapper<Secret>
 */
class SecretTrashMapper extends QBMapper {
	/**
	 * Constructor for SecretTrashMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_secrets', entityClass: Secret::class);
	}//end __construct()

	/**
	 * Find user-owned secrets trashed before a cutoff, oldest first.
	 *
	 * @param DateTime $cutoff Rows trashed before this instant
	 * @param int      $limit  Maximum rows per batch
	 *
	 * @return Secret[]
	 *
	 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-restoring-and-purging-trashed-secrets
	 */
	public function findTrashedBefore(DateTime $cutoff, int $limit = 500): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('owner_type', $qb->createNamedParameter('user')))
			->andWhere($qb->expr()->isNotNull('trashed_at'))
			->andWhere(
				$qb->expr()->lt('trashed_at', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_DATETIME_MUTABLE))
			)
			->orderBy('trashed_at', 'ASC')
			->setMaxResults(max(1, $limit));

		return $this->findEntities(query: $qb);
	}//end findTrashedBefore()
}//end class
