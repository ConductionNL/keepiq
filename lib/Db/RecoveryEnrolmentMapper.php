<?php

/**
 * Keepiq RecoveryEnrolmentMapper
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
 * Mapper for RecoveryEnrolment entities.
 *
 * @extends QBMapper<RecoveryEnrolment>
 */
class RecoveryEnrolmentMapper extends QBMapper {

	/**
	 * Constructor for RecoveryEnrolmentMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_recovery_enrolments', entityClass: RecoveryEnrolment::class);
	}//end __construct()

	/**
	 * Find a row by id.
	 *
	 * @param string $id The id
	 *
	 * @return RecoveryEnrolment
	 *
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function findById(string $id): RecoveryEnrolment {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $this->findEntity(query: $qb);
	}//end findById()

	/**
	 * A user's enrolments, newest first.
	 *
	 * @param string $userId The user
	 *
	 * @return RecoveryEnrolment[]
	 */
	public function findByUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('enrolled_at', 'DESC');

		return $this->findEntities(query: $qb);
	}//end findByUser()

	/**
	 * The enrolments of one suite.
	 *
	 * @param string $suiteId The suite
	 *
	 * @return RecoveryEnrolment[]
	 */
	public function findBySuite(string $suiteId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('suite_id', $qb->createNamedParameter($suiteId)));

		return $this->findEntities(query: $qb);
	}//end findBySuite()

	/**
	 * The enrolments wrapped to one recovery key.
	 *
	 * @param string $recoveryKeyId The recovery key
	 *
	 * @return RecoveryEnrolment[]
	 */
	public function findByKey(string $recoveryKeyId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('recovery_key_id', $qb->createNamedParameter($recoveryKeyId)));

		return $this->findEntities(query: $qb);
	}//end findByKey()
}//end class
