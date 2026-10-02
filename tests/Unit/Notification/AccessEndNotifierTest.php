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
 * The end-of-access notifications (sharing-use-only-and-expiring-shares 5.3).
 *
 * @spec openspec/changes/sharing-use-only-and-expiring-shares/specs/expiring-shares/spec.md#requirement-people-are-told-before-and-when-access-ends
 */
class AccessEndNotifierTest extends TestCase {

	/** @var array<string,?string> */
	private array $recorded = [];

	private function render(string $subject, array $params): void {
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

		$this->recorded = ['subject' => null, 'message' => null, 'link' => null];
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn(Application::APP_ID);
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn($params);
		$notification->method('setIcon')->willReturnSelf();
		$notification->method('setParsedSubject')->willReturnCallback(
			function (string $value) use ($notification) {
				$this->recorded['subject'] = $value;
				return $notification;
			}
		);
		$notification->method('setParsedMessage')->willReturnCallback(
			function (string $value) use ($notification) {
				$this->recorded['message'] = $value;
				return $notification;
			}
		);
		$notification->method('setLink')->willReturnCallback(
			function (string $value) use ($notification) {
				$this->recorded['link'] = $value;
				return $notification;
			}
		);

		(new KeepiqNotifier(l10nFactory: $factory, url: $url))->prepare($notification, 'en');
	}

	/**
	 * The holder is warned a day ahead, with a link to the copy.
	 *
	 * @return void
	 */
	public function testTheHolderIsWarned(): void {
		$this->render('share_access_ending', ['secret_id' => 'copy', 'secret_name' => 'Payroll portal']);
		$this->assertSame('Your access to "Payroll portal" ends tomorrow', $this->recorded['subject']);
		$this->assertSame('/apps/keepiq/secrets/copy', $this->recorded['link']);
	}

	/**
	 * The holder is told it ended.
	 *
	 * @return void
	 */
	public function testTheHolderIsToldItEnded(): void {
		$this->render('share_access_ended', ['secret_name' => 'Payroll portal']);
		$this->assertSame('Your access to "Payroll portal" has ended', $this->recorded['subject']);
	}

	/**
	 * The owner of a normal share gets the rotation hint.
	 *
	 * @return void
	 */
	public function testTheOwnerOfANormalShareIsToldToRotate(): void {
		$this->render(
			'share_access_ended_owner',
			['secret_id' => 'src', 'secret_name' => 'Payroll portal', 'recipient' => 'Carla', 'use_only' => false]
		);
		$this->assertSame('Carla no longer has access to "Payroll portal"', $this->recorded['subject']);
		$this->assertSame('Carla could see this password. Rotate it if Carla should no longer know it.', $this->recorded['message']);
	}

	/**
	 * The owner of a use-only share is told the holder could not view it.
	 *
	 * @return void
	 */
	public function testTheOwnerOfAUseOnlyShareIsToldItWasNotViewable(): void {
		$this->render(
			'share_access_ended_owner',
			['secret_id' => 'src', 'secret_name' => 'Payroll portal', 'recipient' => 'Bob', 'use_only' => true]
		);
		$this->assertSame('Bob could not view this password in Keepiq.', $this->recorded['message']);
	}
}
