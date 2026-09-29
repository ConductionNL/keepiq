<?php

/**
 * Unit tests for SuiteMigrationAbortedListener.
 *
 * On an abort the vault returns to the OLD suite, so the listener releases the
 * SecretRequests locked at migration start while keeping them on that suite
 * (unlockInPlace(old)). It must ignore any other event.
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
use OCA\Keepiq\Event\SuiteMigrationAbortedEvent;
use OCA\Keepiq\Event\SuiteMigrationStartedEvent;
use OCA\Keepiq\Listener\SuiteMigrationAbortedListener;
use OCA\Keepiq\Service\SecretRequestSuiteLockService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for SuiteMigrationAbortedListener.
 */
class SuiteMigrationAbortedListenerTest extends TestCase {
	public function testUnlocksRequestsKeepingTheOldSuite(): void {
		// The REAL lock service over a mocked mapper (#809 review): the old test
		// mocked the service and asserted unlockAndUpdateSuite('old', 'old'),
		// which the real service always refuses ("must differ"), so the
		// requests were never unlocked while the test stayed green.
		$mapper = $this->createMock(SecretRequestMapper::class);
		$mapper->expects($this->once())
			->method('unlockByEncryptionSuiteId')
			->with('old-suite')
			->willReturn(2);
		$mapper->expects($this->never())->method('unlockAndUpdateSuite');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');

		$listener = new SuiteMigrationAbortedListener(
			new SecretRequestSuiteLockService($mapper, $this->createMock(LoggerInterface::class)),
			$logger
		);

		$listener->handle(new SuiteMigrationAbortedEvent(
			oldSuiteId: 'old-suite',
			newSuiteId: 'new-suite',
			migrationId: 'migration-1',
		));
	}//end testUnlocksRequestsKeepingTheOldSuite()

	public function testIgnoresOtherEvents(): void {
		$service = $this->createMock(SecretRequestSuiteLockService::class);
		$service->expects($this->never())->method('unlockInPlace');

		$listener = new SuiteMigrationAbortedListener(
			$service,
			$this->createMock(LoggerInterface::class)
		);

		$listener->handle(new SuiteMigrationStartedEvent('old', 'new', 'migration-1'));
		$listener->handle(new Event());
	}//end testIgnoresOtherEvents()
}//end class
