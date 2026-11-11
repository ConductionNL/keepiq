<?php

/**
 * Keepiq WebAuthn Challenge Mapper
 *
 * The rows of `keepiq_webauthn_challenges`: one pending browser-extension
 * passkey challenge per user, app password and purpose.
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

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Reads and deletes pending extension passkey challenges.
 *
 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-passkey-trust/spec.md#requirement-extension-challenges-are-server-side-bound-and-single-use
 */
class WebAuthnChallengeMapper {
	/**
	 * The table.
	 *
	 * @var string
	 */
	public const TABLE = 'keepiq_webauthn_challenges';

	/**
	 * Constructor for WebAuthnChallengeMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(
		private IDBConnection $db,
	) {
	}//end __construct()

	/**
	 * Store a pending challenge, replacing the one for the same user, app
	 * password and purpose.
	 *
	 * @param array{uid: string, token_id: int, purpose: string, challenge: string, assertion_challenge: string|null, expires_at: int} $row The row
	 *
	 * @return void
	 *
	 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-passkey-trust/spec.md#requirement-extension-challenges-are-server-side-bound-and-single-use
	 */
	public function replace(array $row): void {
		$this->deletePending(uid: $row['uid'], tokenId: $row['token_id'], purpose: $row['purpose']);

		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)->values([
			'uid' => $qb->createNamedParameter($row['uid']),
			'token_id' => $qb->createNamedParameter($row['token_id'], IQueryBuilder::PARAM_INT),
			'purpose' => $qb->createNamedParameter($row['purpose']),
			'challenge' => $qb->createNamedParameter($row['challenge']),
			'assertion_challenge' => $qb->createNamedParameter($row['assertion_challenge']),
			'expires_at' => $qb->createNamedParameter($row['expires_at'], IQueryBuilder::PARAM_INT),
		]);
		$qb->executeStatement();
	}//end replace()

	/**
	 * The pending row for a user, app password and purpose, or null.
	 *
	 * @param string $uid The user
	 * @param int $tokenId The app password's token id
	 * @param string $purpose enrol or unlock
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-passkey-trust/spec.md#requirement-extension-challenges-are-server-side-bound-and-single-use
	 */
	public function findPending(string $uid, int $tokenId, string $purpose): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('token_id', $qb->createNamedParameter($tokenId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('purpose', $qb->createNamedParameter($purpose)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if (is_array($row) === false) {
			return null;
		}

		return $row;
	}//end findPending()

	/**
	 * Delete one row; the number of rows deleted (0 when another request
	 * deleted it first).
	 *
	 * @param int $id The row id
	 *
	 * @return int
	 *
	 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-passkey-trust/spec.md#requirement-extension-challenges-are-server-side-bound-and-single-use
	 */
	public function deleteById(int $id): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}//end deleteById()

	/**
	 * Delete every pending challenge of an app password.
	 *
	 * @param int $tokenId The token id
	 *
	 * @return int The number of rows deleted
	 *
	 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-passkey-trust/spec.md#requirement-extension-credentials-follow-their-app-password
	 */
	public function deleteByToken(int $tokenId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('token_id', $qb->createNamedParameter($tokenId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}//end deleteByToken()

	/**
	 * Delete every challenge that expired before the given time.
	 *
	 * @param int $now Unix time
	 *
	 * @return int The number of rows deleted
	 *
	 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-passkey-trust/spec.md#requirement-extension-challenges-are-server-side-bound-and-single-use
	 */
	public function deleteExpired(int $now): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->lt('expires_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}//end deleteExpired()

	/**
	 * Delete the pending challenge of a user, app password and purpose.
	 *
	 * @param string $uid The user
	 * @param int $tokenId The token id
	 * @param string $purpose enrol or unlock
	 *
	 * @return void
	 */
	private function deletePending(string $uid, int $tokenId, string $purpose): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('token_id', $qb->createNamedParameter($tokenId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('purpose', $qb->createNamedParameter($purpose)));
		$qb->executeStatement();
	}//end deletePending()
}//end class
