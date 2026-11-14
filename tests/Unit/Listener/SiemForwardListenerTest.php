<?php

/**
 * Unit tests for SiemForwardListener: forwards whitelisted audit events, fail-soft.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Listener;

use OCA\Keepiq\Db\SiemQueueItemMapper;
use OCA\Keepiq\Db\SiemSinkMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\SuiteMigrationEvent;
use OCA\Keepiq\Event\SuiteMigrationStartedEvent;
use OCA\Keepiq\Listener\SiemForwardListener;
use OCA\Keepiq\Service\SiemService;
use OCA\Keepiq\Service\SiemSinkService;
use OCA\Keepiq\Service\SiemTransport;
use OCP\EventDispatcher\Event;
use OCP\IGroupManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for SiemForwardListener.
 */
#[CoversClass(SiemForwardListener::class)]
#[UsesClass(SiemService::class)]
#[UsesClass(AuditEvent::class)]
#[UsesClass(SuiteMigrationEvent::class)]
#[UsesClass(SuiteMigrationStartedEvent::class)]
class SiemForwardListenerTest extends TestCase {
	/**
	 * Build the listener over the real SIEM service with mocked storage.
	 *
	 * @param SiemSinkMapper  $sinks  The sink mapper
	 * @param LoggerInterface $logger The listener logger
	 *
	 * @return SiemForwardListener
	 */
	private function listener(SiemSinkMapper $sinks, LoggerInterface $logger): SiemForwardListener {
		$service = new SiemService(
			$sinks,
			$this->createMock(SiemQueueItemMapper::class),
			$this->createMock(SiemTransport::class),
			$this->createMock(SiemSinkService::class),
			$this->createMock(IGroupManager::class),
			null,
			$this->createMock(LoggerInterface::class)
		);

		return new SiemForwardListener($service, $logger);
	}//end listener()

	/**
	 * A whitelisted audit event is offered to the enabled sinks.
	 *
	 * @return void
	 */
	public function testWhitelistedAuditEventIsEnqueued(): void {
		$sinks = $this->createMock(SiemSinkMapper::class);
		$sinks->expects($this->once())->method('findEnabled')->willReturn([]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');

		$this->listener($sinks, $logger)->handle(new AuditEvent('user', 'alice', 'secret.created', 'secret', 's-1'));
	}//end testWhitelistedAuditEventIsEnqueued()

	/**
	 * An event type outside the whitelist builds no payload and queues nothing.
	 *
	 * @return void
	 */
	public function testUnlistedAuditEventIsNotEnqueued(): void {
		$sinks = $this->createMock(SiemSinkMapper::class);
		$sinks->expects($this->never())->method('findEnabled');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');

		$this->listener($sinks, $logger)->handle(new AuditEvent('user', 'alice', 'not.a.listed.type', 'secret', 's-1'));
	}//end testUnlistedAuditEventIsNotEnqueued()

	/**
	 * A failing enqueue is logged, never thrown into the audited action.
	 *
	 * @return void
	 */
	public function testForwardFailureIsLoggedNotThrown(): void {
		$sinks = $this->createMock(SiemSinkMapper::class);
		$sinks->method('findEnabled')->willThrowException(new RuntimeException('db down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('Keepiq: SIEM forward failed: db down', ['app' => 'keepiq']);

		$this->listener($sinks, $logger)->handle(new AuditEvent('user', 'alice', 'secret.created', 'secret', 's-1'));
	}//end testForwardFailureIsLoggedNotThrown()

	/**
	 * Non-audit events are ignored.
	 *
	 * @return void
	 */
	public function testIgnoresNonAuditEvents(): void {
		$sinks = $this->createMock(SiemSinkMapper::class);
		$sinks->expects($this->never())->method('findEnabled');

		$listener = $this->listener($sinks, $this->createMock(LoggerInterface::class));
		$listener->handle(new SuiteMigrationStartedEvent('a', 'b', 'c'));
		$listener->handle(new Event());
	}//end testIgnoresNonAuditEvents()
}//end class
