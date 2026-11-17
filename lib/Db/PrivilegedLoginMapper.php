<?php

/**
 * Keepiq Privileged Login Mapper
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
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Mapper for the keepiq_privileged_logins table.
 *
 * @template-extends QBMapper<PrivilegedLogin>
 */
class PrivilegedLoginMapper extends QBMapper {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_privileged_logins', entityClass: PrivilegedLogin::class);
	}//end __construct()

	/**
	 * Which of the given source secrets are privileged.
	 *
	 * @param list<string> $secretIds The source secret ids
	 *
	 * @return list<string> The privileged ones
	 *
	 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
	 */
	public function privilegedAmong(array $secretIds): array {
		if ($secretIds === []) {
			return [];
		}

		$found = [];
		foreach (array_chunk(array_values(array_unique($secretIds)), 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('secret_id')
				->from($this->getTableName())
				->where($qb->expr()->in('secret_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)));
			$result = $qb->executeQuery();
			while (($row = $result->fetch()) !== false) {
				$found[] = (string)$row['secret_id'];
			}

			$result->closeCursor();
		}

		return $found;
	}//end privilegedAmong()

	/**
	 * Remove the mark of a secret, if any.
	 *
	 * @param string $secretId The source secret id
	 *
	 * @return int The rows removed
	 *
	 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
	 */
	public function deleteBySecret(string $secretId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)));

		return $qb->executeStatement();
	}//end deleteBySecret()

	/**
	 * Remove every mark a departing user set.
	 *
	 * @param string $userId The user
	 *
	 * @return int The rows removed
	 *
	 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
	 */
	public function deleteByUser(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('owner_id', $qb->createNamedParameter($userId)));

		return $qb->executeStatement();
	}//end deleteByUser()
}//end class
