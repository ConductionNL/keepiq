<?php

/**
 * Keepiq Federation Partner Mapper
 *
 * Query-builder mapper for FederationPartner rows
 * (sharing-federated-recipients D1).
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
 * Mapper for the keepiq_federation_partners table.
 *
 * @template-extends QBMapper<FederationPartner>
 */
class FederationPartnerMapper extends QBMapper {
	/**
	 * Constructor for FederationPartnerMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_federation_partners', entityClass: FederationPartner::class);
	}//end __construct()

	/**
	 * Find a partner by its UUID.
	 *
	 * @param string $id The partner UUID
	 *
	 * @return FederationPartner
	 *
	 * @throws DoesNotExistException When no row matches
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
	 */
	public function findById(string $id): FederationPartner {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $this->findEntity(query: $qb);
	}//end findById()

	/**
	 * Find a partner by its host, as an OCM signer or a cloud id names it.
	 *
	 * @param string $host The lowercase host, with `:port` when not 443
	 *
	 * @return FederationPartner
	 *
	 * @throws DoesNotExistException When no partner has that host
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
	 */
	public function findByHost(string $host): FederationPartner {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('host', $qb->createNamedParameter($host)));

		return $this->findEntity(query: $qb);
	}//end findByHost()

	/**
	 * All partners, oldest first.
	 *
	 * @return FederationPartner[]
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
	 */
	public function findAllPartners(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('added_at', 'ASC');

		return $this->findEntities(query: $qb);
	}//end findAllPartners()
}//end class
