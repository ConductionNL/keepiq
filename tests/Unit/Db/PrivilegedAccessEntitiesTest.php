<?php

/**
 * Unit tests for the privileged login and access request entities.
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

use DateTime;
use OCA\Keepiq\Db\AccessRequest;
use OCA\Keepiq\Db\PrivilegedLogin;
use OCA\Keepiq\Service\PrivilegedAccess\AccessWindow;
use OCA\Keepiq\Service\PrivilegedAccess\AddressRange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
 */
#[CoversClass(AccessRequest::class)]
#[CoversClass(PrivilegedLogin::class)]
#[UsesClass(AccessWindow::class)]
#[UsesClass(AddressRange::class)]
class PrivilegedAccessEntitiesTest extends TestCase {

	/**
	 * An approved request.
	 *
	 * @param string|null $ranges The stored ranges
	 *
	 * @return AccessRequest
	 */
	private static function approved(?string $ranges): AccessRequest {
		$request = new AccessRequest();
		$request->setId('r1');
		$request->setSecretId('s1');
		$request->setStatus(AccessRequest::STATUS_APPROVED);
		$request->setApprovedStart(new DateTime('2026-11-16T14:00:00+00:00'));
		$request->setApprovedEnd(new DateTime('2026-11-16T16:00:00+00:00'));
		$request->setRanges($ranges);
		return $request;
	}//end approved()

	/**
	 * The approved window is rebuilt with its ranges, start inclusive and end exclusive.
	 *
	 * @return void
	 */
	public function testTheApprovedWindowCarriesItsRanges(): void {
		$window = self::approved(ranges: '["192.0.2.0/24"]')->approvedWindow();

		$this->assertNotNull($window);
		$this->assertTrue($window->allows(now: new DateTime('2026-11-16T14:00:00+00:00'), address: '192.0.2.5'));
		$this->assertFalse($window->allows(now: new DateTime('2026-11-16T16:00:00+00:00'), address: '192.0.2.5'));
		$this->assertFalse($window->allows(now: new DateTime('2026-11-16T15:00:00+00:00'), address: '198.51.100.5'));
	}//end testTheApprovedWindowCarriesItsRanges()

	/**
	 * No ranges, or a stored value that is no list, means any address.
	 *
	 * @return void
	 */
	public function testMissingOrMalformedRangesMeanAnyAddress(): void {
		$this->assertSame([], self::approved(ranges: null)->rangeList());
		$this->assertSame([], self::approved(ranges: '"x"')->rangeList());
		$this->assertSame(['10.0.0.0/8'], self::approved(ranges: '["10.0.0.0/8", 7]')->rangeList());
	}//end testMissingOrMalformedRangesMeanAnyAddress()

	/**
	 * A pending or denied request grants no window.
	 *
	 * @return void
	 */
	public function testOnlyAnApprovedRequestHasAWindow(): void {
		$request = self::approved(ranges: null);
		$request->setStatus(AccessRequest::STATUS_DENIED);
		$this->assertNull($request->approvedWindow());

		$this->assertNull((new AccessRequest())->approvedWindow());
	}//end testOnlyAnApprovedRequestHasAWindow()

	/**
	 * The API form carries the window and ranges, and no secret material.
	 *
	 * @return void
	 */
	public function testSerialisation(): void {
		$data = self::approved(ranges: '["192.0.2.0/24"]')->jsonSerialize();

		$this->assertSame('r1', $data['id']);
		$this->assertSame('approved', $data['status']);
		$this->assertSame('2026-11-16T14:00:00+00:00', $data['approvedStart']);
		$this->assertSame(['192.0.2.0/24'], $data['ranges']);
		$this->assertNull($data['requestedStart']);
		$this->assertSame(
			['id', 'secretId', 'ownerId', 'requesterId', 'reason', 'status', 'requestedStart', 'requestedEnd', 'approvedStart', 'approvedEnd', 'ranges', 'decidedAt', 'createdAt'],
			array_keys($data)
		);

		$flag = new PrivilegedLogin();
		$flag->setSecretId('s1');
		$flag->setCreatedAt(new DateTime('2026-11-16T10:00:00+00:00'));
		$this->assertSame(['secretId' => 's1', 'privileged' => true, 'createdAt' => '2026-11-16T10:00:00+00:00'], $flag->jsonSerialize());
	}//end testSerialisation()
}//end class
