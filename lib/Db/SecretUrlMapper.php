<?php

/**
 * Keepiq Secret Url Mapper
 *
 * Reads and writes the extra website addresses of logins
 * (vault-website-addresses D1).
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
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Throwable;

/**
 * Mapper for SecretUrl entities.
 *
 * @extends QBMapper<SecretUrl>
 */
class SecretUrlMapper extends QBMapper {
	/**
	 * The table.
	 */
	public const TABLE = 'keepiq_secret_urls';

	/**
	 * Constructor for SecretUrlMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: self::TABLE, entityClass: SecretUrl::class);
	}//end __construct()

	/**
	 * The extra addresses of a set of rows, keyed by secret id, in list
	 * order. Rows without extra addresses are absent from the result.
	 *
	 * @param string[] $secretIds The rows
	 *
	 * @return array<string,list<string>>
	 *
	 * @spec openspec/changes/vault-website-addresses/specs/website-addresses/spec.md#requirement-a-login-carries-several-website-addresses
	 */
	public function findUrlsBySecretIds(array $secretIds): array {
		$urls = [];
		foreach (array_chunk(array_values(array_unique($secretIds)), 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('secret_id', 'url')
				->from($this->getTableName())
				->where($qb->expr()->in('secret_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)))
				->orderBy('secret_id', 'ASC')
				->addOrderBy('position', 'ASC');
			$result = $qb->executeQuery();
			while (($row = $result->fetch()) !== false) {
				$urls[(string)$row['secret_id']][] = (string)$row['url'];
			}

			$result->closeCursor();
		}

		return $urls;
	}//end findUrlsBySecretIds()

	/**
	 * Replace the extra addresses of one row, in one transaction.
	 *
	 * @param string       $secretId The row
	 * @param string       $ownerId  Its holder
	 * @param list<string> $urls     The normalised addresses, in order
	 *
	 * @return void
	 *
	 * @throws Throwable When the write fails; nothing is changed then
	 *
	 * @spec openspec/changes/vault-website-addresses/specs/website-addresses/spec.md#requirement-a-login-carries-several-website-addresses
	 */
	public function replaceForSecret(string $secretId, string $ownerId, array $urls): void {
		$this->db->beginTransaction();
		try {
			$this->deleteBySecret(secretId: $secretId);
			foreach (array_values($urls) as $position => $url) {
				$row = new SecretUrl();
				$row->setSecretId($secretId);
				$row->setOwnerId($ownerId);
				$row->setUrl($url);
				$row->setPosition($position);
				$this->insert(entity: $row);
			}

			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}//end replaceForSecret()

	/**
	 * Delete every extra address of one row (the row is deleted for good).
	 *
	 * @param string $secretId The row
	 *
	 * @return int The number of addresses removed
	 *
	 * @spec openspec/changes/vault-website-addresses/specs/website-addresses/spec.md#requirement-a-login-carries-several-website-addresses
	 */
	public function deleteBySecret(string $secretId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)));

		return $qb->executeStatement();
	}//end deleteBySecret()

	/**
	 * Delete every extra address a holder's rows carry (account deletion).
	 *
	 * @param string $ownerId The holder
	 *
	 * @return int The number of addresses removed
	 *
	 * @spec openspec/changes/vault-website-addresses/specs/website-addresses/spec.md#requirement-a-login-carries-several-website-addresses
	 */
	public function deleteByOwner(string $ownerId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)));

		return $qb->executeStatement();
	}//end deleteByOwner()
}//end class
