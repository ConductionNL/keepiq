<?php

/**
 * Keepiq RecoveryKitMapper
 *
 * @category Db
 * @package  OCA\Keepiq\Db
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md
 */

declare(strict_types=1);

namespace OCA\Keepiq\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Reads and writes recovery kits, always by owner.
 *
 * @extends QBMapper<RecoveryKit>
 *
 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#requirement-recovery-is-limited-to-the-owner-and-leaves-a-trail
 */
class RecoveryKitMapper extends QBMapper {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database.
	 *
	 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#requirement-recovery-is-limited-to-the-owner-and-leaves-a-trail
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_recovery_kits', entityClass: RecoveryKit::class);
	}//end __construct()

	/**
	 * The user's kits, at most one per suite.
	 *
	 * @param string $userId The owner.
	 *
	 * @return RecoveryKit[]
	 *
	 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#requirement-recovery-is-limited-to-the-owner-and-leaves-a-trail
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
	 * Delete every kit of a user, for one suite or all of them.
	 *
	 * @param string $userId  The owner.
	 * @param string $suiteId The suite, empty for every suite.
	 *
	 * @return int The number deleted.
	 *
	 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#requirement-recovery-is-limited-to-the-owner-and-leaves-a-trail
	 */
	public function deleteForUser(string $userId, string $suiteId=''): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		if ($suiteId !== '') {
			$qb->andWhere($qb->expr()->eq('suite_id', $qb->createNamedParameter($suiteId)));
		}

		return $qb->executeStatement();
	}//end deleteForUser()
}//end class
