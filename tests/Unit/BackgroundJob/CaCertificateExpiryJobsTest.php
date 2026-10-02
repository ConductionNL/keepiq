<?php

/**
 * The CA expiry jobs count days forward (keepiq#741): the intermediate is
 * renewed only inside its last 30 days, and admins hear about an expiring
 * root once per threshold.
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
use OCA\Keepiq\BackgroundJob\CheckRootCertificateExpiry;
use OCA\Keepiq\BackgroundJob\RenewIntermediateCertificate;
use OCA\Keepiq\Db\CACertificate;
use OCA\Keepiq\Db\CACertificateMapper;
use OCA\Keepiq\Service\CertificateAuthorityService;
use OCA\Keepiq\Service\NotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * Both jobs, driven through run() at a fixed "now".
 */
class CaCertificateExpiryJobsTest extends TestCase {

	/**
	 * A time factory frozen at 2026-10-02 12:00 UTC.
	 *
	 * @return ITimeFactory
	 */
	private function now(): ITimeFactory {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-10-02T12:00:00+00:00'));
		$time->method('getTime')->willReturn((new DateTime('2026-10-02T12:00:00+00:00'))->getTimestamp());
		return $time;
	}//end now()

	/**
	 * A CA certificate expiring a number of days after "now".
	 *
	 * @param int $days Days until expiry
	 *
	 * @return CACertificate
	 */
	private function expiringIn(int $days): CACertificate {
		$cert = new CACertificate();
		$cert->setExpiresAt((new DateTime('2026-10-02T12:00:00+00:00'))->modify('+'.$days.' days'));
		return $cert;
	}//end expiringIn()

	/**
	 * Run a job's protected run().
	 *
	 * @param object $job The job
	 *
	 * @return void
	 */
	private function runJob(object $job): void {
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end runJob()

	/**
	 * An intermediate with 300 days left is not renewed (it was, every night).
	 *
	 * @return void
	 */
	public function testAnIntermediateWithMonthsLeftIsNotRenewed(): void {
		$mapper = $this->createMock(CACertificateMapper::class);
		$mapper->method('findActiveIntermediate')->willReturn($this->expiringIn(300));
		$ca = $this->createMock(CertificateAuthorityService::class);
		$ca->expects($this->never())->method('renewIntermediate');

		$this->runJob(new RenewIntermediateCertificate($this->now(), $mapper, $ca, new NullLogger()));
	}//end testAnIntermediateWithMonthsLeftIsNotRenewed()

	/**
	 * An intermediate inside its last 30 days is renewed.
	 *
	 * @return void
	 */
	public function testAnIntermediateInItsLastMonthIsRenewed(): void {
		$mapper = $this->createMock(CACertificateMapper::class);
		$mapper->method('findActiveIntermediate')->willReturn($this->expiringIn(20));
		$ca = $this->createMock(CertificateAuthorityService::class);
		$ca->expects($this->once())->method('renewIntermediate')->willReturn(3);

		$this->runJob(new RenewIntermediateCertificate($this->now(), $mapper, $ca, new NullLogger()));
	}//end testAnIntermediateInItsLastMonthIsRenewed()

	/**
	 * Build the root job with one admin and an in-memory app config.
	 *
	 * @param int $days Days until the root expires
	 * @param NotificationService $notifications The notifier
	 * @param array<string,string> $config The app config store
	 *
	 * @return CheckRootCertificateExpiry
	 */
	private function rootJob(int $days, NotificationService $notifications, array &$config): CheckRootCertificateExpiry {
		$mapper = $this->createMock(CACertificateMapper::class);
		$mapper->method('findRoot')->willReturn($this->expiringIn($days));
		$admin = $this->createMock(IUser::class);
		$admin->method('getUID')->willReturn('admin');
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$admin]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->with('admin')->willReturn($group);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$config): string {
				return $config[$key] ?? $default;
			}
		);
		$appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$config): bool {
				$config[$key] = $value;
				return true;
			}
		);

		return new CheckRootCertificateExpiry($this->now(), $mapper, new NullLogger(), $groups, $notifications, $appConfig);
	}//end rootJob()

	/**
	 * A root inside the 90-day window notifies the admins, once.
	 *
	 * @return void
	 */
	public function testAnExpiringRootNotifiesTheAdminsOnce(): void {
		$notifications = $this->createMock(NotificationService::class);
		$notifications->expects($this->once())
			->method('notify')
			->with('ca_root_expiring', 'admin', ['days_left' => 60, 'threshold' => 90])
			->willReturn(true);
		$config = [];

		$this->runJob($this->rootJob(60, $notifications, $config));
		// The next day's run inside the same threshold stays quiet.
		$this->runJob($this->rootJob(60, $notifications, $config));
	}//end testAnExpiringRootNotifiesTheAdminsOnce()

	/**
	 * A root with a year left notifies nobody.
	 *
	 * @return void
	 */
	public function testARootWithAYearLeftNotifiesNobody(): void {
		$notifications = $this->createMock(NotificationService::class);
		$notifications->expects($this->never())->method('notify');
		$config = [];

		$this->runJob($this->rootJob(365, $notifications, $config));
	}//end testARootWithAYearLeftNotifiesNobody()

	/**
	 * Crossing into the next threshold notifies again.
	 *
	 * @return void
	 */
	public function testTheNextThresholdNotifiesAgain(): void {
		$notifications = $this->createMock(NotificationService::class);
		$notifications->expects($this->exactly(2))->method('notify')->willReturn(true);
		$config = [];

		$this->runJob($this->rootJob(60, $notifications, $config));
		$this->runJob($this->rootJob(20, $notifications, $config));
	}//end testTheNextThresholdNotifiesAgain()
}//end class
