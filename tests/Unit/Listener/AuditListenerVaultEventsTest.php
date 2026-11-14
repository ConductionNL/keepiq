<?php

/**
 * Unit tests for how AuditListener records the vault export, GDPR export and
 * account deletion events.
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

use OCA\Keepiq\Db\AuditEntry;
use OCA\Keepiq\Event\AccountDataDeletedEvent;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Event\GdprExportPerformedEvent;
use OCA\Keepiq\Event\SecretExportedEvent;
use OCA\Keepiq\Listener\AuditListener;
use OCA\Keepiq\Service\AuditService;
use OCA\Keepiq\Service\DeletionReport;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the vault event family of AuditListener.
 *
 * @covers \OCA\Keepiq\Listener\AuditListener
 * @uses   \OCA\Keepiq\Event\Audit\AuditEvent
 * @uses   \OCA\Keepiq\Event\Audit\AuditEventFactory
 * @uses   \OCA\Keepiq\Event\AccountDataDeletedEvent
 * @uses   \OCA\Keepiq\Event\GdprExportPerformedEvent
 * @uses   \OCA\Keepiq\Event\SecretExportedEvent
 * @uses   \OCA\Keepiq\Service\DeletionReport
 * @uses   \OCA\Keepiq\Db\AuditEntry
 */
class AuditListenerVaultEventsTest extends TestCase {

	/**
	 * The mocked audit service.
	 *
	 * @var AuditService&MockObject
	 */
	private AuditService $auditService;

	/**
	 * Audit events recorded, in order.
	 *
	 * @var list<AuditEvent>
	 */
	private array $recorded = [];

	/**
	 * The listener under test.
	 *
	 * @var AuditListener
	 */
	private AuditListener $listener;

	/**
	 * Wire the listener with a recording audit service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->auditService = $this->createMock(originalClassName: AuditService::class);
		$this->auditService->method('record')->willReturnCallback(
			function (AuditEvent $event): AuditEntry {
				$this->recorded[] = $event;

				return new AuditEntry();
			}
		);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->never())->method('error');
		$this->listener = new AuditListener(auditService: $this->auditService, logger: $logger);
	}//end setUp()

	/**
	 * A secret export is recorded as a vault export by the exporting user, with its mode, scope and count.
	 *
	 * @return void
	 */
	public function testASecretExportIsRecorded(): void {
		$this->auditService->expects($this->never())->method('anonymizeUser');

		$this->listener->handle(new SecretExportedEvent(userId: 'ann', mode: 'encrypted', scope: 'all', secretCount: 4));

		$this->assertCount(expectedCount: 1, haystack: $this->recorded);
		$event = $this->recorded[0];
		$this->assertSame(
			expected: ['ann', AuditEventTypes::VAULT_EXPORTED, 'vault', 'ann', null],
			actual: [$event->getActorId(), $event->getEventType(), $event->getObjectType(), $event->getObjectId(), $event->getObjectName()]
		);
		$this->assertSame(expected: ['mode' => 'encrypted', 'scope' => 'all', 'secretCount' => 4], actual: $event->getMetadata());
	}//end testASecretExportIsRecorded()

	/**
	 * A GDPR export is recorded as such, and nothing is anonymised.
	 *
	 * @return void
	 */
	public function testAGdprExportIsRecorded(): void {
		$this->auditService->expects($this->never())->method('anonymizeUser');

		$this->listener->handle(new GdprExportPerformedEvent(userId: 'bob', includesVault: true));

		$this->assertSame(expected: AuditEventTypes::VAULT_GDPR_EXPORTED, actual: $this->recorded[0]->getEventType());
		$this->assertSame(expected: 'bob', actual: $this->recorded[0]->getActorId());
	}//end testAGdprExportIsRecorded()

	/**
	 * A deleted account is recorded with its counts, then its audit entries are anonymised.
	 *
	 * @return void
	 */
	public function testADeletedAccountIsRecordedThenAnonymised(): void {
		$report = new DeletionReport();
		$report->secretsDeleted     = 2;
		$report->secretsTransferred = 1;
		$report->sharesRemoved      = 3;
		$report->requestsDeleted    = 1;
		$report->suitesDeleted      = 1;
		$this->auditService->expects($this->once())->method('anonymizeUser')->with('carol');

		$this->listener->handle(new AccountDataDeletedEvent(userId: 'carol', trigger: 'user-deleted', report: $report));

		$this->assertSame(expected: AuditEventTypes::VAULT_ACCOUNT_DELETED, actual: $this->recorded[0]->getEventType());
		$this->assertSame(
			expected: ['trigger' => 'user-deleted', 'secretCount' => 3, 'shareCount' => 3, 'requestCount' => 1, 'suiteCount' => 1],
			actual: $this->recorded[0]->getMetadata()
		);
	}//end testADeletedAccountIsRecordedThenAnonymised()

	/**
	 * An unrelated event is ignored.
	 *
	 * @return void
	 */
	public function testAnUnrelatedEventIsIgnored(): void {
		$this->auditService->expects($this->never())->method('anonymizeUser');

		$this->listener->handle(new Event());

		$this->assertSame(expected: [], actual: $this->recorded);
	}//end testAnUnrelatedEventIsIgnored()
}//end class
