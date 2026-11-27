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

use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for the keepiq_federation_partners table.
 *
 * @template-extends QBMapper<FederationPartner>
 */
class FederationPartnerMapper extends QBMapper {

	use FindsByStringId;

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
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
	 */
	public function findById(string $id): FederationPartner {
		return $this->findEntity(query: $this->stringIdQuery(id: $id));
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
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
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
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
	 */
	public function findAllPartners(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('added_at', 'ASC');

		return $this->findEntities(query: $qb);
	}//end findAllPartners()

	/**
	 * The partners with an offered root renewal.
	 *
	 * @return FederationPartner[]
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-offered-renewal-expires-after-30-days-with-reminders
	 */
	public function findWithOfferedRenewal(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->isNotNull('pending_root_fingerprint'));

		return $this->findEntities(query: $qb);
	}//end findWithOfferedRenewal()

	/**
	 * The partners whose renewal notice is due to be sent at `$now`, first time or retry.
	 *
	 * @param DateTime $now The current time
	 *
	 * @return FederationPartner[]
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-a-renewed-root-is-announced-to-partners
	 */
	public function findDueRenewalNotices(DateTime $now): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->isNotNull('renewal_notice_next_at'))
			->andWhere(
				$qb->expr()->lte('renewal_notice_next_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE))
			);

		return $this->findEntities(query: $qb);
	}//end findDueRenewalNotices()
}//end class
