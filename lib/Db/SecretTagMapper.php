<?php

/**
 * Keepiq Secret Tag Mapper
 *
 * Reads and writes the plain-text tags a holder puts on their own secret
 * rows (vault-favourites-tags-and-last-used D2). Every query is keyed by
 * the holder's user id.
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
 * Mapper for SecretTag entities.
 *
 * @extends QBMapper<SecretTag>
 */
class SecretTagMapper extends QBMapper {
	/**
	 * Constructor for SecretTagMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_secret_tags', entityClass: SecretTag::class);
	}//end __construct()

	/**
	 * The tags on a set of a holder's rows, keyed by secret id. Rows without
	 * tags are absent from the result.
	 *
	 * @param string   $ownerId   The holder
	 * @param string[] $secretIds The rows
	 *
	 * @return array<string,list<string>>
	 *
	 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-tags-per-holder
	 */
	public function findTagsBySecretIds(string $ownerId, array $secretIds): array {
		$tags = [];
		foreach (array_chunk(array_values(array_unique($secretIds)), 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('secret_id', 'tag')
				->from($this->getTableName())
				->where($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)))
				->andWhere($qb->expr()->in('secret_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)))
				->orderBy('tag', 'ASC');
			$result = $qb->executeQuery();
			while (($row = $result->fetch()) !== false) {
				$tags[(string)$row['secret_id']][] = (string)$row['tag'];
			}

			$result->closeCursor();
		}

		return $tags;
	}//end findTagsBySecretIds()

	/**
	 * Replace the tags on one of the holder's rows, in one transaction.
	 *
	 * @param string       $secretId The row
	 * @param string       $ownerId  The holder
	 * @param list<string> $tags     The normalised tags
	 *
	 * @return void
	 *
	 * @throws Throwable When the write fails; nothing is changed then
	 *
	 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-tags-per-holder
	 */
	public function replaceForSecret(string $secretId, string $ownerId, array $tags): void {
		$this->db->beginTransaction();
		try {
			$this->deleteBySecret(secretId: $secretId);
			foreach ($tags as $tag) {
				$row = new SecretTag();
				$row->setSecretId($secretId);
				$row->setOwnerId($ownerId);
				$row->setTag($tag);
				$this->insert(entity: $row);
			}

			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}//end replaceForSecret()

	/**
	 * The holder's tags with the number of their live (not trashed, not
	 * archived) secrets carrying each, alphabetically.
	 *
	 * @param string $ownerId The holder
	 *
	 * @return list<array{tag: string, count: int}>
	 *
	 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-tags-per-holder
	 */
	public function countByOwner(string $ownerId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('t.tag')
			->selectAlias($qb->func()->count('t.id'), 'cnt')
			->from($this->getTableName(), 't')
			->innerJoin('t', 'keepiq_secrets', 's', $qb->expr()->eq('s.id', 't.secret_id'))
			->where($qb->expr()->eq('t.owner_id', $qb->createNamedParameter($ownerId)))
			->andWhere($qb->expr()->eq('s.owner_type', $qb->createNamedParameter('user')))
			->andWhere($qb->expr()->eq('s.owner_id', $qb->createNamedParameter($ownerId)))
			->andWhere($qb->expr()->isNull('s.trashed_at'))
			->andWhere($qb->expr()->isNull('s.archived_at'))
			->groupBy('t.tag')
			->orderBy('t.tag', 'ASC');

		$tags   = [];
		$result = $qb->executeQuery();
		while (($row = $result->fetch()) !== false) {
			$tags[] = ['tag' => (string)$row['tag'], 'count' => (int)$row['cnt']];
		}

		$result->closeCursor();

		return $tags;
	}//end countByOwner()

	/**
	 * Delete every tag on one row (the row is deleted for good).
	 *
	 * @param string $secretId The row
	 *
	 * @return int The number of tags removed
	 *
	 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-tags-per-holder
	 */
	public function deleteBySecret(string $secretId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)));

		return $qb->executeStatement();
	}//end deleteBySecret()

	/**
	 * Delete every tag a holder set (account deletion).
	 *
	 * @param string $ownerId The holder
	 *
	 * @return int The number of tags removed
	 *
	 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-tags-per-holder
	 */
	public function deleteByOwner(string $ownerId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)));

		return $qb->executeStatement();
	}//end deleteByOwner()
}//end class
