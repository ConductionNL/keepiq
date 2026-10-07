<?php

/**
 * Keepiq Emergency Contact Mapper
 *
 * Database mapper for the break-glass emergency-access relationships
 * (add-emergency-access). Provides the lookups the lifecycle service and the
 * approval background job need: by grantor, by grantee, by the (grantor,
 * grantee) pair, elapsed pending requests, and by grantor/grantee suite id for
 * key-change invalidation.
 *
 * @category Db
 * @package  OCA\Keepiq\Db
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

namespace OCA\Keepiq\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for EmergencyContact entities.
 *
 * @extends QBMapper<EmergencyContact>
 */
class EmergencyContactMapper extends QBMapper {
	/**
	 * Constructor for EmergencyContactMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_emergency_contacts', entityClass: EmergencyContact::class);
	}//end __construct()

	/**
	 * Find an emergency-contact relationship by its UUID.
	 *
	 * @param string $id The relationship ID
	 *
	 * @return EmergencyContact
	 *
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function findById(string $id): EmergencyContact {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $this->findEntity(query: $qb);
	}//end findById()

	/**
	 * List all emergency contacts a grantor has designated.
	 *
	 * @param string $grantorUserId The grantor Nextcloud user ID
	 *
	 * @return EmergencyContact[]
	 */
	public function findByGrantor(string $grantorUserId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('grantor_user_id', $qb->createNamedParameter($grantorUserId)))
			->orderBy('created_at', 'DESC');

		return $this->findEntities(query: $qb);
	}//end findByGrantor()

	/**
	 * Delete every relationship the user is part of, as grantor or as grantee
	 * (GDPR Art. 17 erasure). A grantor row holds the user's private key
	 * escrowed to the grantee, so it must not survive the erasure.
	 *
	 * @param string $userId The Nextcloud user ID
	 *
	 * @return int The number of rows deleted
	 *
	 * @spec openspec/specs/gdpr-compliance/spec.md
	 */
	public function deleteByUser(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where(
				$qb->expr()->orX(
					$qb->expr()->eq('grantor_user_id', $qb->createNamedParameter($userId)),
					$qb->expr()->eq('grantee_user_id', $qb->createNamedParameter($userId))
				)
			);

		return $qb->executeStatement();
	}//end deleteByUser()

	/**
	 * List all relationships where the user is the grantee (incoming access).
	 *
	 * @param string $granteeUserId The grantee Nextcloud user ID
	 *
	 * @return EmergencyContact[]
	 */
	public function findByGrantee(string $granteeUserId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('grantee_user_id', $qb->createNamedParameter($granteeUserId)))
			->orderBy('created_at', 'DESC');

		return $this->findEntities(query: $qb);
	}//end findByGrantee()

	/**
	 * Find the single relationship for a (grantor, grantee) pair.
	 *
	 * @param string $grantorUserId The grantor Nextcloud user ID
	 * @param string $granteeUserId The grantee Nextcloud user ID
	 *
	 * @return EmergencyContact
	 *
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function findByGrantorAndGrantee(string $grantorUserId, string $granteeUserId): EmergencyContact {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('grantor_user_id', $qb->createNamedParameter($grantorUserId)))
			->andWhere($qb->expr()->eq('grantee_user_id', $qb->createNamedParameter($granteeUserId)));

		return $this->findEntity(query: $qb);
	}//end findByGrantorAndGrantee()

	/**
	 * Find all relationships in the `requested` state (for the approval job).
	 *
	 * @return EmergencyContact[]
	 */
	public function findAllRequested(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter(EmergencyContact::STATE_REQUESTED)));

		return $this->findEntities(query: $qb);
	}//end findAllRequested()

	/**
	 * Find all relationships whose recovery envelope escrows a given suite (the
	 * grantor's suite at designation) — used to invalidate on rotation/revoke.
	 *
	 * @param string $grantorSuiteId The grantor's EncryptionSuite ID
	 *
	 * @return EmergencyContact[]
	 */
	public function findByGrantorSuite(string $grantorSuiteId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('grantor_suite_id', $qb->createNamedParameter($grantorSuiteId)));

		return $this->findEntities(query: $qb);
	}//end findByGrantorSuite()

	/**
	 * Find all relationships whose envelope is encrypted to a given grantee
	 * suite — used to invalidate when that grantee's suite is revoked.
	 *
	 * @param string $granteeSuiteId The grantee's EncryptionSuite ID
	 *
	 * @return EmergencyContact[]
	 */
	public function findByGranteeSuite(string $granteeSuiteId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('grantee_suite_id', $qb->createNamedParameter($granteeSuiteId)));

		return $this->findEntities(query: $qb);
	}//end findByGranteeSuite()

	/**
	 * The users among the given ones who have set up an emergency contact
	 * that is in force (granted, accepted or active), in one query.
	 *
	 * @param string[] $userIds The user IDs to check
	 *
	 * @return string[] The grantor user IDs that have a contact in force
	 *
	 * @spec openspec/specs/admin-member-overview/spec.md#requirement-administrator-lists-vault-status-per-user
	 */
	public function grantorsWithContact(array $userIds): array {
		if ($userIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('grantor_user_id')
			->from($this->getTableName())
			->where(
				$qb->expr()->in('grantor_user_id', $qb->createNamedParameter($userIds, IQueryBuilder::PARAM_STR_ARRAY))
			)
			->andWhere(
				$qb->expr()->in(
					'state',
					$qb->createNamedParameter(['granted', 'accepted', 'active'], IQueryBuilder::PARAM_STR_ARRAY)
				)
			);

		$grantors = [];
		$result = $qb->executeQuery();
		while (($row = $result->fetch()) !== false) {
			$grantors[] = (string)$row['grantor_user_id'];
		}

		$result->closeCursor();

		return $grantors;
	}//end grantorsWithContact()
}//end class
