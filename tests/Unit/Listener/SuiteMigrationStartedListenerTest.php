<?php

/**
 * Unit tests for SuiteMigrationStartedListener: locks the secret requests of the old suite.
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

use OCA\Keepiq\Db\SecretRequestMapper;
use OCA\Keepiq\Event\SuiteMigrationCompletedEvent;
use OCA\Keepiq\Event\SuiteMigrationEvent;
use OCA\Keepiq\Event\SuiteMigrationStartedEvent;
use OCA\Keepiq\Listener\SuiteMigrationStartedListener;
use OCA\Keepiq\Service\SecretRequestSuiteLockService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for SuiteMigrationStartedListener.
 */
#[CoversClass(SuiteMigrationStartedListener::class)]
#[UsesClass(SecretRequestSuiteLockService::class)]
#[UsesClass(SuiteMigrationEvent::class)]
#[UsesClass(SuiteMigrationStartedEvent::class)]
#[UsesClass(SuiteMigrationCompletedEvent::class)]
class SuiteMigrationStartedListenerTest extends TestCase {
	/**
	 * A started migration locks the requests of the OLD suite and logs the count.
	 *
	 * @return void
	 */
	public function testLocksRequestsOfTheOldSuite(): void {
		$mapper = $this->createMock(SecretRequestMapper::class);
		$mapper->expects($this->once())->method('lockByEncryptionSuiteId')->with('old-suite')->willReturn(3);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');
		$logger->expects($this->once())->method('info')->with(
			'Keepiq: locked SecretRequests for compromised suite',
			['oldSuiteId' => 'old-suite', 'locked' => 3]
		);

		$listener = new SuiteMigrationStartedListener(
			new SecretRequestSuiteLockService($mapper, $this->createMock(LoggerInterface::class)),
			$logger
		);
		$listener->handle(new SuiteMigrationStartedEvent('old-suite', 'new-suite', 'mig-1'));
	}//end testLocksRequestsOfTheOldSuite()

	/**
	 * A failing lock is logged, never thrown into the migration.
	 *
	 * @return void
	 */
	public function testLockFailureIsLoggedNotThrown(): void {
		$mapper = $this->createMock(SecretRequestMapper::class);
		$mapper->method('lockByEncryptionSuiteId')->willThrowException(new RuntimeException('db down'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('info');
		$logger->expects($this->once())->method('error')->with(
			'Keepiq: SuiteMigrationStartedListener failed: db down',
			$this->callback(static fn (array $ctx): bool => $ctx['exception'] instanceof RuntimeException)
		);

		$listener = new SuiteMigrationStartedListener(
			new SecretRequestSuiteLockService($mapper, $this->createMock(LoggerInterface::class)),
			$logger
		);
		$listener->handle(new SuiteMigrationStartedEvent('old-suite', 'new-suite', 'mig-1'));
	}//end testLockFailureIsLoggedNotThrown()

	/**
	 * Other events, including the other migration steps, lock nothing.
	 *
	 * @return void
	 */
	public function testIgnoresOtherEvents(): void {
		$mapper = $this->createMock(SecretRequestMapper::class);
		$mapper->expects($this->never())->method('lockByEncryptionSuiteId');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method($this->anything());

		$listener = new SuiteMigrationStartedListener(
			new SecretRequestSuiteLockService($mapper, $this->createMock(LoggerInterface::class)),
			$logger
		);
		$listener->handle(new SuiteMigrationCompletedEvent('old', 'new', 'mig-1'));
		$listener->handle(new Event());
	}//end testIgnoresOtherEvents()
}//end class
