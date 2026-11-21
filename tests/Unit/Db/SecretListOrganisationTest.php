<?php

/**
 * The list's favourite and tag filters and its last-used order
 * (vault-favourites-tags-and-last-used).
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Db
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

namespace OCA\Keepiq\Tests\Unit\Db;

use OCA\Keepiq\Db\SecretListOrganisation;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use PHPUnit\Framework\TestCase;

/**
 * Each filter names exactly what it narrows to; last used sorts never-used rows last.
 */
class SecretListOrganisationTest extends TestCase {
	/**
	 * No filter adds nothing; favourite narrows to starred rows; a tag is
	 * normalised the way stored tags are.
	 *
	 * @return void
	 */
	public function testFilterConditions(): void {
		$filter = new SecretListOrganisation();

		$this->assertSame([], $filter->conditions(null, null));
		$this->assertSame([], $filter->conditions(false, ''));
		$this->assertSame(['favourite' => true], $filter->conditions(true, null));
		$this->assertSame(['tag' => 'on call'], $filter->conditions(null, '  On Call '));
		$this->assertSame(['favourite' => true, 'tag' => 'finance'], $filter->conditions(true, 'FINANCE'));
	}//end testFilterConditions()

	/**
	 * Last used puts never-used rows after used ones in both directions,
	 * then breaks ties by name; other columns sort plainly then by name.
	 *
	 * @return void
	 */
	public function testLastUsedSortsNeverUsedLast(): void {
		$filter = new SecretListOrganisation();

		$this->assertSame(
			[[SecretListOrganisation::NEVER_USED_LAST, 'ASC'], ['last_used_at', 'DESC'], ['name', 'ASC']],
			$filter->orderTerms('last_used_at', 'DESC')
		);
		$this->assertSame(
			[[SecretListOrganisation::NEVER_USED_LAST, 'ASC'], ['last_used_at', 'ASC'], ['name', 'ASC']],
			$filter->orderTerms('last_used_at', 'ASC')
		);
		$this->assertSame([['name', 'DESC']], $filter->orderTerms('name', 'DESC'));
		$this->assertSame([['updated_at', 'ASC'], ['name', 'ASC']], $filter->orderTerms('updated_at', 'ASC'));
	}//end testLastUsedSortsNeverUsedLast()

	/**
	 * The never-used term is applied as a SQL function and first, the other
	 * terms follow as further order columns in their own direction.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-sort-by-date-last-used
	 */
	public function testOrderAppliesTheTermsToTheQuery(): void {
		$neverUsedLast = $this->createMock(IQueryFunction::class);
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->expects($this->once())->method('createFunction')
			->with(SecretListOrganisation::NEVER_USED_LAST)
			->willReturn($neverUsedLast);
		$qb->expects($this->once())->method('orderBy')->with($neverUsedLast, 'ASC');
		$added = [];
		$qb->expects($this->exactly(2))->method('addOrderBy')
			->willReturnCallback(function ($sort, $dir) use (&$added, $qb) {
				$added[] = [$sort, $dir];
				return $qb;
			});

		(new SecretListOrganisation())->order($qb, 'last_used_at', 'DESC');

		$this->assertSame([['last_used_at', 'DESC'], ['name', 'ASC']], $added);
	}//end testOrderAppliesTheTermsToTheQuery()

	/**
	 * A plain column orders by itself first and by name after, with no SQL function.
	 *
	 * @return void
	 */
	public function testOrderByAPlainColumnUsesNoFunction(): void {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->expects($this->never())->method('createFunction');
		$qb->expects($this->once())->method('orderBy')->with('updated_at', 'ASC');
		$qb->expects($this->once())->method('addOrderBy')->with('name', 'ASC');

		(new SecretListOrganisation())->order($qb, 'updated_at', 'ASC');
	}//end testOrderByAPlainColumnUsesNoFunction()
}//end class
