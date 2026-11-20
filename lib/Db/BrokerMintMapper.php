<?php

/**
 * Keepiq BrokerMintMapper
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
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Mapper for the keepiq_broker_mints table.
 *
 * @template-extends QBMapper<BrokerMint>
 *
 * @spec openspec/changes/connection-certificate-expiry-for-integriq/specs/integration-boundary/spec.md#requirement-integriq-may-learn-when-its-own-connection-certificate-expires
 */
class BrokerMintMapper extends QBMapper {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_broker_mints', entityClass: BrokerMint::class);
	}//end __construct()

	/**
	 * The mint record of a secret, or null when the broker did not mint it.
	 *
	 * @param string $secretId The secret
	 *
	 * @return BrokerMint|null
	 *
	 * @spec openspec/changes/connection-certificate-expiry-for-integriq/specs/integration-boundary/spec.md#requirement-integriq-may-learn-when-its-own-connection-certificate-expires
	 */
	public function findBySecret(string $secretId): ?BrokerMint {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)));

		try {
			return $this->findEntity(query: $qb);
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			return null;
		}
	}//end findBySecret()

	/**
	 * Remove the mint record of a secret.
	 *
	 * @param string $secretId The secret
	 *
	 * @return int The rows removed
	 */
	public function deleteBySecret(string $secretId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)));

		return $qb->executeStatement();
	}//end deleteBySecret()
}//end class
