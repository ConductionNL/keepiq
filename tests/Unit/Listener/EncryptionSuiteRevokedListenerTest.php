<?php

/**
 * Unit tests for EncryptionSuiteRevokedListener.
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

use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Event\EncryptionSuiteRevokedEvent;
use OCA\Keepiq\Listener\EncryptionSuiteRevokedListener;
use OCA\Keepiq\Service\DelegationService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for EncryptionSuiteRevokedListener.
 */
class EncryptionSuiteRevokedListenerTest extends TestCase {
	/**
	 * Test the listener sweeps share targets and promotes delegations
	 * for a user-owned suite.
	 *
	 * @return void
	 */
	public function testHandleSweepsAndPromotesForUserSuite(): void {
		$mapper = $this->createMock(ShareTargetMapper::class);
		$service = $this->createMock(DelegationService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$listener = new EncryptionSuiteRevokedListener(
			shareTargetMapper: $mapper,
			delegationService: $service,
			logger: $logger
		);

		$event = new EncryptionSuiteRevokedEvent(
			suiteId: 'suite-1',
			ownerType: 'user',
			ownerId: 'alice',
			revokedBy: 'admin'
		);

		$mapper->expects($this->once())
			->method('deleteByTargetUser')
			->with('alice');
		$service->expects($this->once())
			->method('makePermanent')
			->with('alice')
			->willReturn(2);

		$listener->handle($event);
	}//end testHandleSweepsAndPromotesForUserSuite()

	/**
	 * Test the listener skips application suites.
	 *
	 * @return void
	 */
	public function testHandleSkipsApplicationSuites(): void {
		$mapper = $this->createMock(ShareTargetMapper::class);
		$service = $this->createMock(DelegationService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$listener = new EncryptionSuiteRevokedListener(
			shareTargetMapper: $mapper,
			delegationService: $service,
			logger: $logger
		);

		$event = new EncryptionSuiteRevokedEvent(
			suiteId: 'suite-1',
			ownerType: 'application',
			ownerId: 'app-1',
			revokedBy: 'admin'
		);

		$mapper->expects($this->never())->method('deleteByTargetUser');
		$service->expects($this->never())->method('makePermanent');

		$listener->handle($event);
	}//end testHandleSkipsApplicationSuites()

	/**
	 * Test the listener no-ops on unrelated events.
	 *
	 * @return void
	 */
	public function testHandleIgnoresUnrelatedEvents(): void {
		$mapper = $this->createMock(ShareTargetMapper::class);
		$service = $this->createMock(DelegationService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$listener = new EncryptionSuiteRevokedListener(
			shareTargetMapper: $mapper,
			delegationService: $service,
			logger: $logger
		);

		$mapper->expects($this->never())->method('deleteByTargetUser');

		$listener->handle($this->createMock(Event::class));
	}//end testHandleIgnoresUnrelatedEvents()
}//end class
