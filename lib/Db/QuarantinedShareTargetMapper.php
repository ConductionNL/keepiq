<?php

/**
 * Keepiq Quarantined Share Target Mapper
 *
 * Reads the `keepiq_share_targets` rows the GHSA-m5p9-6pw7-pf3g scan
 * quarantined, which ShareTargetMapper and BulkGrantShareTargetMapper never
 * return.
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
 * The quarantined share rows, kept apart from the live ones so no runtime
 * path can reach them by accident: only the admin command, the refusal of an
 * owner's revoke and the GDPR export read them.
 *
 * @extends QBMapper<ShareTarget>
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
 */
class QuarantinedShareTargetMapper extends QBMapper {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_share_targets', entityClass: ShareTarget::class);
	}//end __construct()

	/**
	 * Every quarantined share target, oldest quarantine first.
	 *
	 * @return ShareTarget[]
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
	 */
	public function findQuarantined(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->isNotNull('quarantined_at'))
			->orderBy('quarantined_at', 'ASC')
			->addOrderBy('id', 'ASC');

		return $this->findEntities(query: $qb);
	}//end findQuarantined()

	/**
	 * One quarantined share target by its UUID.
	 *
	 * @param string $id The share target ID
	 *
	 * @return ShareTarget
	 *
	 * @throws DoesNotExistException When no quarantined row has this id
	 * @throws MultipleObjectsReturnedException
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
	 */
	public function findQuarantinedById(string $id): ShareTarget {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->isNotNull('quarantined_at'));

		return $this->findEntity(query: $qb);
	}//end findQuarantinedById()

	/**
	 * The quarantined share targets of one source secret, for the GDPR export.
	 *
	 * @param string $sourceSecretId The source secret
	 *
	 * @return ShareTarget[]
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
	 */
	public function findQuarantinedBySourceSecret(string $sourceSecretId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('source_secret_id', $qb->createNamedParameter($sourceSecretId)))
			->andWhere($qb->expr()->isNotNull('quarantined_at'));

		return $this->findEntities(query: $qb);
	}//end findQuarantinedBySourceSecret()

	/**
	 * The quarantined share targets naming one recipient, for the GDPR export.
	 *
	 * @param string $targetUserId The recipient
	 *
	 * @return ShareTarget[]
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
	 */
	public function findQuarantinedByTargetUser(string $targetUserId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('target_user_id', $qb->createNamedParameter($targetUserId)))
			->andWhere($qb->expr()->isNotNull('quarantined_at'));

		return $this->findEntities(query: $qb);
	}//end findQuarantinedByTargetUser()
}//end class
