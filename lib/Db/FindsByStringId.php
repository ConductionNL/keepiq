<?php

/**
 * Keepiq FindsByStringId
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
 * The single-row query by UUID shared by the mappers of string-keyed tables.
 *
 * Used by a QBMapper subclass: relies on its `$db` and `getTableName()`.
 * Each mapper runs the query through its own typed `findById()`.
 *
 * @spec exclude Persistence read shared by the UUID-keyed mappers
 */
trait FindsByStringId {

	/**
	 * The query for the row whose `id` column equals the given UUID.
	 *
	 * @param string $id The UUID
	 *
	 * @return IQueryBuilder
	 *
	 * @spec exclude Persistence read shared by the UUID-keyed mappers
	 */
	protected function stringIdQuery(string $id): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $qb;
	}//end stringIdQuery()
}//end trait
