<?php

/**
 * Keepiq ShareSaveStageMapper
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
 * Mapper for ShareSaveStage chunks.
 *
 * @extends QBMapper<ShareSaveStage>
 *
 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-large-share-save-is-staged-in-chunks
 */
class ShareSaveStageMapper extends QBMapper {
	/**
	 * Constructor for ShareSaveStageMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_share_save_stages', entityClass: ShareSaveStage::class);
	}//end __construct()

	/**
	 * Every chunk of a stage that has not expired, oldest first.
	 *
	 * @param string   $stageId The stage
	 * @param DateTime $now     The current time
	 *
	 * @return ShareSaveStage[]
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-large-share-save-is-staged-in-chunks
	 */
	public function findLiveByStage(string $stageId, DateTime $now): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('stage_id', $qb->createNamedParameter($stageId)))
			->andWhere($qb->expr()->gt('expires_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE)))
			->orderBy('created_at', 'ASC');

		return $this->findEntities(query: $qb);
	}//end findLiveByStage()

	/**
	 * Remove every chunk of a stage.
	 *
	 * @param string $stageId The stage
	 *
	 * @return int The number of chunks removed
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-large-share-save-is-staged-in-chunks
	 */
	public function deleteByStage(string $stageId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('stage_id', $qb->createNamedParameter($stageId)));

		return $qb->executeStatement();
	}//end deleteByStage()

	/**
	 * Remove a user's stages for one row.
	 *
	 * @param string $userId   The user
	 * @param string $secretId The row
	 *
	 * @return int The number of chunks removed
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-large-share-save-is-staged-in-chunks
	 */
	public function deleteByUserAndSecret(string $userId, string $secretId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)));

		return $qb->executeStatement();
	}//end deleteByUserAndSecret()

	/**
	 * Remove every stage of a user.
	 *
	 * @param string $userId The user
	 *
	 * @return int The number of chunks removed
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-large-share-save-is-staged-in-chunks
	 */
	public function deleteByUser(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		return $qb->executeStatement();
	}//end deleteByUser()

	/**
	 * Remove every chunk whose stage expired.
	 *
	 * @param DateTime $now The current time
	 *
	 * @return int The number of chunks removed
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-large-share-save-is-staged-in-chunks
	 */
	public function deleteExpired(DateTime $now): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->lte('expires_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE)));

		return $qb->executeStatement();
	}//end deleteExpired()
}//end class
