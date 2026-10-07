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
}//end class
