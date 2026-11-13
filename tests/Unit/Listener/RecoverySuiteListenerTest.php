<?php

/**
 * Unit tests for RecoverySuiteListener.
 *
 * A completed migration drops the recovery enrolments of the old suite; a
 * revoked suite drops its enrolments and ends its open recovery requests. A
 * failing clean-up is logged and never breaks the suite change.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Listener
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

namespace OCA\Keepiq\Tests\Unit\Listener;

use OCA\Keepiq\Event\EncryptionSuiteRevokedEvent;
use OCA\Keepiq\Event\SuiteMigrationCompletedEvent;
use OCA\Keepiq\Event\SuiteMigrationEvent;
use OCA\Keepiq\Event\SuiteMigrationStartedEvent;
use OCA\Keepiq\Listener\RecoverySuiteListener;
use OCA\Keepiq\Service\RecoveryEnrolmentService;
use OCA\Keepiq\Service\RecoveryRequestService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The recovery clean-up that follows a suite change.
 */
#[CoversClass(RecoverySuiteListener::class)]
#[UsesClass(SuiteMigrationCompletedEvent::class)]
#[UsesClass(EncryptionSuiteRevokedEvent::class)]
#[UsesClass(SuiteMigrationStartedEvent::class)]
#[UsesClass(SuiteMigrationEvent::class)]
class RecoverySuiteListenerTest extends TestCase {
	private RecoveryEnrolmentService&MockObject $enrolments;

	private RecoveryRequestService&MockObject $requests;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->enrolments = $this->createMock(RecoveryEnrolmentService::class);
		$this->requests = $this->createMock(RecoveryRequestService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function listener(): RecoverySuiteListener {
		return new RecoverySuiteListener($this->enrolments, $this->requests, $this->logger);
	}

	public function testACompletedMigrationDropsTheOldSuitesEnrolmentsOnly(): void {
		$this->enrolments->expects($this->once())->method('deleteForSuite')->with('old-suite')->willReturn(3);
		$this->requests->expects($this->never())->method('endForSuite');
		$this->logger->expects($this->never())->method('error');

		$this->listener()->handle(new SuiteMigrationCompletedEvent('old-suite', 'new-suite', 'migration-1'));
	}

	public function testARevokedSuiteDropsItsEnrolmentsAndEndsItsRequests(): void {
		$this->enrolments->expects($this->once())->method('deleteForSuite')->with('suite-1')->willReturn(1);
		$this->requests->expects($this->once())->method('endForSuite')->with('suite-1')->willReturn(2);

		$this->listener()->handle(new EncryptionSuiteRevokedEvent('suite-1', 'user', 'alice', 'admin'));
	}

	public function testOtherEventsChangeNothing(): void {
		$this->enrolments->expects($this->never())->method('deleteForSuite');
		$this->requests->expects($this->never())->method('endForSuite');

		$this->listener()->handle(new SuiteMigrationStartedEvent('old', 'new', 'migration-1'));
		$this->listener()->handle(new Event());
	}

	public function testAFailingCleanUpIsLoggedAndSwallowed(): void {
		$failure = new RuntimeException('database gone');
		$this->enrolments->method('deleteForSuite')->willThrowException($failure);
		$this->requests->expects($this->never())->method('endForSuite');
		$this->logger->expects($this->once())->method('error')->with(
			'Keepiq: account recovery clean-up after a suite change failed: database gone',
			['exception' => $failure, 'app' => 'keepiq']
		);

		$this->listener()->handle(new EncryptionSuiteRevokedEvent('suite-1', 'user', 'alice', 'admin'));
	}
}
