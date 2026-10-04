<?php

/**
 * Keepiq Federated Inbound Share Mapper
 *
 * Query-builder mapper for the receiving side of federated shares
 * (sharing-federated-recipients D4).
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

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Mapper for the keepiq_federated_inbound table.
 *
 * @template-extends QBMapper<FederatedInbound>
 *
 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
 */
class FederatedInboundMapper extends QBMapper {
	/**
	 * Constructor for FederatedInboundMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_federated_inbound', entityClass: FederatedInbound::class);
	}//end __construct()

	/**
	 * Find an inbound share by its UUID.
	 *
	 * @param string $id The inbound share UUID
	 *
	 * @return FederatedInbound
	 *
	 * @throws DoesNotExistException When no row matches
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
	 */
	public function findById(string $id): FederatedInbound {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $this->findEntity(query: $qb);
	}//end findById()

	/**
	 * The share a partner announced under its own share id.
	 *
	 * @param string $partnerId     The sending partner
	 * @param string $remoteShareId The share id on the sending instance
	 *
	 * @return FederatedInbound
	 *
	 * @throws DoesNotExistException When no row matches
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-owner-updates-reach-the-remote-copy-and-revocation-removes-it
	 */
	public function findByRemote(string $partnerId, string $remoteShareId): FederatedInbound {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('partner_id', $qb->createNamedParameter($partnerId)))
			->andWhere($qb->expr()->eq('remote_share_id', $qb->createNamedParameter($remoteShareId)));

		return $this->findEntity(query: $qb);
	}//end findByRemote()

	/**
	 * The shares announced under one remote share id, from any partner
	 * (normally one: share ids are UUIDs).
	 *
	 * @param string $remoteShareId The share id on the sending instance
	 *
	 * @return FederatedInbound[]
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-owner-updates-reach-the-remote-copy-and-revocation-removes-it
	 */
	public function findByRemoteShareId(string $remoteShareId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('remote_share_id', $qb->createNamedParameter($remoteShareId)));

		return $this->findEntities(query: $qb);
	}//end findByRemoteShareId()

	/**
	 * The inbound shares whose copy is this secret (at most one in practice).
	 *
	 * @param string $secretId The recipient's copy
	 *
	 * @return FederatedInbound[]
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#scenario-bob-deletes-his-copy
	 */
	public function findBySecretId(string $secretId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)));

		return $this->findEntities(query: $qb);
	}//end findBySecretId()

	/**
	 * Every inbound share of one local user, newest first.
	 *
	 * @param string $recipientUid The local user
	 *
	 * @return FederatedInbound[]
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
	 */
	public function findByRecipient(string $recipientUid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('recipient_uid', $qb->createNamedParameter($recipientUid)))
			->orderBy('received_at', 'DESC');

		return $this->findEntities(query: $qb);
	}//end findByRecipient()
}//end class
