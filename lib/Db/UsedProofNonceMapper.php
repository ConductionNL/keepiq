<?php

/**
 * Keepiq Used Proof Nonce Mapper
 *
 * Makes every vault-key proof single-use on every install (keepiq#868). The
 * unique index on `nonce_hash` is the authority: the first insert of a
 * challenge wins, any later one hits the constraint. This holds without a
 * memcache, with a server-local one, and across the nodes of a cluster,
 * where the distributed cache it replaces did not.
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

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for consumed vault-key-proof challenges.
 *
 * @extends QBMapper<UsedProofNonce>
 */
class UsedProofNonceMapper extends QBMapper {
	/**
	 * Constructor for UsedProofNonceMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_used_proofs', entityClass: UsedProofNonce::class);
	}//end __construct()

	/**
	 * Claim a challenge. True the first time, false on every later call.
	 *
	 * @param string $nonceHash SHA-256 of the challenge, hex
	 * @param int    $expiresAt When the challenge expires (Unix time)
	 *
	 * @return bool Whether this call was the first to claim it
	 *
	 * @throws DbException On any database failure other than the duplicate
	 *
	 * @spec openspec/specs/vault-key-proof/spec.md#requirement-challenges-are-stateless-and-expiring
	 */
	public function claim(string $nonceHash, int $expiresAt): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->insert($this->getTableName())
			->values(
				[
					'nonce_hash' => $qb->createNamedParameter($nonceHash),
					'expires_at' => $qb->createNamedParameter($expiresAt, IQueryBuilder::PARAM_INT),
				]
			);

		try {
			$qb->executeStatement();
		} catch (DbException $e) {
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION
				|| $e->getReason() === DbException::REASON_CONSTRAINT_VIOLATION
			) {
				return false;
			}

			throw $e;
		}

		return true;
	}//end claim()

	/**
	 * Delete every claim whose challenge has expired.
	 *
	 * An expired challenge is refused on its own, so its row protects
	 * nothing any more.
	 *
	 * @param int $now The current Unix time
	 *
	 * @return int How many rows were deleted
	 *
	 * @spec openspec/specs/vault-key-proof/spec.md#requirement-challenges-are-stateless-and-expiring
	 */
	public function deleteExpired(int $now): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->lt('expires_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}//end deleteExpired()
}//end class
