<?php

/**
 * Unit tests for ApplicationAuditTrail.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
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

namespace OCA\Keepiq\Tests\Unit\Service;

use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Service\ApplicationAuditTrail;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the application lifecycle audit events.
 *
 * @covers \OCA\Keepiq\Service\ApplicationAuditTrail
 * @uses   \OCA\Keepiq\Event\Audit\AuditEvent
 * @uses   \OCA\Keepiq\Event\Audit\AuditEventFactory
 */
class ApplicationAuditTrailTest extends TestCase {
	/**
	 * @param array<int, AuditEvent> $captured
	 */
	private function trail(array &$captured): ApplicationAuditTrail {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->once())
			->method('dispatchTyped')
			->willReturnCallback(static function (AuditEvent $event) use (&$captured): void {
				$captured[] = $event;
			});

		return new ApplicationAuditTrail($dispatcher);
	}

	private function assertEvent(
		AuditEvent $event,
		string $actorType,
		?string $actorId,
		string $type,
	): void {
		$this->assertSame($actorType, $event->getActorType());
		$this->assertSame($actorId, $event->getActorId());
		$this->assertSame($type, $event->getEventType());
		$this->assertSame('application', $event->getObjectType());
		$this->assertSame('app-1', $event->getObjectId());
		$this->assertSame('Billing', $event->getObjectName());
		$this->assertSame([], $event->getMetadata());
	}

	public function testRegisteredByAUserIsActoredByThatUser(): void {
		$captured = [];
		$this->trail($captured)->recordRegistered('alice', 'app-1', 'Billing');

		$this->assertEvent($captured[0], AuditEvent::ACTOR_USER, 'alice', AuditEventTypes::APPLICATION_REGISTERED);
	}

	public function testRegisteredWithoutAUserIsASystemEvent(): void {
		$captured = [];
		$this->trail($captured)->recordRegistered(null, 'app-1', 'Billing');

		$this->assertEvent($captured[0], AuditEvent::ACTOR_SYSTEM, null, AuditEventTypes::APPLICATION_REGISTERED);
	}

	public function testRegisteredWithAnEmptyUserIsASystemEvent(): void {
		$captured = [];
		$this->trail($captured)->recordRegistered('', 'app-1', 'Billing');

		$this->assertEvent($captured[0], AuditEvent::ACTOR_SYSTEM, null, AuditEventTypes::APPLICATION_REGISTERED);
	}

	public function testApprovedIsActoredByTheAdmin(): void {
		$captured = [];
		$this->trail($captured)->recordApproved('admin', 'app-1', 'Billing');

		$this->assertEvent($captured[0], AuditEvent::ACTOR_USER, 'admin', AuditEventTypes::APPLICATION_APPROVED);
	}

	public function testRejectedIsActoredByTheAdmin(): void {
		$captured = [];
		$this->trail($captured)->recordRejected('admin', 'app-1', 'Billing');

		$this->assertEvent($captured[0], AuditEvent::ACTOR_USER, 'admin', AuditEventTypes::APPLICATION_REJECTED);
	}

	public function testDeletedIsASystemEvent(): void {
		$captured = [];
		$this->trail($captured)->recordDeleted('app-1', 'Billing');

		$this->assertEvent($captured[0], AuditEvent::ACTOR_SYSTEM, null, AuditEventTypes::APPLICATION_DELETED);
	}

	public function testWithoutADispatcherNothingHappens(): void {
		$trail = new ApplicationAuditTrail();
		$trail->recordApproved('admin', 'app-1', 'Billing');
		$trail->recordDeleted('app-1', 'Billing');
		$this->addToAssertionCount(1);
	}
}
