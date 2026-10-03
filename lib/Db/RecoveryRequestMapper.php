<?php

/**
 * Keepiq RecoveryRequestMapper
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
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for RecoveryRequest entities.
 *
 * @extends QBMapper<RecoveryRequest>
 */
class RecoveryRequestMapper extends QBMapper {

	/**
	 * Constructor for RecoveryRequestMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_recovery_requests', entityClass: RecoveryRequest::class);
	}//end __construct()

	/**
	 * Find a row by id.
	 *
	 * @param string $id The id
	 *
	 * @return RecoveryRequest
	 *
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function findById(string $id): RecoveryRequest {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $this->findEntity(query: $qb);
	}//end findById()

	/**
	 * Requests in any of the given statuses, oldest first.
	 *
	 * @param array $statuses The statuses
	 *
	 * @return RecoveryRequest[]
	 */
	public function findByStatuses(array $statuses): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('status', $qb->createNamedParameter($statuses, IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('created_at', 'ASC');

		return $this->findEntities(query: $qb);
	}//end findByStatuses()

	/**
	 * A user's requests, newest first.
	 *
	 * @param string $userId The user
	 *
	 * @return RecoveryRequest[]
	 */
	public function findByUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('created_at', 'DESC');

		return $this->findEntities(query: $qb);
	}//end findByUser()

	/**
	 * The requests of one suite.
	 *
	 * @param string $suiteId The suite
	 *
	 * @return RecoveryRequest[]
	 */
	public function findBySuite(string $suiteId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('suite_id', $qb->createNamedParameter($suiteId)));

		return $this->findEntities(query: $qb);
	}//end findBySuite()

	/**
	 * Open requests past their expiry.
	 *
	 * @param array $statuses The open statuses
	 * @param DateTime $now The current time
	 *
	 * @return RecoveryRequest[]
	 */
	public function findLapsed(array $statuses, DateTime $now): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('status', $qb->createNamedParameter($statuses, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->lte('expires_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE)));

		return $this->findEntities(query: $qb);
	}//end findLapsed()
}//end class
