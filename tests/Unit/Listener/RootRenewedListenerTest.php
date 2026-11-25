<?php

/**
 * Keepiq Root Renewed Listener Test
 *
 * A committed root renewal queues the notices to partners, with the clock's time, and a failure to queue stays in the
 * listener; the federation registrar binds it to RootRenewedEvent.
 *
 * @category Tests
 * @package  OCA\Keepiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-a-renewed-root-is-announced-to-partners
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Listener;

use DateTime;
use OCA\Keepiq\Event\RootRenewedEvent;
use OCA\Keepiq\Listener\FederationEventRegistrar;
use OCA\Keepiq\Listener\RootRenewedListener;
use OCA\Keepiq\Service\RootRenewalNoticeDelivery;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Keepiq\Listener\RootRenewedListener
 */
class RootRenewedListenerTest extends TestCase {
	/**
	 * A clock at a fixed time.
	 *
	 * @param DateTime $now The time
	 *
	 * @return ITimeFactory
	 */
	private function clockAt(DateTime $now): ITimeFactory {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn($now);
		return $time;
	}//end clockAt()

	public function testARenewalQueuesTheNoticesAtTheClocksTime(): void {
		$now = new DateTime('2026-11-25T12:00:00Z');
		$notices = $this->createMock(RootRenewalNoticeDelivery::class);
		$notices->expects($this->once())->method('announce')->with($now)->willReturn(2);
		$listener = new RootRenewedListener($notices, $this->clockAt($now), $this->createMock(LoggerInterface::class));

		$listener->handle(new RootRenewedEvent());
	}

	public function testAnotherEventQueuesNothing(): void {
		$notices = $this->createMock(RootRenewalNoticeDelivery::class);
		$notices->expects($this->never())->method('announce');
		$listener = new RootRenewedListener($notices, $this->clockAt(new DateTime()), $this->createMock(LoggerInterface::class));

		$listener->handle(new Event());
	}

	public function testAFailureToQueueIsLoggedNotThrown(): void {
		$notices = $this->createMock(RootRenewalNoticeDelivery::class);
		$notices->method('announce')->willThrowException(new RuntimeException('partner table gone'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('partner table gone'));
		$listener = new RootRenewedListener($notices, $this->clockAt(new DateTime()), $logger);

		$listener->handle(new RootRenewedEvent());
	}

	public function testTheFederationRegistrarBindsTheListener(): void {
		$pairs = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			function (string $event, string $listener) use (&$pairs): void {
				$pairs[] = [$event, $listener];
			}
		);

		(new FederationEventRegistrar())->register($context);

		$this->assertContains([RootRenewedEvent::class, RootRenewedListener::class], $pairs);
	}
}
