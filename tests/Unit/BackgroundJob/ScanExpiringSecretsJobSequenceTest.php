<?php

/**
 * Pins the exact calls of one expiry scan over a mixed page.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\BackgroundJob;

use DateTime;
use OCA\Keepiq\BackgroundJob\ScanExpiringSecretsJob;
use OCA\Keepiq\Db\RotationFlag;
use OCA\Keepiq\Db\RotationFlagMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Service\NotificationService;
use OCA\Keepiq\Service\RotationPolicyService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * One scan over a page holding every branch, with every call recorded in order.
 *
 * @covers \OCA\Keepiq\BackgroundJob\ScanExpiringSecretsJob
 */
final class ScanExpiringSecretsJobSequenceTest extends TestCase {
	/**
	 * Every collaborator call, in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * Days until expiry per secret id; null never expires, 'throw' fails.
	 *
	 * @var array<string,int|string|null>
	 */
	private const DAYS = [
		'late-new'    => -3,
		'late-closed' => -1,
		'late-open'   => -2,
		'week'        => 7,
		'five'        => 5,
		'policy'      => 4,
		'broken'      => 'throw',
		'never'       => null,
		'today'       => 0,
	];

	/**
	 * The scan flags, reminds and logs exactly as pinned.
	 *
	 * @return void
	 */
	public function testScansAMixedPageInOrder(): void {
		$now = new DateTime('2026-07-18T12:00:00Z');
		$job = $this->job(now: $now, reminderDays: '[30,7,0]');

		$method = new ReflectionMethod($job, 'run');
		$method->invoke($job, null);

		$expected = [
			['page', 500, 0],
			['resolve', 'late-new'], ['findBySecret', 'late-new'], ['flag', 'late-new', 'policy_expiry'],
			['notify', 'secret_rotation_due', 'alice', ['secret_name' => 'Secret late-new', 'secret_id' => 'late-new'], 'secret', 'late-new'],
			['resolve', 'late-closed'], ['findBySecret', 'late-closed'], ['flag', 'late-closed', 'policy_expiry'],
			['notify', 'secret_rotation_due', 'alice', ['secret_name' => 'Secret late-closed', 'secret_id' => 'late-closed'], 'secret', 'late-closed'],
			['resolve', 'late-open'], ['findBySecret', 'late-open'],
			['resolve', 'week'], ['reminderDaysFor', 'week'],
			['notify', 'secret_expiring', 'alice', ['secret_name' => 'Secret week', 'secret_id' => 'week', 'days_left' => 7], 'secret', 'week'],
			['resolve', 'five'], ['reminderDaysFor', 'five'],
			['resolve', 'policy'], ['reminderDaysFor', 'policy'],
			['notify', 'secret_expiring', 'alice', ['secret_name' => 'Secret policy', 'secret_id' => 'policy', 'days_left' => 4], 'secret', 'policy'],
			['resolve', 'broken'],
			['warning', 'Keepiq: expiry scan failed for secret broken: RuntimeException', ['app' => 'keepiq']],
			['resolve', 'never'],
			['resolve', 'today'], ['reminderDaysFor', 'today'],
			['notify', 'secret_expiring', 'alice', ['secret_name' => 'Secret today', 'secret_id' => 'today', 'days_left' => 0], 'secret', 'today'],
		];
		self::assertSame($expected, $this->calls);
	}//end testScansAMixedPageInOrder()

	/**
	 * A stored reminder list that is not a list falls back to [30,7,1].
	 *
	 * @return void
	 */
	public function testAnUnreadableReminderListFallsBack(): void {
		foreach (['not json', '[]', '"7"'] as $stored) {
			$this->calls = [];
			$job = $this->job(now: new DateTime('2026-07-18T12:00:00Z'), reminderDays: $stored);
			(new ReflectionMethod($job, 'run'))->invoke($job, null);

			$notified = array_values(array_filter($this->calls, static fn (array $c): bool => $c[0] === 'notify'));
			self::assertSame(['late-new', 'late-closed', 'week', 'policy'], array_map(static fn (array $c): string => $c[5], $notified), $stored);
		}
	}//end testAnUnreadableReminderListFallsBack()

	/**
	 * The job over recording collaborators.
	 *
	 * @param DateTime $now          The scan instant
	 * @param string   $reminderDays The stored `expiry_reminder_days`
	 *
	 * @return ScanExpiringSecretsJob
	 */
	private function job(DateTime $now, string $reminderDays): ScanExpiringSecretsJob {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn($now);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $key === 'expiry_reminder_days' ? $reminderDays : $default
		);
		$secrets = $this->createMock(SecretMapper::class);
		$secrets->method('findAllUserOwnedPaged')->willReturnCallback(function (int $limit, int $offset): array {
			$this->calls[] = ['page', $limit, $offset];
			return array_map(static function (string $id): Secret {
				$secret = new Secret();
				$secret->setId($id);
				$secret->setName('Secret ' . $id);
				$secret->setOwnerId('alice');
				return $secret;
			}, array_keys(self::DAYS));
		});

		return new ScanExpiringSecretsJob(
			time: $time,
			secretMapper: $secrets,
			flagMapper: $this->flags(),
			rotationService: $this->rotation(now: $now),
			notificationService: $this->notifications(),
			appConfig: $appConfig,
			logger: $this->logger(),
		);
	}//end job()

	/**
	 * A flag mapper: no flag, a closed flag, or an open flag.
	 *
	 * @return RotationFlagMapper
	 */
	private function flags(): RotationFlagMapper {
		$flags = $this->createMock(RotationFlagMapper::class);
		$flags->method('findBySecret')->willReturnCallback(function (string $secretId): RotationFlag {
			$this->calls[] = ['findBySecret', $secretId];
			if ($secretId === 'late-new') {
				throw new DoesNotExistException('none');
			}

			$flag = new RotationFlag();
			$flag->setStatus($secretId === 'late-open' ? 'open' : 'resolved');
			return $flag;
		});
		return $flags;
	}//end flags()

	/**
	 * A rotation service answering from DAYS.
	 *
	 * @param DateTime $now The scan instant
	 *
	 * @return RotationPolicyService
	 */
	private function rotation(DateTime $now): RotationPolicyService {
		$rotation = $this->createMock(RotationPolicyService::class);
		$rotation->method('resolveEffectiveExpiry')->willReturnCallback(function (Secret $secret) use ($now): ?DateTime {
			$this->calls[] = ['resolve', $secret->getId()];
			$days = self::DAYS[$secret->getId()];
			if ($days === 'throw') {
				throw new RuntimeException('a message that must not be logged');
			}

			return $days === null ? null : (clone $now)->modify(($days >= 0 ? '+' : '') . $days . ' days');
		});
		$rotation->method('reminderDaysFor')->willReturnCallback(function (Secret $secret): ?array {
			$this->calls[] = ['reminderDaysFor', $secret->getId()];
			return $secret->getId() === 'policy' ? ['4'] : null;
		});
		$rotation->method('flag')->willReturnCallback(function (string $secretId, string $reason): RotationFlag {
			$this->calls[] = ['flag', $secretId, $reason];
			return new RotationFlag();
		});
		return $rotation;
	}//end rotation()

	/**
	 * A recording notification service.
	 *
	 * @return NotificationService
	 */
	private function notifications(): NotificationService {
		$notifications = $this->createMock(NotificationService::class);
		$notifications->method('notify')->willReturnCallback(
			function (string $subject, string $recipientId, array $params, string $objectType, string $objectId): bool {
				$this->calls[] = ['notify', $subject, $recipientId, $params, $objectType, $objectId];
				return true;
			}
		);
		return $notifications;
	}//end notifications()

	/**
	 * A logger recording warnings.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context = []): void {
			$this->calls[] = ['warning', $message, $context];
		});
		return $logger;
	}//end logger()
}//end class
