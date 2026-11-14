<?php

/**
 * Unit tests for the transport details HoneyTripwireListener hands on.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Listener;

use OCA\Keepiq\Db\LinkShareMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Listener\HoneyTripwireListener;
use OCA\Keepiq\Service\HoneyAccess;
use OCA\Keepiq\Service\HoneyTripwireService;
use OCA\Keepiq\Service\ShareQuarantineScanner;
use OCA\Keepiq\Service\ShareRowJudge;
use OCP\EventDispatcher\Event;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * An empty remote address or user agent is handed on as unknown, a long
 * user agent is cut at 512 characters, and a non-audit event is ignored.
 *
 * @covers \OCA\Keepiq\Listener\HoneyTripwireListener
 * @uses   \OCA\Keepiq\Event\Audit\AuditEvent
 * @uses   \OCA\Keepiq\Service\HoneyAccess
 * @uses   \OCA\Keepiq\Service\ShareRowJudge
 */
class HoneyTripwireTransportTest extends TestCase {

	/**
	 * The accesses raised, in order.
	 *
	 * @var list<HoneyAccess>
	 */
	private array $raised = [];

	/**
	 * A listener whose request answers with the given address and user agent.
	 *
	 * @param string $remoteAddress The remote address.
	 * @param string $userAgent     The User-Agent header.
	 * @param bool   $consulted     Whether the request may be read at all.
	 *
	 * @return HoneyTripwireListener
	 */
	private function listener(string $remoteAddress, string $userAgent, bool $consulted = true): HoneyTripwireListener {
		$honey = $this->createMock(HoneyTripwireService::class);
		$honey->method('raiseAlert')->willReturnCallback(
			function (HoneyAccess $access): bool {
				$this->raised[] = $access;

				return true;
			}
		);
		$request = $this->createMock(IRequest::class);
		$times = self::never();
		if ($consulted === true) {
			$times = self::once();
		}

		$request->expects($times)->method('getRemoteAddress')->willReturn($remoteAddress);
		$request->expects($consulted === true ? self::once() : self::never())->method('getHeader')->with('User-Agent')->willReturn($userAgent);

		return new HoneyTripwireListener(
			honeyService: $honey,
			linkShareMapper: $this->createMock(LinkShareMapper::class),
			request: $request,
			logger: new NullLogger(),
			rowJudge: new ShareRowJudge(
				targets: $this->createMock(ShareTargetMapper::class),
				secrets: $this->createMock(SecretMapper::class),
				scanner: $this->createMock(ShareQuarantineScanner::class)
			),
		);
	}//end listener()

	/**
	 * A read of a flagged secret by bob.
	 *
	 * @return AuditEvent
	 */
	private static function read(): AuditEvent {
		return AuditEvent::forUser(actorId: 'bob', eventType: AuditEventTypes::SECRET_READ, objectType: 'secret', objectId: 'secret-1');
	}//end read()

	/**
	 * Without a remote address or user agent both are handed on as null.
	 *
	 * @return void
	 */
	public function testAnEmptyAddressAndUserAgentAreUnknown(): void {
		$this->listener(remoteAddress: '', userAgent: '')->handle(self::read());

		self::assertCount(1, $this->raised);
		self::assertSame(
			['secret-1', 'user', 'bob', 'ui', null, null],
			[$this->raised[0]->secretId, $this->raised[0]->accessorType, $this->raised[0]->accessorId, $this->raised[0]->channel, $this->raised[0]->remoteIp, $this->raised[0]->userAgent]
		);
	}//end testAnEmptyAddressAndUserAgentAreUnknown()

	/**
	 * A user agent is cut at 512 characters; the address is passed as is.
	 *
	 * @return void
	 */
	public function testALongUserAgentIsCutAt512(): void {
		$this->listener(remoteAddress: '2001:db8::1', userAgent: str_repeat('a', 511) . 'bc')->handle(self::read());

		self::assertSame(['2001:db8::1', str_repeat('a', 511) . 'b'], [$this->raised[0]->remoteIp, $this->raised[0]->userAgent]);
	}//end testALongUserAgentIsCutAt512()

	/**
	 * An event that is not an audit event raises nothing and never reads the request.
	 *
	 * @return void
	 */
	public function testANonAuditEventIsIgnored(): void {
		$this->listener(remoteAddress: '192.0.2.7', userAgent: 'Agent', consulted: false)->handle(new Event());

		self::assertSame([], $this->raised);
	}//end testANonAuditEventIsIgnored()
}//end class
