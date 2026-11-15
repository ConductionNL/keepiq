<?php

/**
 * Keepiq Ephemeral Send Mapper
 *
 * Query-builder mapper for EphemeralSend rows (ephemeral-send §1.2).
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

use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for the keepiq_ephemeral_sends table.
 *
 * @template-extends QBMapper<EphemeralSend>
 */
class EphemeralSendMapper extends QBMapper {

	use FindsByStringId;

	/**
	 * Constructor for EphemeralSendMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_ephemeral_sends', entityClass: EphemeralSend::class);
	}//end __construct()

	/**
	 * Find a send by its UUID.
	 *
	 * @param string $id The send UUID
	 *
	 * @return EphemeralSend
	 *
	 * @throws DoesNotExistException When no row matches
	 */
	public function findById(string $id): EphemeralSend {
		return $this->findEntity(query: $this->stringIdQuery(id: $id));
	}//end findById()

	/**
	 * Find a send by its public token.
	 *
	 * @param string $token The URL token
	 *
	 * @return EphemeralSend
	 *
	 * @throws DoesNotExistException When no row matches
	 */
	public function findByToken(string $token): EphemeralSend {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)));

		return $this->findEntity(query: $qb);
	}//end findByToken()

	/**
	 * All sends of an owner, newest first.
	 *
	 * @param string $ownerId The owner user ID
	 *
	 * @return EphemeralSend[]
	 */
	public function findByOwner(string $ownerId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)))
			->orderBy('created_at', 'DESC');

		return $this->findEntities(query: $qb);
	}//end findByOwner()

	/**
	 * TTL-elapsed or fully-burned sends (purge-job input).
	 *
	 * @param DateTime $now The evaluation instant
	 * @param int $limit Maximum rows per batch
	 *
	 * @return EphemeralSend[]
	 */
	public function findPurgeable(DateTime $now, int $limit = 500): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->orX(
					$qb->expr()->andX(
						$qb->expr()->isNotNull('expires_at'),
						$qb->expr()->lte('expires_at', $qb->createNamedParameter($now, 'datetime'))
					),
					$qb->expr()->gte('view_count', 'max_views')
				)
			)
			->setMaxResults($limit);

		return $this->findEntities(query: $qb);
	}//end findPurgeable()
	/**
	 * Atomically count one serve of the ciphertext as a view, and as an
	 * attempt when the send has a password, but only while views remain and
	 * the attempts since the last confirmed decrypt are below the cap.
	 * Returns 1 when the ciphertext may be served, 0 otherwise.
	 *
	 * @param string $token        The URL token
	 * @param int    $attemptsCap  The attempt cap
	 * @param bool   $countAttempt Whether the serve is also a password attempt
	 *
	 * @return int The number of affected rows
	 */
	public function countServeIfAllowed(string $token, int $attemptsCap, bool $countAttempt): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('view_count', $qb->createFunction('view_count + 1'));
		if ($countAttempt === true) {
			$qb->set('failed_attempts', $qb->createFunction('failed_attempts + 1'));
		}

		$qb->where($qb->expr()->eq('token', $qb->createNamedParameter($token)))
			->andWhere($qb->expr()->lt('view_count', 'max_views'))
			->andWhere($qb->expr()->lt('failed_attempts', $qb->createNamedParameter($attemptsCap, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}//end countServeIfAllowed()

	/**
	 * Reset the attempts of a send after a confirmed decrypt.
	 *
	 * @param string $token The URL token
	 *
	 * @return int The number of affected rows
	 */
	public function resetFailedAttempts(string $token): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('failed_attempts', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)));

		return $qb->executeStatement();
	}//end resetFailedAttempts()
}//end class
