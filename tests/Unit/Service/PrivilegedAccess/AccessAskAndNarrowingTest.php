<?php

/**
 * Unit tests for the AccessAsk and AccessNarrowing carriers.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service\PrivilegedAccess
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

namespace OCA\Keepiq\Tests\Unit\Service\PrivilegedAccess;

use DateTimeImmutable;
use OCA\Keepiq\Service\PrivilegedAccess\AccessAsk;
use OCA\Keepiq\Service\PrivilegedAccess\AccessNarrowing;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The carriers keep what was submitted, unvalidated: validation stays in the service.
 */
#[CoversClass(AccessAsk::class)]
#[CoversClass(AccessNarrowing::class)]
class AccessAskAndNarrowingTest extends TestCase {

	/**
	 * An ask keeps its window and its reason untrimmed, even a reversed window.
	 *
	 * @return void
	 */
	public function testAnAskKeepsWhatWasSubmitted(): void {
		$start = new DateTimeImmutable('2026-11-16T16:00:00+00:00');
		$end = new DateTimeImmutable('2026-11-16T14:00:00+00:00');

		$ask = new AccessAsk(startsAt: $start, endsAt: $end, reason: '  incident 4711 ');

		$this->assertSame($start, $ask->startsAt);
		$this->assertSame($end, $ask->endsAt);
		$this->assertSame('  incident 4711 ', $ask->reason);
	}//end testAnAskKeepsWhatWasSubmitted()

	/**
	 * A default narrowing approves as asked, from any address.
	 *
	 * @return void
	 */
	public function testADefaultNarrowingNarrowsNothing(): void {
		$narrowing = new AccessNarrowing();

		$this->assertNull($narrowing->startsAt);
		$this->assertNull($narrowing->endsAt);
		$this->assertSame([], $narrowing->ranges);
	}//end testADefaultNarrowingNarrowsNothing()

	/**
	 * A narrowing keeps each part it was given.
	 *
	 * @return void
	 */
	public function testANarrowingKeepsItsParts(): void {
		$end = new DateTimeImmutable('2026-11-16T15:00:00+00:00');

		$narrowing = new AccessNarrowing(endsAt: $end, ranges: ['192.0.2.0/24']);

		$this->assertNull($narrowing->startsAt);
		$this->assertSame($end, $narrowing->endsAt);
		$this->assertSame(['192.0.2.0/24'], $narrowing->ranges);
	}//end testANarrowingKeepsItsParts()
}//end class
