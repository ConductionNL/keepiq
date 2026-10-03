<?php

/**
 * Keepiq RecoveryOfficerMapper
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
 * Mapper for RecoveryOfficer entities.
 *
 * @extends QBMapper<RecoveryOfficer>
 */
class RecoveryOfficerMapper extends QBMapper {

	/**
	 * Constructor for RecoveryOfficerMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_recovery_officers', entityClass: RecoveryOfficer::class);
	}//end __construct()

	/**
	 * Find a row by id.
	 *
	 * @param string $id The id
	 *
	 * @return RecoveryOfficer
	 *
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function findById(string $id): RecoveryOfficer {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $this->findEntity(query: $qb);
	}//end findById()

	/**
	 * Every officer copy of one recovery key.
	 *
	 * @param string $recoveryKeyId The recovery key
	 *
	 * @return RecoveryOfficer[]
	 */
	public function findByKey(string $recoveryKeyId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('recovery_key_id', $qb->createNamedParameter($recoveryKeyId)));

		return $this->findEntities(query: $qb);
	}//end findByKey()

	/**
	 * One officer's copy of one recovery key.
	 *
	 * @param string $recoveryKeyId The recovery key
	 * @param string $officerUid The officer
	 *
	 * @return RecoveryOfficer
	 *
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function findForKeyAndOfficer(string $recoveryKeyId, string $officerUid): RecoveryOfficer {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('recovery_key_id', $qb->createNamedParameter($recoveryKeyId)))
			->andWhere($qb->expr()->eq('officer_uid', $qb->createNamedParameter($officerUid)));

		return $this->findEntity(query: $qb);
	}//end findForKeyAndOfficer()

	/**
	 * Every copy one officer holds.
	 *
	 * @param string $officerUid The officer
	 *
	 * @return RecoveryOfficer[]
	 */
	public function findByOfficer(string $officerUid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('officer_uid', $qb->createNamedParameter($officerUid)));

		return $this->findEntities(query: $qb);
	}//end findByOfficer()

	/**
	 * Delete every copy one officer holds.
	 *
	 * @param string $officerUid The officer
	 *
	 * @return void
	 */
	public function deleteByOfficer(string $officerUid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('officer_uid', $qb->createNamedParameter($officerUid)));

		$qb->executeStatement();
	}//end deleteByOfficer()
}//end class
