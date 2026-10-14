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
 * @spec openspec/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
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
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
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
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-owner-updates-reach-the-remote-copy-and-revocation-removes-it
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
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-owner-updates-reach-the-remote-copy-and-revocation-removes-it
	 */
	public function findByPartner(string $partnerId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('partner_id', $qb->createNamedParameter($partnerId)));

		return $this->findEntities(query: $qb);
	}//end findByPartner()

	/**
	 * Shares with a notification due for another delivery attempt. A share
	 * that is no longer active is announced nothing but its end: a suspended,
	 * declined or failed one is left out unless `$ending` is pending on it.
	 *
	 * @param DateTime $now    The current time
	 * @param int      $limit  The most rows to return
	 * @param string   $ending The notification that ends a share (SHARE_UNSHARED)
	 *
	 * @return FederatedShare[]
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-owner-updates-reach-the-remote-copy-and-revocation-removes-it
	 */
	public function findDueNotifications(DateTime $now, int $limit, string $ending): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->isNotNull('pending_notification'))
			->andWhere($qb->expr()->lte('next_notify_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE)))
			->andWhere(
				$qb->expr()->orX(
					$qb->expr()->eq('status', $qb->createNamedParameter(FederatedShare::STATUS_ACTIVE)),
					$qb->expr()->eq('pending_notification', $qb->createNamedParameter($ending))
				)
			)
			->orderBy('next_notify_at', 'ASC')
			->setMaxResults($limit);

		return $this->findEntities(query: $qb);
	}//end findDueNotifications()

	/**
	 * Write a share's changed fields only while it is still active, so a
	 * revoke or suspend that landed meanwhile is never overwritten.
	 *
	 * @param FederatedShare $share The share with its changes set
	 *
	 * @return int The rows matched: 0 when the share is no longer active
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/federated-sharing/spec.md#requirement-partner-copies-change-only-with-the-owners-share-save
	 */
	public function updateIfActive(FederatedShare $share): int {
		$qb = $this->changes(share: $share);
		$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(FederatedShare::STATUS_ACTIVE)));

		return $this->run(qb: $qb, share: $share);
	}//end updateIfActive()

	/**
	 * Write a share's changed fields only while it still has the status it
	 * was read with and `$ending` is not pending on it, so a revoke that
	 * landed meanwhile, and its pending unshare, are never overwritten.
	 *
	 * @param FederatedShare $share The share with its changes set
	 * @param string $status The status it was read with
	 * @param string $ending The notification that ends a share (SHARE_UNSHARED)
	 *
	 * @return int The rows matched: 0 when the share changed meanwhile
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-the-owner-revoked-the-share-meanwhile
	 */
	public function updateIfStatus(FederatedShare $share, string $status, string $ending): int {
		$qb = $this->changes(share: $share);
		$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)))
			->andWhere(
				$qb->expr()->orX(
					$qb->expr()->isNull('pending_notification'),
					$qb->expr()->neq('pending_notification', $qb->createNamedParameter($ending))
				)
			);

		return $this->run(qb: $qb, share: $share);
	}//end updateIfStatus()

	/**
	 * Write the outcome of a delivery attempt only while the row still holds
	 * the notification, status and version the attempt started from, so it
	 * never clears or replaces a notification queued meanwhile.
	 *
	 * @param FederatedShare $share The share with the outcome set
	 * @param string $pending The notification that was attempted
	 * @param string $status The status the attempt started from
	 * @param DateTime|null $updatedAt The version the attempt started from
	 *
	 * @return int The rows matched: 0 when something else was queued meanwhile
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/federated-sharing/spec.md#requirement-partner-copies-change-only-with-the-owners-share-save
	 */
	public function updateIfUnchanged(FederatedShare $share, string $pending, string $status, ?DateTime $updatedAt): int {
		$qb = $this->changes(share: $share);
		$qb->andWhere($qb->expr()->eq('pending_notification', $qb->createNamedParameter($pending)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)));
		$version = $qb->expr()->isNull('updated_at');
		if ($updatedAt !== null) {
			$version = $qb->expr()->eq('updated_at', $qb->createNamedParameter($updatedAt, IQueryBuilder::PARAM_DATETIME_MUTABLE));
		}

		$qb->andWhere($version);

		return $this->run(qb: $qb, share: $share);
	}//end updateIfUnchanged()

	/**
	 * Delete a share only while the notification is still pending on it.
	 *
	 * @param FederatedShare $share The share
	 * @param string $pending The notification that was delivered
	 *
	 * @return int The rows deleted
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-revocation-removes-bobs-copy
	 */
	public function deleteIfPending(FederatedShare $share, string $pending): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($share->getId())))
			->andWhere($qb->expr()->eq('pending_notification', $qb->createNamedParameter($pending)));

		return $qb->executeStatement();
	}//end deleteIfPending()

	/**
	 * An UPDATE of the share's changed fields, for its row.
	 *
	 * @param FederatedShare $share The share with its changes set
	 *
	 * @return IQueryBuilder
	 */
	private function changes(FederatedShare $share): IQueryBuilder {
		$fields = $share->getUpdatedFields();
		unset($fields['id']);

		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName());
		foreach (array_keys($fields) as $property) {
			$getter = 'get'.ucfirst($property);
			$qb->set(
				$share->propertyToColumn($property),
				$qb->createNamedParameter(
					$share->$getter(),
					$this->getParameterTypeForProperty(entity: $share, property: $property)
				)
			);
		}

		return $qb->where($qb->expr()->eq('id', $qb->createNamedParameter($share->getId())));
	}//end changes()

	/**
	 * Run a conditional update and forget the written changes, so a later
	 * write of the same entity cannot send an older ciphertext again.
	 *
	 * @param IQueryBuilder $qb The update
	 * @param FederatedShare $share The share it writes
	 *
	 * @return int The rows matched
	 */
	private function run(IQueryBuilder $qb, FederatedShare $share): int {
		$rows = $qb->executeStatement();
		$share->resetUpdatedFields();

		return $rows;
	}//end run()
}//end class
