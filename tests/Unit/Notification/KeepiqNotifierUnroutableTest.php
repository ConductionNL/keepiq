<?php

/**
 * Unit tests for KeepiqNotifier when the app route cannot be resolved.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Notification
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

namespace OCA\Keepiq\Tests\Unit\Notification;

use InvalidArgumentException;
use OCA\Keepiq\Notification\KeepiqNotifier;
use OCA\Keepiq\Notification\SubjectRendering;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Every link and action on a notification is optional: when the dashboard
 * route cannot be resolved, the notification must still render its subject
 * and message, and carry no link and no actions.
 */
#[CoversClass(KeepiqNotifier::class)]
#[UsesClass(SubjectRendering::class)]
class KeepiqNotifierUnroutableTest extends TestCase {

	/**
	 * Notifier under test, wired to a URL generator whose routes all fail.
	 *
	 * @var KeepiqNotifier
	 */
	private KeepiqNotifier $notifier;

	/**
	 * Values recorded from the notification double during the last prepare().
	 *
	 * @var array<string,mixed>
	 */
	private array $recorded = [];

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$l = $this->createMock(originalClassName: IL10N::class);
		$l->method('t')->willReturnCallback(
			static fn (string $text, $parameters = []): string => vsprintf($text, (array)$parameters)
		);
		$factory = $this->createMock(originalClassName: IFactory::class);
		$factory->method('get')->willReturn($l);

		$url = $this->createMock(originalClassName: IURLGenerator::class);
		$url->method('imagePath')->willReturn('/apps/keepiq/img/app.svg');
		$url->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path): string => 'https://cloud.example.test' . $path
		);
		$url->method('linkToRoute')->willThrowException(new InvalidArgumentException('no such route'));

		$this->notifier = new KeepiqNotifier(l10nFactory: $factory, url: $url);
	}//end setUp()

	/**
	 * Run prepare() on a recording double that must never receive an action.
	 *
	 * @param string $subject The notification subject identifier
	 * @param array<string,mixed> $params The subject parameters
	 *
	 * @return array<string,mixed>
	 */
	private function prepare(string $subject, array $params): array {
		$this->recorded = ['parsedSubject' => null, 'parsedMessage' => null, 'linkCalls' => 0];

		$notification = $this->createMock(originalClassName: INotification::class);
		$notification->method('getApp')->willReturn('keepiq');
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn($params);
		$notification->method('setIcon')->willReturnSelf();
		$notification->method('setParsedSubject')->willReturnCallback(
			function (string $value) use ($notification): INotification {
				$this->recorded['parsedSubject'] = $value;
				return $notification;
			}
		);
		$notification->method('setParsedMessage')->willReturnCallback(
			function (string $value) use ($notification): INotification {
				$this->recorded['parsedMessage'] = $value;
				return $notification;
			}
		);
		$notification->method('setLink')->willReturnCallback(
			function () use ($notification): INotification {
				$this->recorded['linkCalls']++;
				return $notification;
			}
		);
		$notification->expects($this->never())->method('createAction');
		$notification->expects($this->never())->method('addParsedAction');

		$returned = $this->notifier->prepare(notification: $notification, languageCode: 'en');
		$this->assertSame(expected: $notification, actual: $returned);

		return $this->recorded;
	}//end prepare()

	/**
	 * Recovery subjects keep their text when the dashboard link fails.
	 *
	 * @return void
	 */
	public function testRecoveryRequestRendersWithoutLink(): void {
		$recorded = $this->prepare(subject: 'recovery_requested', params: ['user' => 'alice']);

		$this->assertSame(
			expected: 'alice asks to recover their account. Compare the words with them before you approve.',
			actual: $recorded['parsedSubject']
		);
		$this->assertSame(expected: 0, actual: $recorded['linkCalls']);
	}//end testRecoveryRequestRendersWithoutLink()

	/**
	 * A device approval request keeps its warning when the dashboard link fails.
	 *
	 * @return void
	 */
	public function testDeviceApprovalRendersWithoutLink(): void {
		$recorded = $this->prepare(subject: 'device_approval_requested', params: ['device_label' => 'Firefox on Linux']);

		$this->assertSame(expected: 'A new device asks to open your vault', actual: $recorded['parsedSubject']);
		$this->assertSame(
			expected: 'Firefox on Linux asks to be approved. Only approve a device you are using right now.',
			actual: $recorded['parsedMessage']
		);
		$this->assertSame(expected: 0, actual: $recorded['linkCalls']);
	}//end testDeviceApprovalRendersWithoutLink()

	/**
	 * A federated share keeps its message when the Incoming page link fails.
	 *
	 * @return void
	 */
	public function testFederatedShareRendersWithoutAppLink(): void {
		$recorded = $this->prepare(
			subject: 'federated_share_received',
			params: ['secret_name' => 'VPN key', 'shared_by' => 'bob@other.example']
		);

		$this->assertSame(expected: 'A secret from another organisation', actual: $recorded['parsedSubject']);
		$this->assertSame(
			expected: 'bob@other.example shared "VPN key" with you. Accept it under Incoming from other organisations.',
			actual: $recorded['parsedMessage']
		);
		$this->assertSame(expected: 0, actual: $recorded['linkCalls']);
	}//end testFederatedShareRendersWithoutAppLink()

	/**
	 * A complete share request gets no approve or deny action when the app
	 * root cannot be resolved, yet its message still names everyone involved.
	 *
	 * @return void
	 */
	public function testShareRequestHasNoActionsWithoutAppRoot(): void {
		$recorded = $this->prepare(
			subject: 'share_request',
			params: [
				'sourceSecretId' => 'sec-7',
				'secretName' => 'API token',
				'requesterId' => 'bob',
				'targetUserId' => 'carol',
			]
		);

		$this->assertSame(expected: 'Share request', actual: $recorded['parsedSubject']);
		$this->assertSame(
			expected: 'bob asks you to share the secret "API token" with carol.',
			actual: $recorded['parsedMessage']
		);
		$this->assertSame(expected: 0, actual: $recorded['linkCalls']);
	}//end testShareRequestHasNoActionsWithoutAppRoot()
}//end class
