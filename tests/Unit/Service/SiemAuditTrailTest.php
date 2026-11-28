<?php

/**
 * SiemAuditTrail and SuiteLifecycleEventRegistrar: the audit events an
 * administrator's SIEM sink changes record, held to the audit whitelist,
 * and the listeners every suite lifecycle event reaches.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditMetadataPolicy;
use OCA\Keepiq\Event\EncryptionSuiteRevokedEvent;
use OCA\Keepiq\Event\SuiteMigrationAbortedEvent;
use OCA\Keepiq\Event\SuiteMigrationCompletedEvent;
use OCA\Keepiq\Event\SuiteMigrationStartedEvent;
use OCA\Keepiq\Listener\SuiteLifecycleEventRegistrar;
use OCA\Keepiq\Service\SiemAuditTrail;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\EventDispatcher\IEventListener;
use PHPUnit\Framework\TestCase;

/**
 * The SIEM audit trail and the suite lifecycle listeners.
 */
class SiemAuditTrailTest extends TestCase {
	/**
	 * The events dispatched so far.
	 *
	 * @var array<int, AuditEvent>
	 */
	private array $events = [];

	/**
	 * A trail whose dispatcher records every event.
	 *
	 * @return SiemAuditTrail
	 */
	private function trail(): SiemAuditTrail {
		$dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (AuditEvent $event): void {
				$this->events[] = $event;
			}
		);
		return new SiemAuditTrail(eventDispatcher: $dispatcher);
	}//end trail()

	/**
	 * Each sink change records its event against the sink, by the administrator.
	 *
	 * @return void
	 */
	public function testEachSinkChangeIsRecorded(): void {
		$trail = $this->trail();
		$trail->recordSinkCreated(actorId: 'admin', sinkId: 'sink-1', type: 'syslog');
		$trail->recordSinkUpdated(actorId: 'admin', sinkId: 'sink-1', type: 'splunk_hec');
		$trail->recordSinkUpdated(actorId: 'admin', sinkId: 'sink-1');
		$trail->recordSinkTested(actorId: 'admin', sinkId: 'sink-1', outcome: 'ok');
		$trail->recordSinkDeleted(actorId: 'admin', sinkId: 'sink-1');
		$this->assertSame(
			expected: [
				['siem.sink_created', ['sinkId' => 'sink-1', 'type' => 'syslog']],
				['siem.sink_updated', ['sinkId' => 'sink-1', 'type' => 'splunk_hec']],
				['siem.sink_updated', ['sinkId' => 'sink-1']],
				['siem.sink_tested', ['sinkId' => 'sink-1', 'outcome' => 'ok']],
				['siem.sink_deleted', ['sinkId' => 'sink-1']],
			],
			actual: array_map(static fn (AuditEvent $event): array => [$event->getEventType(), $event->getMetadata()], $this->events)
		);
		foreach ($this->events as $event) {
			$this->assertSame(expected: ['admin', 'siem_sink', 'sink-1'], actual: [$event->getActorId(), $event->getObjectType(), $event->getObjectId()]);
		}
	}//end testEachSinkChangeIsRecorded()

	/**
	 * Every metadata key the trail writes is one the audit whitelist allows for that event.
	 *
	 * @return void
	 */
	public function testTheMetadataStaysWithinTheWhitelist(): void {
		$trail = $this->trail();
		$trail->recordSinkCreated(actorId: 'admin', sinkId: 'sink-1', type: 'syslog');
		$trail->recordSinkUpdated(actorId: 'admin', sinkId: 'sink-1', type: 'syslog');
		$trail->recordSinkTested(actorId: 'admin', sinkId: 'sink-1', outcome: 'failed');
		$trail->recordSinkDeleted(actorId: 'admin', sinkId: 'sink-1');
		foreach ($this->events as $event) {
			$this->assertTrue(condition: AuditMetadataPolicy::isKnown(eventType: $event->getEventType()), message: $event->getEventType());
			$allowed = AuditMetadataPolicy::WHITELIST[$event->getEventType()];
			$this->assertSame(expected: [], actual: array_diff(array_keys($event->getMetadata()), $allowed), message: $event->getEventType());
		}
	}//end testTheMetadataStaysWithinTheWhitelist()

	/**
	 * Without a dispatcher the trail records nothing and does not fail.
	 *
	 * @return void
	 */
	public function testWithoutADispatcherNothingIsRecorded(): void {
		(new SiemAuditTrail())->recordSinkDeleted(actorId: 'admin', sinkId: 'sink-1');
		$this->assertSame(expected: [], actual: $this->events);
	}//end testWithoutADispatcherNothingIsRecorded()

	/**
	 * The listeners each suite lifecycle event reaches, by short name.
	 *
	 * @return array<string, array<int, string>>
	 */
	private function suiteListeners(): array {
		$pairs   = [];
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			function (string $event, string $listener) use (&$pairs): void {
				$this->assertTrue(condition: is_subclass_of($listener, IEventListener::class), message: $listener);
				$pairs[$event][] = substr((string)strrchr($listener, '\\'), 1);
			}
		);
		(new SuiteLifecycleEventRegistrar())->register(context: $context);
		return $pairs;
	}//end suiteListeners()

	/**
	 * A completed migration rotates emergency access and recovery and records the compromise.
	 *
	 * @return void
	 */
	public function testTheSuiteLifecycleListeners(): void {
		$listeners = $this->suiteListeners();
		$this->assertSame(expected: ['SuiteMigrationStartedListener'], actual: $listeners[SuiteMigrationStartedEvent::class]);
		$this->assertSame(expected: ['SuiteMigrationAbortedListener'], actual: $listeners[SuiteMigrationAbortedEvent::class]);
		$this->assertSame(
			expected: ['SuiteMigrationCompletedListener', 'SuiteCompromiseListener', 'EmergencyAccessSuiteRotationListener', 'RecoverySuiteListener'],
			actual: $listeners[SuiteMigrationCompletedEvent::class]
		);
		$this->assertSame(
			expected: ['EncryptionSuiteRevokedListener', 'EmergencyAccessSuiteRevocationListener', 'RecoverySuiteListener'],
			actual: $listeners[EncryptionSuiteRevokedEvent::class]
		);
	}//end testTheSuiteLifecycleListeners()
}//end class
