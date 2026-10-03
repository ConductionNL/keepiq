<?php

/**
 * Keepiq RecoveryKeyMapper
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
 * Mapper for RecoveryKey entities.
 *
 * @extends QBMapper<RecoveryKey>
 */
class RecoveryKeyMapper extends QBMapper {

	/**
	 * Constructor for RecoveryKeyMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_recovery_keys', entityClass: RecoveryKey::class);
	}//end __construct()

	/**
	 * Find a row by id.
	 *
	 * @param string $id The id
	 *
	 * @return RecoveryKey
	 *
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function findById(string $id): RecoveryKey {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $this->findEntity(query: $qb);
	}//end findById()

	/**
	 * Recovery keys in one status, newest first.
	 *
	 * @param string $status The status
	 *
	 * @return RecoveryKey[]
	 */
	public function findByStatus(string $status): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter($status)))
			->orderBy('created_at', 'DESC');

		return $this->findEntities(query: $qb);
	}//end findByStatus()
}//end class
