<?php

/**
 * Keepiq Federated Share Mapper
 *
 * Query-builder mapper for the sending side of federated shares
 * (sharing-federated-recipients D4, D5).
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
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for the keepiq_federated_shares table.
 *
 * @template-extends QBMapper<FederatedShare>
 *
 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
 */
class FederatedShareMapper extends QBMapper {
	/**
	 * Constructor for FederatedShareMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_federated_shares', entityClass: FederatedShare::class);
	}//end __construct()

	/**
	 * Find a share by its UUID.
	 *
	 * @param string $id The share UUID
	 *
	 * @return FederatedShare
	 *
	 * @throws DoesNotExistException When no row matches
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
	 */
	public function findById(string $id): FederatedShare {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $this->findEntity(query: $qb);
	}//end findById()

	/**
	 * Every federated share of one source secret.
	 *
	 * @param string $sourceSecretId The owner's secret
	 *
	 * @return FederatedShare[]
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-owner-updates-reach-the-remote-copy-and-revocation-removes-it
	 */
	public function findBySourceSecret(string $sourceSecretId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('source_secret_id', $qb->createNamedParameter($sourceSecretId)))
			->orderBy('created_at', 'ASC');

		return $this->findEntities(query: $qb);
	}//end findBySourceSecret()

	/**
	 * Every federated share to recipients of one partner.
	 *
	 * @param string $partnerId The partner UUID
	 *
	 * @return FederatedShare[]
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-owner-updates-reach-the-remote-copy-and-revocation-removes-it
	 */
	public function findByPartner(string $partnerId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('partner_id', $qb->createNamedParameter($partnerId)));

		return $this->findEntities(query: $qb);
	}//end findByPartner()

	/**
	 * Shares with a notification due for another delivery attempt.
	 *
	 * @param DateTime $now   The current time
	 * @param int      $limit The most rows to return
	 *
	 * @return FederatedShare[]
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-owner-updates-reach-the-remote-copy-and-revocation-removes-it
	 */
	public function findDueNotifications(DateTime $now, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->isNotNull('pending_notification'))
			->andWhere($qb->expr()->lte('next_notify_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE)))
			->orderBy('next_notify_at', 'ASC')
			->setMaxResults($limit);

		return $this->findEntities(query: $qb);
	}//end findDueNotifications()
}//end class
