<?php

/**
 * Keepiq ExpiringGrantQueries
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
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The end-date query shared by the three grant mappers (share targets,
 * group shares and team-folder memberships), which all carry `expires_at`.
 *
 * Used by a QBMapper subclass: relies on its `$db`, `getTableName()` and
 * `findEntities()`.
 */
trait ExpiringGrantQueries {

	/**
	 * The grants whose end date falls in (from, to]. A null `from` means
	 * every grant whose end date is at or before `to`, so
	 * `findEndingBetween(null, $now)` lists the expired grants.
	 *
	 * @param DateTime|null $from Exclusive lower bound (null = none)
	 * @param DateTime      $to   Inclusive upper bound
	 *
	 * @return array<int,mixed> The grant entities
	 *
	 * @spec openspec/specs/expiring-shares/spec.md#requirement-a-background-job-removes-expired-access
	 */
	public function findEndingBetween(?DateTime $from, DateTime $to): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->isNotNull('expires_at'))
			->andWhere($qb->expr()->lte('expires_at', $qb->createNamedParameter($to, IQueryBuilder::PARAM_DATETIME_MUTABLE)));
		if ($from !== null) {
			$qb->andWhere($qb->expr()->gt('expires_at', $qb->createNamedParameter($from, IQueryBuilder::PARAM_DATETIME_MUTABLE)));
		}

		return $this->findEntities(query: $qb);
	}//end findEndingBetween()
}//end trait
