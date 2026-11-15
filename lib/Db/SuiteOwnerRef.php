<?php

/**
 * Keepiq Suite Owner Ref
 *
 * One owner's rows bound to one encryption suite.
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

use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The suite and owner a migration query is keyed by: the rows of this owner
 * that are still bound to this suite.
 *
 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
 */
final class SuiteOwnerRef {
	/**
	 * Constructor for SuiteOwnerRef.
	 *
	 * @param string $encryptionSuiteId The suite ID
	 * @param string $ownerType The owner type
	 * @param string $ownerId The owner ID
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $encryptionSuiteId,
		public readonly string $ownerType,
		public readonly string $ownerId,
	) {
	}//end __construct()

	/**
	 * Narrow a secrets query to this owner's rows still bound to this suite.
	 *
	 * @param IQueryBuilder $qb The query to narrow
	 *
	 * @return IQueryBuilder The same query, for chaining
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	public function narrow(IQueryBuilder $qb): IQueryBuilder {
		return $qb->where($qb->expr()->eq('encryption_suite_id', $qb->createNamedParameter($this->encryptionSuiteId)))
			->andWhere($qb->expr()->eq('owner_type', $qb->createNamedParameter($this->ownerType)))
			->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($this->ownerId)));
	}//end narrow()
}//end class
