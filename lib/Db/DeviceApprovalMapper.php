<?php

/**
 * Keepiq DeviceApprovalMapper
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
 * Mapper for DeviceApproval entities.
 *
 * @extends QBMapper<DeviceApproval>
 */
class DeviceApprovalMapper extends QBMapper {

	/**
	 * Constructor for DeviceApprovalMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_device_approvals', entityClass: DeviceApproval::class);
	}//end __construct()

	/**
	 * Find a request by id.
	 *
	 * @param string $id The request id
	 *
	 * @return DeviceApproval
	 *
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function findById(string $id): DeviceApproval {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $this->findEntity(query: $qb);
	}//end findById()

	/**
	 * A user's pending requests that have not expired, newest first.
	 *
	 * @param string   $userId The user
	 * @param DateTime $now    The current time
	 *
	 * @return DeviceApproval[]
	 */
	public function findPendingForUser(string $userId, DateTime $now): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(DeviceApproval::STATUS_PENDING)))
			->andWhere($qb->expr()->gt('expires_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE)))
			->orderBy('created_at', 'DESC');

		return $this->findEntities(query: $qb);
	}//end findPendingForUser()

	/**
	 * Requests still pending or approved-but-unclaimed past their expiry.
	 *
	 * @param DateTime $now The current time
	 *
	 * @return DeviceApproval[]
	 */
	public function findLapsed(DateTime $now): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->in(
					'status',
					$qb->createNamedParameter(
						[DeviceApproval::STATUS_PENDING, DeviceApproval::STATUS_APPROVED],
						IQueryBuilder::PARAM_STR_ARRAY
					)
				)
			)
			->andWhere($qb->expr()->lte('expires_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE)));

		return $this->findEntities(query: $qb);
	}//end findLapsed()
}//end class
