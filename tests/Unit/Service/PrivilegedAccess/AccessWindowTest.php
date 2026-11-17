<?php

/**
 * Unit tests for AccessWindow — the approved time window of a privileged login.
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
use InvalidArgumentException;
use OCA\Keepiq\Service\PrivilegedAccess\AccessWindow;
use OCA\Keepiq\Service\PrivilegedAccess\AddressRange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/capability-requirements-from-the-matrix/tasks.md#task-3.1
 */
#[CoversClass(AccessWindow::class)]
#[UsesClass(AddressRange::class)]
class AccessWindowTest extends TestCase {

	/**
	 * A moment on the scenario's day, in UTC.
	 *
	 * @param string $time `H:i`.
	 *
	 * @return DateTimeImmutable
	 */
	private static function at(string $time): DateTimeImmutable {
		return new DateTimeImmutable('2026-11-16T' . $time . ':00+00:00');
	}//end at()

	/**
	 * The requested window: 14:00 to 16:00.
	 *
	 * @return AccessWindow
	 */
	private static function requested(): AccessWindow {
		return new AccessWindow(startsAt: self::at('14:00'), endsAt: self::at('16:00'));
	}//end requested()

	public function testTheWindowStartsInclusiveAndEndsExclusive(): void {
		$window = self::requested();

		$this->assertFalse($window->allows(now: self::at('13:59'), address: '192.0.2.10'));
		$this->assertTrue($window->allows(now: self::at('14:00'), address: '192.0.2.10'));
		$this->assertTrue($window->allows(now: self::at('15:59'), address: '192.0.2.10'));
		$this->assertFalse($window->allows(now: self::at('16:00'), address: '192.0.2.10'));
	}//end testTheWindowStartsInclusiveAndEndsExclusive()

	public function testAWindowInAnotherZoneComparesAsTheSameMoment(): void {
		$window = self::requested();

		$this->assertTrue($window->allows(now: new DateTimeImmutable('2026-11-16T15:30:00+01:00'), address: '192.0.2.10'));
		$this->assertFalse($window->allows(now: new DateTimeImmutable('2026-11-16T17:30:00+01:00'), address: '192.0.2.10'));
	}//end testAWindowInAnotherZoneComparesAsTheSameMoment()

	public function testAWindowThatDoesNotEndAfterItStartsIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		new AccessWindow(startsAt: self::at('16:00'), endsAt: self::at('16:00'));
	}//end testAWindowThatDoesNotEndAfterItStartsIsRefused()

	public function testAnApprovalForTheOfficeRangeAllowsOnlyThatRange(): void {
		$approved = AccessWindow::approve(requested: self::requested(), ranges: ['192.0.2.0/24']);

		$this->assertTrue($approved->allows(now: self::at('14:30'), address: '192.0.2.44'));
		$this->assertFalse($approved->allows(now: self::at('14:30'), address: '198.51.100.7'));
		$this->assertFalse($approved->allows(now: self::at('16:30'), address: '192.0.2.44'));
		$this->assertSame(['192.0.2.0/24'], $approved->rangeStrings());
	}//end testAnApprovalForTheOfficeRangeAllowsOnlyThatRange()

	public function testAnApprovalMayNameSeveralRangesIncludingIpv6(): void {
		$approved = AccessWindow::approve(requested: self::requested(), ranges: ['192.0.2.0/24', '2001:db8:abcd::/48']);

		$this->assertTrue($approved->allows(now: self::at('15:00'), address: '2001:db8:abcd:1::5'));
		$this->assertTrue($approved->allows(now: self::at('15:00'), address: '192.0.2.1'));
		$this->assertFalse($approved->allows(now: self::at('15:00'), address: '2001:db8:dead::5'));
		$this->assertSame(['192.0.2.0/24', '2001:db8:abcd::/48'], $approved->rangeStrings());
	}//end testAnApprovalMayNameSeveralRangesIncludingIpv6()

	public function testAnApprovalWithoutRangesAllowsAnyAddressInTheWindow(): void {
		$approved = AccessWindow::approve(requested: self::requested());

		$this->assertTrue($approved->allows(now: self::at('15:00'), address: '2001:db8::1'));
		$this->assertSame([], $approved->rangeStrings());
		$this->assertEquals(self::at('14:00'), $approved->startsAt);
		$this->assertEquals(self::at('16:00'), $approved->endsAt);
	}//end testAnApprovalWithoutRangesAllowsAnyAddressInTheWindow()

	public function testAnApprovalMayNarrowTheWindow(): void {
		$approved = AccessWindow::approve(requested: self::requested(), startsAt: self::at('14:30'), endsAt: self::at('15:00'));

		$this->assertFalse($approved->allows(now: self::at('14:15'), address: '192.0.2.1'));
		$this->assertTrue($approved->allows(now: self::at('14:45'), address: '192.0.2.1'));
		$this->assertFalse($approved->allows(now: self::at('15:30'), address: '192.0.2.1'));
	}//end testAnApprovalMayNarrowTheWindow()

	public function testAnApprovalStartingBeforeTheRequestIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		AccessWindow::approve(requested: self::requested(), startsAt: self::at('13:00'));
	}//end testAnApprovalStartingBeforeTheRequestIsRefused()

	public function testAnApprovalEndingAfterTheRequestIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		AccessWindow::approve(requested: self::requested(), endsAt: self::at('16:01'));
	}//end testAnApprovalEndingAfterTheRequestIsRefused()

	public function testAnApprovalWithARangeThatDoesNotParseIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		AccessWindow::approve(requested: self::requested(), ranges: ['192.0.2.0/24', 'the office']);
	}//end testAnApprovalWithARangeThatDoesNotParseIsRefused()
}//end class
