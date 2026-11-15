<?php

/**
 * Failure and skip paths of the certificate and trash background jobs.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\BackgroundJob;

use DateTime;
use Exception;
use OCA\Keepiq\BackgroundJob\CheckRootCertificateExpiry;
use OCA\Keepiq\BackgroundJob\PurgeTrashedSecretsJob;
use OCA\Keepiq\BackgroundJob\RenewIntermediateCertificate;
use OCA\Keepiq\BackgroundJob\ScanCertificateExpiryJob;
use OCA\Keepiq\Db\CACertificate;
use OCA\Keepiq\Db\CACertificateMapper;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretTrashMapper;
use OCA\Keepiq\Service\CertificateAuthorityService;
use OCA\Keepiq\Service\CertificateLifecycleService;
use OCA\Keepiq\Service\NotificationService;
use OCA\Keepiq\Service\SecretService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * What each job does when its input is missing, a collaborator throws, or
 * nothing qualifies: it logs at the right level, skips, and never throws.
 *
 * @covers \OCA\Keepiq\BackgroundJob\CheckRootCertificateExpiry
 * @covers \OCA\Keepiq\BackgroundJob\RenewIntermediateCertificate
 * @covers \OCA\Keepiq\BackgroundJob\PurgeTrashedSecretsJob
 * @covers \OCA\Keepiq\BackgroundJob\ScanCertificateExpiryJob
 * @uses   \OCA\Keepiq\Db\CACertificate
 * @uses   \OCA\Keepiq\Db\EncryptionSuite
 * @uses   \OCA\Keepiq\Db\Secret
 */
class CertificateAndTrashJobEdgesTest extends TestCase {

	/**
	 * Every log line: level, message, context.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $logged = [];

	/**
	 * A logger recording into $logged.
	 *
	 * @return LoggerInterface The logger.
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context = []) use ($level): void {
					$this->logged[] = [$level, (string)$message, $context];
				}
			);
		}

		return $logger;
	}//end logger()

	/**
	 * A time factory frozen at 2026-10-02 12:00 UTC.
	 *
	 * @return ITimeFactory The factory.
	 */
	private function now(): ITimeFactory {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-10-02T12:00:00+00:00'));
		return $time;
	}//end now()

	/**
	 * A CA certificate expiring $days after now, or without an expiry.
	 *
	 * @param int|null $days Days until expiry, null for none.
	 *
	 * @return CACertificate The certificate.
	 */
	private static function certificate(?int $days): CACertificate {
		$cert = new CACertificate();
		if ($days !== null) {
			$cert->setExpiresAt((new DateTime('2026-10-02T12:00:00+00:00'))->modify(sprintf('%+d days', $days)));
		}

		return $cert;
	}//end certificate()

	/**
	 * Run a job's protected run().
	 *
	 * @param object $job The job.
	 *
	 * @return void
	 */
	private static function runJob(object $job): void {
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end runJob()

	/**
	 * The root job over a root, an admin group and a notifier.
	 *
	 * @param CACertificate|Exception $root          What findRoot answers or throws.
	 * @param IGroup|null             $adminGroup    The admin group.
	 * @param NotificationService     $notifications The notifier.
	 * @param array<string, string>   $config        The app config store.
	 *
	 * @return CheckRootCertificateExpiry The job.
	 */
	private function rootJob(CACertificate|Exception $root, ?IGroup $adminGroup, NotificationService $notifications, array &$config): CheckRootCertificateExpiry {
		$mapper = $this->createMock(CACertificateMapper::class);
		if ($root instanceof Exception) {
			$mapper->method('findRoot')->willThrowException($root);
		} else {
			$mapper->method('findRoot')->willReturn($root);
		}

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->with('admin')->willReturn($adminGroup);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$config): string {
				return ($config[$key] ?? $default);
			}
		);
		$appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$config): bool {
				$config[$key] = $value;
				return true;
			}
		);

		return new CheckRootCertificateExpiry($this->now(), $mapper, $this->logger(), $groups, $notifications, $appConfig);
	}//end rootJob()

	/**
	 * A group of users with the given uids.
	 *
	 * @param array<int, string> $uids The uids.
	 *
	 * @return IGroup The group.
	 */
	private function group(array $uids): IGroup {
		$users = array_map(
			function (string $uid): IUser {
				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);
				return $user;
			},
			$uids
		);
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($users);
		return $group;
	}//end group()

	/**
	 * No root, or a root without an expiry, notifies nobody and records nothing.
	 *
	 * @return void
	 */
	public function testNoRootOrNoExpiryIsSilent(): void {
		foreach ([new Exception('no root'), self::certificate(days: null)] as $root) {
			$config = [];
			$notifications = $this->createMock(NotificationService::class);
			$notifications->expects($this->never())->method('notify');

			self::runJob($this->rootJob(root: $root, adminGroup: $this->group(uids: ['admin']), notifications: $notifications, config: $config));

			$this->assertSame([], $config);
		}

		$this->assertSame([], $this->logged);
	}//end testNoRootOrNoExpiryIsSilent()

	/**
	 * Each window maps to its threshold: 7 inside a week, 30 inside a month;
	 * an expired root is past every threshold.
	 *
	 * @return void
	 */
	public function testTheThresholdIsTheNarrowestWindowTheRootIsIn(): void {
		$seen = [];
		foreach ([5 => 7, 7 => 7, 8 => 30, 31 => 90, 0 => null, -3 => null] as $days => $expected) {
			$config = [];
			$notifications = $this->createMock(NotificationService::class);
			$notifications->method('notify')->willReturnCallback(
				static function (string $subject, string $recipientId, array $params) use (&$seen, $days): bool {
					$seen[$days] = $params['threshold'];
					return true;
				}
			);

			self::runJob($this->rootJob(root: self::certificate(days: $days), adminGroup: $this->group(uids: ['admin']), notifications: $notifications, config: $config));

			$this->assertSame($expected, ($seen[$days] ?? null), "{$days} days left");
		}
	}//end testTheThresholdIsTheNarrowestWindowTheRootIsIn()

	/**
	 * Without an admin group nobody is notified, but the warning is logged and
	 * the threshold recorded.
	 *
	 * @return void
	 */
	public function testWithoutAnAdminGroupOnlyTheWarningIsLogged(): void {
		$config = [];
		$notifications = $this->createMock(NotificationService::class);
		$notifications->expects($this->never())->method('notify');
		$root = self::certificate(days: 20);

		self::runJob($this->rootJob(root: $root, adminGroup: null, notifications: $notifications, config: $config));

		$this->assertSame([['warning', 'Keepiq: Root certificate expires in 20 days (threshold: 30)', []]], $this->logged);
		$this->assertSame(['ca_root_expiry_announced' => $root->getExpiresAt()->getTimestamp().':30'], $config);
	}//end testWithoutAnAdminGroupOnlyTheWarningIsLogged()

	/**
	 * A notification that throws is logged by class and the other admins are
	 * still notified.
	 *
	 * @return void
	 */
	public function testAFailingNotificationDoesNotStopTheOthers(): void {
		$config = [];
		$notified = [];
		$notifications = $this->createMock(NotificationService::class);
		$notifications->method('notify')->willReturnCallback(
			static function (string $subject, string $recipientId, array $params, string $objectType, string $objectId) use (&$notified): bool {
				if ($recipientId === 'alice') {
					throw new RuntimeException('mail server said no to alice@example.nl');
				}

				$notified[] = [$subject, $recipientId, $params, $objectType, $objectId];
				return true;
			}
		);

		self::runJob($this->rootJob(root: self::certificate(days: 6), adminGroup: $this->group(uids: ['alice', 'bob']), notifications: $notifications, config: $config));

		$this->assertSame([['ca_root_expiring', 'bob', ['days_left' => 6, 'threshold' => 7], 'ca_root', 'threshold-7']], $notified);
		$this->assertSame(['warning', 'Keepiq: root certificate expiry notification failed: RuntimeException', []], $this->logged[1]);
		$this->assertStringNotContainsString('alice@example.nl', (string)json_encode($this->logged));
	}//end testAFailingNotificationDoesNotStopTheOthers()

	/**
	 * No active intermediate is a warning; one without an expiry is skipped.
	 *
	 * @return void
	 */
	public function testRenewalWithoutAnIntermediateOrExpirySkips(): void {
		$ca = $this->createMock(CertificateAuthorityService::class);
		$ca->expects($this->never())->method('renewIntermediate');

		$missing = $this->createMock(CACertificateMapper::class);
		$missing->method('findActiveIntermediate')->willThrowException(new Exception('none'));
		self::runJob(new RenewIntermediateCertificate($this->now(), $missing, $ca, $this->logger()));

		$undated = $this->createMock(CACertificateMapper::class);
		$undated->method('findActiveIntermediate')->willReturn(self::certificate(days: null));
		self::runJob(new RenewIntermediateCertificate($this->now(), $undated, $ca, $this->logger()));

		$this->assertSame([['warning', 'Keepiq: No active intermediate found, skipping renewal check', []]], $this->logged);
	}//end testRenewalWithoutAnIntermediateOrExpirySkips()

	/**
	 * A renewal at exactly 30 days logs both steps; a failing one logs an error.
	 *
	 * @return void
	 */
	public function testRenewalLogsItsOutcome(): void {
		$mapper = $this->createMock(CACertificateMapper::class);
		$mapper->method('findActiveIntermediate')->willReturn(self::certificate(days: 30));
		$ca = $this->createMock(CertificateAuthorityService::class);
		$ca->method('renewIntermediate')->willReturnOnConsecutiveCalls(4, $this->throwException(new Exception('HSM offline')));
		$job = new RenewIntermediateCertificate($this->now(), $mapper, $ca, $this->logger());

		self::runJob($job);
		self::runJob($job);

		$this->assertSame(
			[
				['info', 'Keepiq: Intermediate certificate expires in 30 days, auto-renewing', []],
				['info', 'Keepiq: Intermediate auto-renewed, 4 suites re-signed', []],
				['info', 'Keepiq: Intermediate certificate expires in 30 days, auto-renewing', []],
				['error', 'Keepiq: Intermediate auto-renewal failed', ['exception' => 'HSM offline']],
			],
			$this->logged
		);
	}//end testRenewalLogsItsOutcome()

	/**
	 * The trash job's run logs what it purged, nothing when nothing was, and a
	 * warning when the purge itself fails.
	 *
	 * @return void
	 */
	public function testTheTrashRunLogsItsOutcome(): void {
		$secret = new Secret();
		$secret->setId('s-1');
		$secret->setOwnerId('bob');
		$trash = $this->createMock(SecretTrashMapper::class);
		$trash->method('findTrashedBefore')->willReturnOnConsecutiveCalls([$secret], [], $this->throwException(new RuntimeException('db gone')));
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturn(30);
		$job = new PurgeTrashedSecretsJob($this->now(), $trash, $this->createMock(SecretService::class), $appConfig, $this->logger());

		self::runJob($job);
		self::runJob($job);
		self::runJob($job);

		$this->assertSame(
			[
				['info', 'Keepiq: purged 1 secrets from the trash', ['app' => 'keepiq']],
				['warning', 'Keepiq: trash purge failed: db gone', ['app' => 'keepiq']],
			],
			$this->logged
		);
	}//end testTheTrashRunLogsItsOutcome()

	/**
	 * The retention is clamped to 1..365 days.
	 *
	 * @return void
	 */
	public function testTheRetentionIsClamped(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->with('keepiq', 'trash_retention_days', 30)->willReturnOnConsecutiveCalls(0, 400, 45);
		$job = new PurgeTrashedSecretsJob($this->now(), $this->createMock(SecretTrashMapper::class), $this->createMock(SecretService::class), $appConfig, $this->logger());

		$this->assertSame([1, 365, 45], [$job->retentionDays(), $job->retentionDays(), $job->retentionDays()]);
	}//end testTheRetentionIsClamped()

	/**
	 * A suite whose certificate cannot be parsed, has no notAfter, or is off
	 * a threshold is skipped; one whose scan throws is logged by class and
	 * the next suite is still scanned.
	 *
	 * @return void
	 */
	public function testTheSuiteScanSkipsAndSurvivesBadSuites(): void {
		$suites = [];
		foreach (['unparsable', 'undated', 'off-threshold', 'throws', 'due'] as $id) {
			$suite = new EncryptionSuite();
			$suite->setId($id);
			$suite->setOwnerType('user');
			$suite->setOwnerId('alice');
			$suite->setCertificate('PEM-'.$id);
			$suites[] = $suite;
		}

		$mapper = $this->createMock(EncryptionSuiteMapper::class);
		$mapper->method('findAllActiveWithLimit')->with(200, 0)->willReturn($suites);
		$lifecycle = $this->createMock(CertificateLifecycleService::class);
		$lifecycle->method('parseCaCertificate')->willReturnCallback(
			static fn (string $pem): ?array => match ($pem) {
				'PEM-unparsable' => null,
				'PEM-undated' => ['notAfter' => null],
				'PEM-off-threshold' => ['notAfter' => '2026-10-12T13:00:00+00:00'],
				'PEM-throws' => throw new RuntimeException('bad pem with secrets'),
				default => ['notAfter' => '2026-10-09T13:00:00+00:00'],
			}
		);
		$notified = [];
		$notifications = $this->createMock(NotificationService::class);
		$notifications->method('notify')->willReturnCallback(
			static function (string $subject, string $recipientId, array $params, string $objectType, string $objectId) use (&$notified): bool {
				$notified[] = [$recipientId, $params['days_left'], $objectId];
				return true;
			}
		);

		self::runJob(new ScanCertificateExpiryJob($this->now(), $mapper, $lifecycle, $notifications, $this->logger()));

		$this->assertSame([['alice', 7, 'due']], $notified);
		$this->assertSame([['warning', 'Keepiq: certificate expiry scan failed for suite throws: RuntimeException', ['app' => 'keepiq']]], $this->logged);
	}//end testTheSuiteScanSkipsAndSurvivesBadSuites()
}//end class
