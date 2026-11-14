<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins what NotificationService::notify() logs when it drops a notification,
 * and the exact notification it queues.
 *
 * @category Tests
 * @package  OCA\Keepiq\Tests\Unit\Service
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec openspec/specs/user-settings/spec.md#requirement-notification-toggles-mvp
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use DateTime;
use OCA\Keepiq\Service\NotificationService;
use OCP\IConfig;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Notification dispatch outcomes.
 */
class NotificationDispatchTest extends TestCase {

	/**
	 * The notification manager.
	 *
	 * @var IManager&MockObject
	 */
	private IManager $manager;

	/**
	 * The config.
	 *
	 * @var IConfig&MockObject
	 */
	private IConfig $config;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The calls made on the notification, in order.
	 *
	 * @var list<array{0:string,1:array<int,mixed>}>
	 */
	private array $calls = [];

	/**
	 * Build the service.
	 *
	 * @return NotificationService
	 */
	private function service(): NotificationService {
		$this->manager = $this->createMock(IManager::class);
		$this->config = $this->createMock(IConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject'] as $method) {
			$notification->method($method)->willReturnCallback(
				function (...$args) use ($method, $notification): INotification {
					$this->calls[] = [$method, $args];
					return $notification;
				}
			);
		}

		$this->manager->method('createNotification')->willReturn($notification);
		return new NotificationService(notificationManager: $this->manager, config: $this->config, logger: $this->logger);
	}//end service()

	/**
	 * An unknown subject is dropped with a warning naming it, before any other check.
	 *
	 * @return void
	 */
	public function testAnUnknownSubjectIsDroppedWithAWarning(): void {
		$service = $this->service();
		$this->config->expects($this->never())->method('getUserValue');
		$this->logger->expects($this->once())->method('warning')
			->with('Keepiq notify(): unknown subject "mystery" — dropping', ['app' => 'keepiq']);

		$this->assertFalse($service->notify(subject: 'mystery', recipientId: ''));
	}//end testAnUnknownSubjectIsDroppedWithAWarning()

	/**
	 * An empty recipient is dropped without reading any preference or logging.
	 *
	 * @return void
	 */
	public function testAnEmptyRecipientIsDroppedSilently(): void {
		$service = $this->service();
		$this->config->expects($this->never())->method('getUserValue');
		$this->logger->expects($this->never())->method('warning');
		$this->logger->expects($this->never())->method('debug');

		$this->assertFalse($service->notify(subject: 'secret_shared', recipientId: ''));
	}//end testAnEmptyRecipientIsDroppedSilently()

	/**
	 * An opted-out recipient is dropped with a debug line naming the user and subject.
	 *
	 * @return void
	 */
	public function testAnOptedOutRecipientIsDroppedWithADebugLine(): void {
		$service = $this->service();
		$this->config->method('getUserValue')->willReturn('0');
		$this->manager->expects($this->never())->method('notify');
		$this->logger->expects($this->once())->method('debug')
			->with('Keepiq notify(): user alice opted out of "secret_shared"', ['app' => 'keepiq']);

		$this->assertFalse($service->notify(subject: 'secret_shared', recipientId: 'alice'));
	}//end testAnOptedOutRecipientIsDroppedWithADebugLine()

	/**
	 * A delivered notification carries the app, the user, a time, the object and the subject, in that order.
	 *
	 * @return void
	 */
	public function testADeliveredNotificationCarriesItsFieldsInOrder(): void {
		$service = $this->service();
		$this->config->method('getUserValue')->willReturn('1');
		$this->manager->expects($this->once())->method('notify');

		$this->assertTrue(
			$service->notify(subject: 'secret_shared', recipientId: 'bob', params: ['n' => 1], objectType: 'secret', objectId: 's-1')
		);

		$this->assertSame(['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject'], array_column($this->calls, 0));
		$this->assertSame(['keepiq'], $this->calls[0][1]);
		$this->assertSame(['bob'], $this->calls[1][1]);
		$this->assertInstanceOf(DateTime::class, $this->calls[2][1][0]);
		$this->assertSame(['secret', 's-1'], $this->calls[3][1]);
		$this->assertSame(['secret_shared', ['n' => 1]], $this->calls[4][1]);
	}//end testADeliveredNotificationCarriesItsFieldsInOrder()

	/**
	 * Without an object type or id, the notification names its subject for both.
	 *
	 * @return void
	 */
	public function testANotificationAboutNoObjectNamesItsSubject(): void {
		$service = $this->service();
		$this->config->method('getUserValue')->willReturn('1');

		$service->notify(subject: 'secret_shared', recipientId: 'bob', objectId: '');

		$this->assertSame(['secret_shared', 'secret_shared'], $this->calls[3][1]);
	}//end testANotificationAboutNoObjectNamesItsSubject()
}//end class
