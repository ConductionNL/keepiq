<?php

/**
 * Every notification subject KeepiqNotifier renders, pinned call by call.
 *
 * Each subject the notification service can send is prepared with a full,
 * an alternative and an empty parameter set against a recording
 * notification double. Every setter and action call, in order, is compared
 * with a golden file recorded on the code before the renderers were split
 * per subject. Record with KEEPIQ_RECORD_GOLDEN=1.
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

use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Notification\KeepiqNotifier;
use OCA\Keepiq\Service\NotificationService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\IAction;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden renderings of every notification subject.
 */
class KeepiqNotifierGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/golden/keepiq-notifier.json';

	/**
	 * The calls recorded so far.
	 *
	 * @var array<int,mixed>
	 */
	private array $calls = [];

	/**
	 * The parameter sets every subject is prepared with.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function parameterSets(): array {
		return [
			'full' => [
				'secret_id' => 'secret-1',
				'secret_name' => 'Bank',
				'shared_by' => 'bob',
				'requester' => 'carol',
				'target_user_id' => 'dave',
				'result' => 'denied',
				'group_id' => 'team',
				'group_share_id' => 'gs-1',
				'new_member_id' => 'erin',
				'recipient' => 'frank',
				'use_only' => false,
				'count' => 3,
				'other_count' => 2,
				'days_left' => 5,
				'app_name' => 'Deployer',
				'registered_by' => 'grace',
				'sink_name' => 'Splunk',
				'accessor' => 'mallory',
				'channel' => 'api',
				'grantee_name' => 'Heidi',
				'granteeUserId' => 'heidi',
				'waitPeriodDays' => 7,
				'user' => 'ivan',
				'device_label' => 'Laptop',
				'confirmedBy' => 'judy',
				'memberIds' => ['kim', 'lee'],
			],
			'camel' => [
				'secretId' => 'secret-2',
				'secretName' => 'Mail',
				'sourceSecretId' => 'secret-3',
				'requesterId' => 'carol',
				'target_user_id' => 'carol',
				'result' => 'approved',
				'groupId' => 'ops',
				'groupShareId' => 'gs-2',
				'newMemberId' => 'erin',
				'sharedBy' => 'bob',
				'use_only' => true,
				'count' => 1,
				'other_count' => 0,
				'days_left' => 0,
				'overdue' => true,
				'grantee_name' => '',
				'granteeUserId' => 'heidi',
			],
			'empty' => [],
		];
	}//end parameterSets()

	/**
	 * Every subject with every parameter set.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function cases(): array {
		$out = [];
		foreach (array_keys(NotificationService::SUBJECT_SETTING_MAP) as $subject) {
			foreach (array_keys(self::parameterSets()) as $set) {
				$out[$subject . ' ' . $set] = [$subject, $set];
			}
		}

		return $out;
	}//end cases()

	/**
	 * Each subject renders exactly the recorded calls.
	 *
	 * @param string $subject The notification subject
	 * @param string $set The parameter set name
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testRenderingMatchesGolden(string $subject, string $set): void {
		$this->calls = [];
		$notification = $this->recordingNotification(subject: $subject, params: self::parameterSets()[$set]);

		try {
			$this->notifier()->prepare(notification: $notification, languageCode: 'de');
			$outcome = 'prepared';
		} catch (UnknownNotificationException) {
			$outcome = 'unknown';
		}

		$trace = ['calls' => $this->calls, 'outcome' => $outcome];
		$key = $subject . ' ' . $set;

		$golden = [];
		if (is_file(self::GOLDEN) === true) {
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		if (getenv('KEEPIQ_RECORD_GOLDEN') === '1') {
			$golden[$key] = $trace;
			ksort($golden);
			file_put_contents(self::GOLDEN, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$this->assertSame($golden[$key] ?? null, json_decode((string)json_encode($trace), true));
	}//end testRenderingMatchesGolden()

	/**
	 * The notifier under test, with an echoing translator and URL generator.
	 *
	 * @return KeepiqNotifier
	 */
	private function notifier(): KeepiqNotifier {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(
			function (string $text, $parameters = []): string {
				$this->calls[] = ['t', $text, $parameters];
				return '[' . $text . ']' . json_encode($parameters);
			}
		);
		$l->method('n')->willReturnCallback(
			function (string $singular, string $plural, int $count, array $parameters = []): string {
				$this->calls[] = ['n', $singular, $plural, $count, $parameters];
				return '[' . $singular . '|' . $plural . ']' . $count . json_encode($parameters);
			}
		);

		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturnCallback(
			function (string $app, ?string $lang = null) use ($l): IL10N {
				$this->calls[] = ['l10n', $app, $lang];
				return $l;
			}
		);

		$url = $this->createMock(IURLGenerator::class);
		$url->method('imagePath')->willReturnCallback(static fn (string $app, string $file): string => "/img/{$app}/{$file}");
		$url->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://cloud.test' . $path);
		$url->method('linkToRoute')->willReturnCallback(
			function (string $route, array $arguments = []): string {
				$this->calls[] = ['linkToRoute', $route, $arguments];
				return '/route/' . $route . '/';
			}
		);

		return new KeepiqNotifier(l10nFactory: $factory, url: $url);
	}//end notifier()

	/**
	 * A notification double that records every call made on it.
	 *
	 * @param string $subject The notification subject
	 * @param array<string,mixed> $params The subject parameters
	 *
	 * @return INotification
	 */
	private function recordingNotification(string $subject, array $params): INotification {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn(Application::APP_ID);
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn($params);

		foreach (['setIcon', 'setParsedSubject', 'setParsedMessage', 'setLink', 'setRichSubject', 'setRichMessage'] as $method) {
			$notification->method($method)->willReturnCallback(
				function (...$args) use ($method, $notification): INotification {
					$this->calls[] = array_merge([$method], $args);
					return $notification;
				}
			);
		}

		$notification->method('createAction')->willReturnCallback(fn (): IAction => $this->recordingAction());
		$notification->method('addParsedAction')->willReturnCallback(
			function (IAction $action) use ($notification): INotification {
				$this->calls[] = ['addParsedAction'];
				return $notification;
			}
		);

		return $notification;
	}//end recordingNotification()

	/**
	 * An action double that records every call made on it.
	 *
	 * @return IAction
	 */
	private function recordingAction(): IAction {
		$this->calls[] = ['createAction'];
		$action = $this->createMock(IAction::class);
		foreach (['setLabel', 'setParsedLabel', 'setLink', 'setPrimary'] as $method) {
			$action->method($method)->willReturnCallback(
				function (...$args) use ($method, $action): IAction {
					$this->calls[] = array_merge(['action.' . $method], $args);
					return $action;
				}
			);
		}

		return $action;
	}//end recordingAction()
}//end class
