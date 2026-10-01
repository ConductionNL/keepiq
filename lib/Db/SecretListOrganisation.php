<?php

/**
 * Keepiq Secret List Organisation
 *
 * The favourite and tag filters and the sort order of the paged secret
 * list (vault-favourites-tags-and-last-used D4).
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
 * Narrows and orders a query on keepiq_secrets for one holder.
 */
class SecretListOrganisation {
	/**
	 * The order term that puts rows never used after used ones, in either
	 * direction and on every database (their NULL ordering differs).
	 *
	 * @var string
	 */
	public const NEVER_USED_LAST = 'CASE WHEN last_used_at IS NULL THEN 1 ELSE 0 END';

	/**
	 * The filters a request means: `favourite` => true and/or `tag` => the
	 * normalised tag. An unset, false or empty filter adds nothing.
	 *
	 * @param bool|null   $favourite Only starred rows when true
	 * @param string|null $tag       Only rows carrying this tag
	 *
	 * @return array{favourite?: true, tag?: string}
	 *
	 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-favourite-items-per-holder
	 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-tags-per-holder
	 */
	public function conditions(?bool $favourite, ?string $tag): array {
		$conditions = [];
		if ($favourite === true) {
			$conditions['favourite'] = true;
		}

		$tag = mb_strtolower(trim((string)$tag));
		if ($tag !== '') {
			$conditions['tag'] = $tag;
		}

		return $conditions;
	}//end conditions()

	/**
	 * Apply the filters to a query on keepiq_secrets of one holder.
	 *
	 * @param IQueryBuilder $qb        The query to narrow
	 * @param string        $ownerId   The holder whose tags count
	 * @param bool|null     $favourite Only starred rows when true
	 * @param string|null   $tag       Only rows carrying this tag
	 *
	 * @return void
	 *
	 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-tags-per-holder
	 */
	public function apply(IQueryBuilder $qb, string $ownerId, ?bool $favourite, ?string $tag): void {
		$conditions = $this->conditions(favourite: $favourite, tag: $tag);
		if (isset($conditions['favourite']) === true) {
			$qb->andWhere($qb->expr()->eq('is_favourite', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
		}

		if (isset($conditions['tag']) === true) {
			$sub = $qb->getConnection()->getQueryBuilder();
			$sub->select('secret_id')
				->from('keepiq_secret_tags')
				->where($sub->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)))
				->andWhere($sub->expr()->eq('tag', $qb->createNamedParameter($conditions['tag'])));
			$qb->andWhere($qb->expr()->in('id', $qb->createFunction($sub->getSQL())));
		}
	}//end apply()

	/**
	 * The order terms for a sort column: last used puts never-used rows
	 * last; every column but name breaks ties by name.
	 *
	 * @param string $column    An allow-listed sort column
	 * @param string $direction ASC or DESC
	 *
	 * @return list<array{0: string, 1: string}>
	 *
	 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-sort-by-date-last-used
	 */
	public function orderTerms(string $column, string $direction): array {
		if ($column === 'name') {
			return [['name', $direction]];
		}

		$terms = [];
		if ($column === 'last_used_at') {
			$terms[] = [self::NEVER_USED_LAST, 'ASC'];
		}

		$terms[] = [$column, $direction];
		$terms[] = ['name', 'ASC'];

		return $terms;
	}//end orderTerms()

	/**
	 * Apply the order terms to a query.
	 *
	 * @param IQueryBuilder $qb        The query to order
	 * @param string        $column    An allow-listed sort column
	 * @param string        $direction ASC or DESC
	 *
	 * @return void
	 *
	 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-sort-by-date-last-used
	 */
	public function order(IQueryBuilder $qb, string $column, string $direction): void {
		$first = true;
		foreach ($this->orderTerms(column: $column, direction: $direction) as [$term, $dir]) {
			$sort = $term;
			if ($term === self::NEVER_USED_LAST) {
				$sort = $qb->createFunction($term);
			}

			if ($first === true) {
				$qb->orderBy($sort, $dir);
				$first = false;
				continue;
			}

			$qb->addOrderBy($sort, $dir);
		}
	}//end order()
}//end class
