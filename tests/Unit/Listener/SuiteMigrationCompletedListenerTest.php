<?php

/**
 * Unit tests for SuiteMigrationCompletedListener: unlocks requests and moves them to the new suite.
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
use OCA\Keepiq\Listener\SuiteMigrationCompletedListener;
use OCA\Keepiq\Service\SecretRequestSuiteLockService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for SuiteMigrationCompletedListener.
 */
#[CoversClass(SuiteMigrationCompletedListener::class)]
#[UsesClass(SecretRequestSuiteLockService::class)]
#[UsesClass(SuiteMigrationEvent::class)]
#[UsesClass(SuiteMigrationStartedEvent::class)]
#[UsesClass(SuiteMigrationCompletedEvent::class)]
class SuiteMigrationCompletedListenerTest extends TestCase {
	/**
	 * Completion unlocks the old suite's requests onto the new suite, in that order.
	 *
	 * @return void
	 */
	public function testUnlocksOntoTheNewSuite(): void {
		$mapper = $this->createMock(SecretRequestMapper::class);
		$mapper->expects($this->once())->method('unlockAndUpdateSuite')->with('old-suite', 'new-suite')->willReturn(5);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');
		$logger->expects($this->once())->method('info')->with(
			'Keepiq: unlocked SecretRequests after compromise recovery',
			['oldSuiteId' => 'old-suite', 'newSuiteId' => 'new-suite', 'unlocked' => 5]
		);

		$listener = new SuiteMigrationCompletedListener(
			new SecretRequestSuiteLockService($mapper, $this->createMock(LoggerInterface::class)),
			$logger
		);
		$listener->handle(new SuiteMigrationCompletedEvent('old-suite', 'new-suite', 'mig-1'));
	}//end testUnlocksOntoTheNewSuite()

	/**
	 * A completion naming the same suite twice is refused by the service; the
	 * listener logs that refusal instead of throwing.
	 *
	 * @return void
	 */
	public function testRefusedUnlockIsLoggedNotThrown(): void {
		$mapper = $this->createMock(SecretRequestMapper::class);
		$mapper->expects($this->never())->method('unlockAndUpdateSuite');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('info');
		$logger->expects($this->once())->method('error')->with(
			'Keepiq: SuiteMigrationCompletedListener failed: oldEncryptionSuiteId and newEncryptionSuiteId must differ',
			$this->callback(static fn (array $ctx): bool => $ctx['exception'] instanceof RuntimeException)
		);

		$listener = new SuiteMigrationCompletedListener(
			new SecretRequestSuiteLockService($mapper, $this->createMock(LoggerInterface::class)),
			$logger
		);
		$listener->handle(new SuiteMigrationCompletedEvent('same', 'same', 'mig-1'));
	}//end testRefusedUnlockIsLoggedNotThrown()

	/**
	 * Other events unlock nothing.
	 *
	 * @return void
	 */
	public function testIgnoresOtherEvents(): void {
		$mapper = $this->createMock(SecretRequestMapper::class);
		$mapper->expects($this->never())->method('unlockAndUpdateSuite');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method($this->anything());

		$listener = new SuiteMigrationCompletedListener(
			new SecretRequestSuiteLockService($mapper, $this->createMock(LoggerInterface::class)),
			$logger
		);
		$listener->handle(new SuiteMigrationStartedEvent('old', 'new', 'mig-1'));
		$listener->handle(new Event());
	}//end testIgnoresOtherEvents()
}//end class
