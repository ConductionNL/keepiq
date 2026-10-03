<?php

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Notification;

use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Notification\KeepiqNotifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;

/**
 * A new device's request is announced (crypto-new-device-approval task 1.2).
 *
 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-a-new-device-requests-approval-with-a-one-time-key
 */
class DeviceApprovalNotifierTest extends TestCase {

	public function testTheRequestIsAnnouncedWithItsDeviceAndAWarning(): void {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(
			static fn (string $text, $parameters = []): string => vsprintf($text, (array)$parameters)
		);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l);
		$url = $this->createMock(IURLGenerator::class);
		$url->method('getAbsoluteURL')->willReturnArgument(0);
		$url->method('linkToRoute')->willReturn('/apps/keepiq/');
		$url->method('imagePath')->willReturn('/app.svg');

		$recorded = [];
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn(Application::APP_ID);
		$notification->method('getSubject')->willReturn('device_approval_requested');
		$notification->method('getSubjectParameters')->willReturn(['device_label' => 'Firefox on Linux']);
		$notification->method('setIcon')->willReturnSelf();
		foreach (['setParsedSubject' => 'subject', 'setParsedMessage' => 'message', 'setLink' => 'link'] as $method => $key) {
			$notification->method($method)->willReturnCallback(
				function (string $value) use (&$recorded, $key, $notification) {
					$recorded[$key] = $value;
					return $notification;
				}
			);
		}

		(new KeepiqNotifier(l10nFactory: $factory, url: $url))->prepare($notification, 'en');

		$this->assertSame('A new device asks to open your vault', $recorded['subject']);
		$this->assertSame(
			'Firefox on Linux asks to be approved. Only approve a device you are using right now.',
			$recorded['message']
		);
		$this->assertSame('/apps/keepiq/', $recorded['link']);
	}
}
