<?php

/**
 * Unit tests for ScanExpiringSecretsJob (rotation-expiry-policies §8.3).
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\BackgroundJob
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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Tests for ScanExpiringSecretsJob.
 */
class ScanExpiringSecretsJobTest extends TestCase {
	private ScanExpiringSecretsJob $job;

	private SecretMapper&MockObject $secretMapper;

	private RotationFlagMapper&MockObject $flagMapper;

	private RotationPolicyService&MockObject $rotationService;

	private NotificationService&MockObject $notificationService;

	/**
	 * The fixed "now" every test runs at.
	 */
	private DateTime $now;

	/**
	 * Build the job with fresh mocks and default thresholds [30,7,1].
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->now = new DateTime('2026-07-18T12:00:00Z');

		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getDateTime')->willReturn($this->now);
		$time->method('getTime')->willReturn($this->now->getTimestamp());

		$this->secretMapper = $this->createMock(originalClassName: SecretMapper::class);
		$this->flagMapper = $this->createMock(originalClassName: RotationFlagMapper::class);
		$this->rotationService = $this->createMock(originalClassName: RotationPolicyService::class);
		$this->notificationService = $this->createMock(originalClassName: NotificationService::class);

		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);

		$this->job = new ScanExpiringSecretsJob(
			time: $time,
			secretMapper: $this->secretMapper,
			flagMapper: $this->flagMapper,
			rotationService: $this->rotationService,
			notificationService: $this->notificationService,
			appConfig: $appConfig,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * Invoke the protected run() hook.
	 *
	 * @return void
	 */
	private function runJob(): void {
		$method = new ReflectionMethod($this->job, 'run');
		$method->setAccessible(true);
		$method->invoke($this->job, null);
	}//end runJob()

	/**
	 * A user-owned secret whose effective expiry sits N days from now.
	 *
	 * @param string $id The secret id
	 * @param int $daysLeft Days until expiry (negative = overdue)
	 *
	 * @return Secret
	 */
	private function secretExpiringIn(string $id, int $daysLeft): Secret {
		$secret = new Secret();
		$secret->setId($id);
		$secret->setName('Secret ' . $id);
		$secret->setOwnerType('user');
		$secret->setOwnerId('alice');

		$expiry = (clone $this->now)->modify(($daysLeft >= 0 ? '+' : '') . $daysLeft . ' days');
		$this->rotationService->method('resolveEffectiveExpiry')
			->willReturnCallback(static fn (Secret $s) => $s->getId() === $id ? $expiry : null);

		return $secret;
	}//end secretExpiringIn()

	/**
	 * 8.3: a secret exactly at a threshold (7 days) triggers ONE
	 * secret_expiring notification; off-threshold secrets are silent.
	 *
	 * @return void
	 */
	public function testNotifiesOnlyAtExactThreshold(): void {
		$atThreshold = $this->secretExpiringIn('sec-7', 7);
		$offThreshold = new Secret();
		$offThreshold->setId('sec-12');
		$offThreshold->setName('Secret sec-12');
		$offThreshold->setOwnerType('user');
		$offThreshold->setOwnerId('alice');

		$this->secretMapper->method('findAllUserOwnedPaged')
			->willReturnOnConsecutiveCalls([$atThreshold, $offThreshold], []);

		$notified = [];
		$this->notificationService->method('notify')->willReturnCallback(
			static function (string $subject, string $recipientId, array $params = []) use (&$notified): bool {
				$notified[] = [$subject, $params['secret_id'] ?? '', $params['days_left'] ?? null];
				return true;
			}
		);

		$this->runJob();

		$this->assertCount(1, $notified);
		$this->assertSame(['secret_expiring', 'sec-7', 7], $notified[0]);
	}//end testNotifiesOnlyAtExactThreshold()

	/**
	 * 8.3: an overdue secret raises the policy_expiry flag and notifies
	 * once; on the next run (flag already open) neither repeats.
	 *
	 * @return void
	 */
	public function testOverdueFlagsOnceAndDeduplicates(): void {
		$overdue = $this->secretExpiringIn('sec-late', -3);
		$this->secretMapper->method('findAllUserOwnedPaged')
			->willReturnOnConsecutiveCalls([$overdue], [], [$overdue], []);

		// First run: no flag row yet → flag + notify.
		$openFlag = new RotationFlag();
		$openFlag->setSecretId('sec-late');
		$openFlag->setStatus('open');
		$calls = 0;
		$this->flagMapper->method('findBySecret')->willReturnCallback(
			static function () use (&$calls, $openFlag): RotationFlag {
				++$calls;
				if ($calls === 1) {
					throw new DoesNotExistException('none yet');
				}

				return $openFlag;
			}
		);

		$flaggedWith = [];
		$this->rotationService->method('flag')->willReturnCallback(
			static function (string $secretId, string $reason) use (&$flaggedWith, $openFlag): RotationFlag {
				$flaggedWith[] = [$secretId, $reason];
				return $openFlag;
			}
		);

		$notifyCount = 0;
		$this->notificationService->method('notify')->willReturnCallback(
			static function (string $subject) use (&$notifyCount): bool {
				if ($subject === 'secret_rotation_due') {
					++$notifyCount;
				}

				return true;
			}
		);

		$this->runJob();
		// Second run: the flag is open → nothing new.
		$this->runJob();

		$this->assertSame([['sec-late', 'policy_expiry']], $flaggedWith);
		$this->assertSame(1, $notifyCount);
	}//end testOverdueFlagsOnceAndDeduplicates()

	/**
	 * 8.3: never-expiring secrets produce no notifications or flags.
	 *
	 * @return void
	 */
	public function testNeverExpiringIsSilent(): void {
		$secret = new Secret();
		$secret->setId('sec-none');
		$secret->setName('Secret sec-none');
		$secret->setOwnerType('user');
		$secret->setOwnerId('alice');

		$this->secretMapper->method('findAllUserOwnedPaged')->willReturnOnConsecutiveCalls([$secret], []);
		$this->rotationService->method('resolveEffectiveExpiry')->willReturn(null);
		$this->notificationService->expects($this->never())->method('notify');
		$this->rotationService->expects($this->never())->method('flag');

		$this->runJob();
	}//end testNeverExpiringIsSilent()
}//end class
