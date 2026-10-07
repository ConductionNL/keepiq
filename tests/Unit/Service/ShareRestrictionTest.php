<?php

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use DateTime;
use DateTimeZone;
use InvalidArgumentException;
use OCA\Keepiq\Service\ShareRestriction;
use OCA\Keepiq\Service\ShareRestrictionRules;
use PHPUnit\Framework\TestCase;

/**
 * Reading the two options from a request, and combining grants
 * (sharing-use-only-and-expiring-shares D1, D2).
 *
 * @spec openspec/changes/archive/2026-10-04-sharing-use-only-and-expiring-shares/tasks.md#task-1.2
 */
class ShareRestrictionTest extends TestCase {

	/**
	 * The fixed "now" of every case.
	 *
	 * @return DateTime
	 */
	private function now(): DateTime {
		return new DateTime('2026-10-02T12:00:00', new DateTimeZone('UTC'));
	}

	/**
	 * A date in the past is refused.
	 *
	 * @return void
	 */
	public function testAPastEndDateIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		(new ShareRestrictionRules())->fromRequest(useOnly: false, expiresAt: '2026-10-01T12:00:00Z', now: $this->now());
	}

	/**
	 * Now itself is not in the future either.
	 *
	 * @return void
	 */
	public function testAnEndDateOfNowIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		(new ShareRestrictionRules())->fromRequest(useOnly: false, expiresAt: '2026-10-02T12:00:00Z', now: $this->now());
	}

	/**
	 * Garbage is refused, not read as "no end".
	 *
	 * @return void
	 */
	public function testAnUnreadableDateIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		(new ShareRestrictionRules())->fromRequest(useOnly: false, expiresAt: 'next tuesday-ish!!', now: $this->now());
	}

	/**
	 * A future date and the flag are carried; empty means no end.
	 *
	 * @return void
	 */
	public function testAFutureDateAndTheFlagAreRead(): void {
		$restriction = (new ShareRestrictionRules())->fromRequest(useOnly: 'true', expiresAt: '2026-10-09T17:00:00+02:00', now: $this->now());
		$this->assertTrue($restriction->useOnly);
		$this->assertSame('2026-10-09T15:00:00+00:00', $restriction->expiresAt?->format('c'));

		$open = (new ShareRestrictionRules())->fromRequest(useOnly: null, expiresAt: '', now: $this->now());
		$this->assertFalse($open->useOnly);
		$this->assertNull($open->expiresAt);
		$this->assertFalse($open->isRestricted());
	}

	/**
	 * Use-only only when every grant is; the latest end wins; no end wins.
	 *
	 * @return void
	 */
	public function testTheMostGenerousGrantWins(): void {
		$early = new DateTime('2026-10-05T00:00:00Z');
		$late  = new DateTime('2026-10-20T00:00:00Z');

		$both = (new ShareRestrictionRules())->combine(
			[new ShareRestriction(true, $early), new ShareRestriction(true, $late)]
		);
		$this->assertTrue($both->useOnly);
		$this->assertEquals($late, $both->expiresAt);

		$lifted = (new ShareRestrictionRules())->combine(
			[new ShareRestriction(true, $early), new ShareRestriction(false, $early)]
		);
		$this->assertFalse($lifted->useOnly, 'an unrestricted grant lifts use-only');

		$open = (new ShareRestrictionRules())->combine(
			[new ShareRestriction(true, $early), new ShareRestriction(true, null)]
		);
		$this->assertNull($open->expiresAt, 'a grant without an end date wins');

		$none = (new ShareRestrictionRules())->combine([]);
		$this->assertFalse($none->isRestricted());
	}
}
