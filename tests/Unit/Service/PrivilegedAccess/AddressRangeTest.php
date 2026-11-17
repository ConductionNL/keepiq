<?php

/**
 * Unit tests for AddressRange — the CIDR ranges a privileged login's window may be limited to.
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

use InvalidArgumentException;
use OCA\Keepiq\Service\PrivilegedAccess\AddressRange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/capability-requirements-from-the-matrix/tasks.md#task-3.1
 */
#[CoversClass(AddressRange::class)]
class AddressRangeTest extends TestCase {

	/**
	 * Ranges and addresses with whether the address is inside.
	 *
	 * @return array<string, array{string, string, bool}>
	 */
	public static function membership(): array {
		return [
			'office range, inside' => ['192.0.2.0/24', '192.0.2.17', true],
			'office range, last address' => ['192.0.2.0/24', '192.0.2.255', true],
			'office range, next network' => ['192.0.2.0/24', '192.0.3.1', false],
			'host bits in the range are ignored' => ['192.0.2.77/24', '192.0.2.1', true],
			'an odd prefix splits a byte' => ['198.51.100.0/27', '198.51.100.31', true],
			'an odd prefix, just outside' => ['198.51.100.0/27', '198.51.100.32', false],
			'a bare address is that one address' => ['203.0.113.5', '203.0.113.5', true],
			'a bare address, a neighbour' => ['203.0.113.5', '203.0.113.6', false],
			'zero prefix is every IPv4 address' => ['0.0.0.0/0', '8.8.8.8', true],
			'IPv6, inside' => ['2001:db8:abcd::/48', '2001:db8:abcd:12::1', true],
			'IPv6, outside' => ['2001:db8:abcd::/48', '2001:db8:abce::1', false],
			'IPv6, written in another form' => ['2001:0db8:0000::/32', '2001:db8:ffff::9', true],
			'IPv6, an odd prefix' => ['2001:db8::/33', '2001:db8:8000::1', false],
			'IPv4 never falls in an IPv6 range' => ['::/0', '192.0.2.1', false],
			'IPv6 never falls in an IPv4 range' => ['0.0.0.0/0', '::1', false],
			'an IPv4-mapped address is not the IPv4 one' => ['192.0.2.0/24', '::ffff:192.0.2.1', false],
			'garbage never falls in a range' => ['0.0.0.0/0', 'not-an-ip', false],
			'an empty address never falls in a range' => ['0.0.0.0/0', '', false],
			'an address with a NUL byte never falls in a range' => ['0.0.0.0/0', "192.0.2.1\0trailer", false],
		];
	}//end membership()

	/**
	 * @param string $cidr     The range.
	 * @param string $address  The client address.
	 * @param bool   $expected Whether it is inside.
	 *
	 * @return void
	 */
	#[DataProvider('membership')]
	public function testContains(string $cidr, string $address, bool $expected): void {
		$this->assertSame($expected, AddressRange::parse(cidr: $cidr)->contains(address: $address));
	}//end testContains()

	public function testItIsStoredInCanonicalForm(): void {
		$this->assertSame('192.0.2.0/24', AddressRange::parse(cidr: ' 192.0.2.77/24 ')->toString());
		$this->assertSame('2001:db8::/32', AddressRange::parse(cidr: '2001:0DB8:0000:0000::/32')->toString());
		$this->assertSame('203.0.113.5/32', AddressRange::parse(cidr: '203.0.113.5')->toString());
		$this->assertSame('::1/128', AddressRange::parse(cidr: '::1')->toString());
	}//end testItIsStoredInCanonicalForm()

	/**
	 * Ranges an owner might type that are not ranges.
	 *
	 * @return array<string, array{string}>
	 */
	public static function invalid(): array {
		return [
			'empty' => [''],
			'a host name' => ['office.example.org/24'],
			'an IPv4 prefix past 32' => ['192.0.2.0/33'],
			'an IPv6 prefix past 128' => ['2001:db8::/129'],
			'a negative prefix' => ['192.0.2.0/-1'],
			'a prefix that is not a number' => ['192.0.2.0/abc'],
			'an empty prefix' => ['192.0.2.0/'],
			'an address out of range' => ['192.0.2.256/24'],
			'an address with a NUL byte' => ["192.0.2.0\0/24"],
		];
	}//end invalid()

	/**
	 * @param string $cidr The typed range.
	 *
	 * @return void
	 */
	#[DataProvider('invalid')]
	public function testAnythingElseIsRefused(string $cidr): void {
		$this->expectException(InvalidArgumentException::class);
		AddressRange::parse(cidr: $cidr);
	}//end testAnythingElseIsRefused()
}//end class
