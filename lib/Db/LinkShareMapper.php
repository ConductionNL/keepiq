<?php

/**
 * Keepiq Link Share Mapper
 *
 * Database mapper for link share entities.
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
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;

/**
 * Mapper for LinkShare entities.
 *
 * @extends QBMapper<LinkShare>
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) 13 against a threshold of 10:
 *   each is a single query on the link shares table.
 */
class LinkShareMapper extends QBMapper {
	/**
	 * The `original_created_by` of a handed-over link whose maker's account
	 * was deleted. Nextcloud's uid validation rejects the `:`, and the
	 * queries below guard against a backend that skips it.
	 *
	 * @var string
	 */
	public const DELETED_ACCOUNT_MARKER = 'keepiq:deleted-account';

	/**
	 * Constructor for LinkShareMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_link_shares', entityClass: LinkShare::class);
	}//end __construct()

	/**
	 * Find a link share by its UUID.
	 *
	 * @param string $id The link share ID
	 *
	 * @return LinkShare
	 *
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function findById(string $id): LinkShare {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $this->findEntity(query: $qb);
	}//end findById()

	/**
	 * Find a link share by its public access token.
	 *
	 * @param string $token The access token
	 *
	 * @return LinkShare
	 *
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function findByToken(string $token): LinkShare {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)));

		return $this->findEntity(query: $qb);
	}//end findByToken()

	/**
	 * Find all link shares for a given secret.
	 *
	 * @param string $secretId The secret ID
	 *
	 * @return LinkShare[]
	 */
	public function findBySecretId(string $secretId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)));

		return $this->findEntities(query: $qb);
	}//end findBySecretId()

	/**
	 * Find all link shares a given user holds.
	 *
	 * @param string $userId The Nextcloud user ID
	 *
	 * @return LinkShare[]
	 */
	public function findByCreatedBy(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('created_by', $qb->createNamedParameter($userId)));

		return $this->findEntities(query: $qb);
	}//end findByCreatedBy()

	/**
	 * Find all link shares a given user holds or made, including the ones
	 * they made that an ownership transfer handed to someone else.
	 *
	 * @param string $userId The Nextcloud user ID
	 *
	 * @return LinkShare[]
	 *
	 * @spec openspec/specs/gdpr-compliance/spec.md
	 */
	public function findHeldOrMadeBy(string $userId): array {
		// A backend that skips Nextcloud's uid validation could hand out the
		// marker as a uid; that user did not make the marker's links.
		if ($userId === self::DELETED_ACCOUNT_MARKER) {
			return $this->findByCreatedBy(userId: $userId);
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->orX(
					$qb->expr()->eq('created_by', $qb->createNamedParameter($userId)),
					$qb->expr()->eq('original_created_by', $qb->createNamedParameter($userId))
				)
			);

		return $this->findEntities(query: $qb);
	}//end findHeldOrMadeBy()

	/**
	 * Delete all link shares for a given secret (cascade on secret deletion).
	 *
	 * @param string $secretId The secret ID
	 *
	 * @return void
	 */
	public function deleteBySecretId(string $secretId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)));

		$qb->executeStatement();
	}//end deleteBySecretId()

	/**
	 * Delete all link shares a given user holds (cascade on account deletion),
	 * including the ones handed to them by an ownership transfer.
	 *
	 * @param string $userId The Nextcloud user ID
	 *
	 * @return void
	 */
	public function deleteByUserId(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('created_by', $qb->createNamedParameter($userId)));

		$qb->executeStatement();
	}//end deleteByUserId()

	/**
	 * Delete all link shares a given user made (cascade on compromise
	 * recovery): the ones they still hold, and the ones an ownership transfer
	 * handed to someone else. A link handed to them that someone else made
	 * is left alone, also when that maker's account is deleted.
	 *
	 * @param string $userId The Nextcloud user ID
	 *
	 * @return void
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-compromise-force-revoke-contains-the-account
	 */
	public function deleteMadeBy(string $userId): void {
		$qb   = $this->db->getQueryBuilder();
		$made = $qb->expr()->andX(
			$qb->expr()->eq('created_by', $qb->createNamedParameter($userId)),
			$qb->expr()->isNull('original_created_by')
		);
		// A uid equal to the marker did not make the marker's links.
		if ($userId !== self::DELETED_ACCOUNT_MARKER) {
			$made = $qb->expr()->orX(
				$made,
				$qb->expr()->eq('original_created_by', $qb->createNamedParameter($userId))
			);
		}

		$qb->delete($this->getTableName())->where($made);

		$qb->executeStatement();
	}//end deleteMadeBy()

	/**
	 * Replace a user by DELETED_ACCOUNT_MARKER as the maker of the links an
	 * ownership transfer handed to someone else, once that user's account is
	 * deleted. Not null: that would read as made by the holder, whose
	 * compromise revoke would then cut the link.
	 *
	 * @param string $userId The Nextcloud user ID
	 *
	 * @return int The rows changed
	 *
	 * @spec openspec/specs/gdpr-compliance/spec.md
	 */
	public function forgetOriginalCreator(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('original_created_by', $qb->createNamedParameter(self::DELETED_ACCOUNT_MARKER))
			->where($qb->expr()->eq('original_created_by', $qb->createNamedParameter($userId)));

		return $qb->executeStatement();
	}//end forgetOriginalCreator()

	/**
	 * Hand the link shares one user holds on a secret to another user, who
	 * then lists and revokes them. Links others hold on the secret are left
	 * alone. The first maker of each link is kept in `original_created_by`.
	 *
	 * @param string $secretId   The secret the links were created on
	 * @param string $fromUserId The user who holds the links
	 * @param string $toUserId   The user who takes them over
	 *
	 * @return int The rows handed over
	 *
	 * @spec openspec/specs/link-sharing/spec.md#requirement-manual-revocation
	 */
	public function reassignCreator(string $secretId, string $fromUserId, string $toUserId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('original_created_by', $this->originalCreator(qb: $qb))
			->set('created_by', $qb->createNamedParameter($toUserId))
			->where($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)))
			->andWhere($qb->expr()->eq('created_by', $qb->createNamedParameter($fromUserId)));

		return $qb->executeStatement();
	}//end reassignCreator()

	/**
	 * Hand one link share to another user, who then lists and revokes it.
	 * Its first maker is kept in `original_created_by`.
	 *
	 * @param string $id       The link share ID
	 * @param string $toUserId The user who takes it over
	 *
	 * @return int The rows handed over (0 when it is theirs already or gone)
	 *
	 * @spec openspec/specs/link-sharing/spec.md#requirement-manual-revocation
	 */
	public function reassignCreatorById(string $id, string $toUserId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('original_created_by', $this->originalCreator(qb: $qb))
			->set('created_by', $qb->createNamedParameter($toUserId))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->neq('created_by', $qb->createNamedParameter($toUserId)));

		return $qb->executeStatement();
	}//end reassignCreatorById()

	/**
	 * The value `original_created_by` takes when a link is handed over: the
	 * maker already recorded, else the user who holds it until now. MySQL
	 * reads a column assigned earlier in the same UPDATE as its new value,
	 * so this has to be set before `created_by`.
	 *
	 * @param IQueryBuilder $qb The update being built
	 *
	 * @return IQueryFunction
	 */
	private function originalCreator(IQueryBuilder $qb): IQueryFunction {
		return $qb->createFunction(
			'COALESCE('.$qb->getColumnName('original_created_by').', '.$qb->getColumnName('created_by').')'
		);
	}//end originalCreator()

	/**
	 * Atomically increment the usage count for a token, but only while
	 * usage_count is still below usage_limit. Returns the number of rows
	 * affected (1 on success, 0 if the limit was already reached or the
	 * token does not exist). This guards against the two-tab race where
	 * two concurrent confirms could otherwise exceed the limit.
	 *
	 * @param string $token The access token
	 *
	 * @return int The number of affected rows
	 */
	public function incrementUsageIfBelowLimit(string $token): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('usage_count', $qb->createFunction('usage_count + 1'))
			->set('failed_attempts', $qb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)))
			->andWhere($qb->expr()->lt('usage_count', 'usage_limit'));

		return $qb->executeStatement();
	}//end incrementUsageIfBelowLimit()
}//end class
