<?php

/**
 * Keepiq Access Request Mapper
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
 * Mapper for the keepiq_access_requests table.
 *
 * @template-extends QBMapper<AccessRequest>
 */
class AccessRequestMapper extends QBMapper {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_access_requests', entityClass: AccessRequest::class);
	}//end __construct()

	/**
	 * One request by id.
	 *
	 * @param string $id The request id
	 *
	 * @return AccessRequest
	 *
	 * @throws DoesNotExistException When there is no such request
	 * @throws MultipleObjectsReturnedException When the id is not unique
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
	 */
	public function findById(string $id): AccessRequest {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $this->findEntity(query: $qb);
	}//end findById()

	/**
	 * The requests of one secret, newest first; of one requester when given.
	 *
	 * @param string      $secretId    The source secret id
	 * @param string|null $requesterId Only this requester's, or all
	 *
	 * @return AccessRequest[]
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
	 */
	public function findBySecret(string $secretId, ?string $requesterId = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)));
		if ($requesterId !== null) {
			$qb->andWhere($qb->expr()->eq('requester_id', $qb->createNamedParameter($requesterId)));
		}

		$qb->orderBy('created_at', 'DESC');

		return $this->findEntities(query: $qb);
	}//end findBySecret()

	/**
	 * The approved requests of one requester on one secret.
	 *
	 * @param string $secretId    The source secret id
	 * @param string $requesterId The recipient
	 *
	 * @return AccessRequest[]
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
	 */
	public function findApproved(string $secretId, string $requesterId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)))
			->andWhere($qb->expr()->eq('requester_id', $qb->createNamedParameter($requesterId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(AccessRequest::STATUS_APPROVED)));

		return $this->findEntities(query: $qb);
	}//end findApproved()

	/**
	 * The requests waiting for an owner's answer, newest first.
	 *
	 * @param string $ownerId The owner
	 *
	 * @return AccessRequest[]
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
	 */
	public function findPendingForOwner(string $ownerId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(AccessRequest::STATUS_PENDING)))
			->orderBy('created_at', 'DESC');

		return $this->findEntities(query: $qb);
	}//end findPendingForOwner()

	/**
	 * Remove every request of a secret.
	 *
	 * @param string $secretId The source secret id
	 *
	 * @return int The rows removed
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
	 */
	public function deleteBySecret(string $secretId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)));

		return $qb->executeStatement();
	}//end deleteBySecret()

	/**
	 * Remove every request a departing user filed or was asked to answer.
	 *
	 * @param string $userId The user
	 *
	 * @return int The rows removed
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
	 */
	public function deleteByUser(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->orX(
				$qb->expr()->eq('owner_id', $qb->createNamedParameter($userId)),
				$qb->expr()->eq('requester_id', $qb->createNamedParameter($userId))
			));

		return $qb->executeStatement();
	}//end deleteByUser()
}//end class
