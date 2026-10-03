<?php

/**
 * Keepiq RecoveryApprovalMapper
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

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Mapper for RecoveryApproval entities.
 *
 * @extends QBMapper<RecoveryApproval>
 */
class RecoveryApprovalMapper extends QBMapper {

	/**
	 * Constructor for RecoveryApprovalMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_recovery_approvals', entityClass: RecoveryApproval::class);
	}//end __construct()

	/**
	 * Find a row by id.
	 *
	 * @param string $id The id
	 *
	 * @return RecoveryApproval
	 *
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function findById(string $id): RecoveryApproval {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $this->findEntity(query: $qb);
	}//end findById()

	/**
	 * The decisions on one request.
	 *
	 * @param string $requestId The request
	 *
	 * @return RecoveryApproval[]
	 */
	public function findByRequest(string $requestId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('request_id', $qb->createNamedParameter($requestId)));

		return $this->findEntities(query: $qb);
	}//end findByRequest()
}//end class
