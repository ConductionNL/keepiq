<?php

/**
 * Keepiq Query Count
 *
 * Reads the single count a COUNT(*) AS cnt query answers.
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
 * Runs a query that selects `COUNT(*) AS cnt` and answers the count, 0 when
 * no row comes back.
 *
 * @spec openspec/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
 */
final class QueryCount {
	/**
	 * Run the query and read its `cnt` column.
	 *
	 * @param IQueryBuilder $qb The count query
	 *
	 * @return int
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	public function read(IQueryBuilder $qb): int {
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['cnt'] ?? 0);
	}//end read()
}//end class
