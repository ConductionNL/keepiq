<?php

/**
 * Unit tests for UserDeletedListener.
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

use OCA\Keepiq\Listener\UserDeletedListener;
use OCA\Keepiq\Service\AccountDeletionService;
use OCA\Keepiq\Service\DeletionReport;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the NC user-deletion cascade trigger.
 */
class UserDeletedListenerTest extends TestCase {
	/**
	 * A NC user deletion triggers the cascade with the 'user-deleted' trigger.
	 *
	 * @return void
	 */
	public function testNcUserDeletionTriggersCascade(): void {
		$service = $this->createMock(AccountDeletionService::class);
		$logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$event = $this->createMock(UserDeletedEvent::class);
		$event->method('getUser')->willReturn($user);

		$service->expects($this->once())
			->method('deleteAllFor')
			->with('alice', 'user-deleted')
			->willReturn(new DeletionReport());

		(new UserDeletedListener($service, $logger))->handle($event);
	}//end testNcUserDeletionTriggersCascade()

	/**
	 * A non-UserDeletedEvent is ignored.
	 *
	 * @return void
	 */
	public function testIgnoresOtherEvents(): void {
		$service = $this->createMock(AccountDeletionService::class);
		$logger = $this->createMock(LoggerInterface::class);

		$service->expects($this->never())->method('deleteAllFor');

		(new UserDeletedListener($service, $logger))->handle(
			new class extends Event {
			}
		);
	}//end testIgnoresOtherEvents()

	/**
	 * A cascade failure is logged and swallowed (never blocks NC deletion).
	 *
	 * @return void
	 */
	public function testCascadeFailureIsSwallowed(): void {
		$service = $this->createMock(AccountDeletionService::class);
		$logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');
		$event = $this->createMock(UserDeletedEvent::class);
		$event->method('getUser')->willReturn($user);

		$service->method('deleteAllFor')->willThrowException(new \RuntimeException('boom'));
		$logger->expects($this->once())->method('error');

		// Must not throw.
		(new UserDeletedListener($service, $logger))->handle($event);
	}//end testCascadeFailureIsSwallowed()
}//end class
