<?php

/**
 * Unit tests for AuditListener.
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

use OCA\Keepiq\Db\AuditEntry;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Listener\AuditListener;
use OCA\Keepiq\Service\AuditService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for AuditListener — records AuditEvents and is fail-soft.
 */
class AuditListenerTest extends TestCase {

	/**
	 * The mocked audit service.
	 *
	 * @var AuditService&MockObject
	 */
	private AuditService $auditService;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The listener under test.
	 *
	 * @var AuditListener
	 */
	private AuditListener $listener;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->auditService = $this->createMock(AuditService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->listener = new AuditListener($this->auditService, $this->logger);
	}//end setUp()

	/**
	 * An AuditEvent is recorded through the service.
	 *
	 * @return void
	 */
	public function testRecordsAuditEvent(): void {
		$event = AuditEvent::forUser(
			actorId: 'alice',
			eventType: AuditEventTypes::SECRET_CREATED,
			objectType: 'secret',
			objectId: 'sec-1',
			objectName: 'X',
		);

		$this->auditService->expects($this->once())
			->method('record')
			->with($event)
			->willReturn(new AuditEntry());

		$this->listener->handle($event);
	}//end testRecordsAuditEvent()

	/**
	 * A record() failure is swallowed and logged at error level — it MUST NOT
	 * propagate into the audited business operation (fail-soft requirement).
	 *
	 * @return void
	 */
	public function testRecordFailureIsSwallowedAndLogged(): void {
		$event = AuditEvent::forUser(
			actorId: 'alice',
			eventType: AuditEventTypes::SECRET_CREATED,
			objectType: 'secret',
			objectId: 'sec-1',
			objectName: 'X',
		);

		$this->auditService->method('record')
			->willThrowException(new RuntimeException('db down'));

		$this->logger->expects($this->once())->method('error');

		// Must not throw.
		$this->listener->handle($event);
		$this->addToAssertionCount(1);
	}//end testRecordFailureIsSwallowedAndLogged()
}//end class
