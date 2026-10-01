<?php

/**
 * Keepiq Secret State Filter
 *
 * Narrows a secrets query to one trash/archive state
 * (vault-trash-and-archive D3): the one place that decides what "live",
 * "trashed", "archived" and "kept" mean in SQL.
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

use InvalidArgumentException;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Applies a SecretMapper::STATE_* restriction to a query on keepiq_secrets.
 */
class SecretStateFilter {
	/**
	 * Restrict a query to one state; null leaves it unrestricted.
	 *
	 * @param IQueryBuilder $qb    The query to narrow
	 * @param string|null   $state One of the SecretMapper::STATE_* constants, or null
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the state is unknown
	 *
	 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-archiving-a-secret
	 */
	public function apply(IQueryBuilder $qb, ?string $state): void {
		foreach ($this->conditions(state: $state) as $column => $isSet) {
			if ($isSet === true) {
				$qb->andWhere($qb->expr()->isNotNull($column));
				continue;
			}

			$qb->andWhere($qb->expr()->isNull($column));
		}
	}//end apply()

	/**
	 * The conditions a state means: column => true (must be set) or false
	 * (must be null). Null state means no condition.
	 *
	 * @param string|null $state One of the SecretMapper::STATE_* constants, or null
	 *
	 * @return array<string,bool>
	 *
	 * @throws InvalidArgumentException When the state is unknown
	 *
	 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-archiving-a-secret
	 */
	public function conditions(?string $state): array {
		return match ($state) {
			null => [],
			SecretMapper::STATE_LIVE => ['trashed_at' => false, 'archived_at' => false],
			SecretMapper::STATE_TRASHED => ['trashed_at' => true],
			SecretMapper::STATE_ARCHIVED => ['trashed_at' => false, 'archived_at' => true],
			SecretMapper::STATE_KEPT => ['trashed_at' => false],
			default => throw new InvalidArgumentException('Unknown secret state: '.$state),
		};
	}//end conditions()
}//end class
