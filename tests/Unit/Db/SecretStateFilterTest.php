<?php

/**
 * Unit tests for the trash/archive state filter (vault-trash-and-archive D3).
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

use InvalidArgumentException;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretStateFilter;
use PHPUnit\Framework\TestCase;

/**
 * Each state names exactly the conditions it means.
 */
class SecretStateFilterTest extends TestCase {
	/**
	 * The conditions a state means.
	 *
	 * @param string|null $state The state
	 *
	 * @return array<string,bool>
	 */
	private function conditions(?string $state): array {
		return (new SecretStateFilter())->conditions($state);
	}//end conditions()

	/**
	 * Live excludes trashed and archived; trashed is any trashed row;
	 * archived excludes trashed; kept is everything not trashed; null adds nothing.
	 *
	 * @return void
	 */
	public function testEachStateMeansItsConditions(): void {
		$this->assertSame(['trashed_at' => false, 'archived_at' => false], $this->conditions(SecretMapper::STATE_LIVE));
		$this->assertSame(['trashed_at' => true], $this->conditions(SecretMapper::STATE_TRASHED));
		$this->assertSame(['trashed_at' => false, 'archived_at' => true], $this->conditions(SecretMapper::STATE_ARCHIVED));
		$this->assertSame(['trashed_at' => false], $this->conditions(SecretMapper::STATE_KEPT));
		$this->assertSame([], $this->conditions(null));
	}//end testEachStateMeansItsConditions()

	/**
	 * An unknown state is refused, never silently widened.
	 *
	 * @return void
	 */
	public function testUnknownStateIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->conditions('everything');
	}//end testUnknownStateIsRefused()
}//end class
