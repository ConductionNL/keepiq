<?php

/**
 * Unit tests for ScanCertificateExpiryJob (certificate-lifecycle §6.3).
 *
 * @category Test
 * @package  OCA\Doriath\Tests\Unit\BackgroundJob
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

namespace OCA\Doriath\Tests\Unit\BackgroundJob;

use DateTime;
use OCA\Doriath\BackgroundJob\ScanCertificateExpiryJob;
use OCA\Doriath\Db\EncryptionSuite;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Service\CertificateLifecycleService;
use OCA\Doriath\Service\NotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * Tests for the suite-certificate expiry scan.
 */
class ScanCertificateExpiryJobTest extends TestCase {
	private ScanCertificateExpiryJob $job;

	private EncryptionSuiteMapper&MockObject $suiteMapper;

	private CertificateLifecycleService&MockObject $lifecycleService;

	private NotificationService&MockObject $notificationService;

	/**
	 * Build the job over mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->suiteMapper = $this->createMock(originalClassName: EncryptionSuiteMapper::class);
		$this->lifecycleService = $this->createMock(originalClassName: CertificateLifecycleService::class);
		$this->notificationService = $this->createMock(originalClassName: NotificationService::class);

		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime());
		$time->method('getTime')->willReturn(time());

		$this->job = new ScanCertificateExpiryJob(
			time: $time,
			suiteMapper: $this->suiteMapper,
			lifecycleService: $this->lifecycleService,
			notificationService: $this->notificationService,
			logger: new NullLogger(),
		);
	}//end setUp()

	/**
	 * Invoke the protected run().
	 *
	 * @return void
	 */
	private function runJob(): void {
		$run = new ReflectionMethod($this->job, 'run');
		$run->invoke($this->job, null);
	}//end runJob()

	/**
	 * A user-owned suite whose parsed notAfter lands on a threshold.
	 *
	 * @param string $ownerType The suite owner type
	 * @param int $daysLeft Days until notAfter
	 *
	 * @return EncryptionSuite
	 */
	private function makeSuite(string $ownerType, int $daysLeft): EncryptionSuite {
		$suite = new EncryptionSuite();
		$suite->setId('suite-' . $ownerType . '-' . $daysLeft);
		$suite->setOwnerType($ownerType);
		$suite->setOwnerId('alice');
		$suite->setCertificate('PEM-PLACEHOLDER');
		$this->lifecycleService->method('parseCaCertificate')->willReturn(
			['notAfter' => (new DateTime('+' . $daysLeft . ' days +5 minutes'))->format('c')]
		);

		return $suite;
	}//end makeSuite()

	/**
	 * A threshold hit (30 days) notifies the owner exactly once (§6.3;
	 * dedup = exact-day match on a daily cadence).
	 *
	 * @return void
	 */
	public function testNotifiesAtThreshold(): void {
		$this->suiteMapper->method('findAllActiveWithLimit')
			->willReturnOnConsecutiveCalls([$this->makeSuite('user', 30)], []);

		$this->notificationService->expects($this->once())->method('notify')
			->with(
				$this->equalTo('certificate_expiring'),
				$this->equalTo('alice'),
				$this->callback(static fn (array $p): bool => ($p['days_left'] ?? null) === 30),
			);

		$this->runJob();
	}//end testNotifiesAtThreshold()

	/**
	 * Off-threshold days produce no reminder (§6.3).
	 *
	 * @return void
	 */
	public function testSilentOffThreshold(): void {
		$this->suiteMapper->method('findAllActiveWithLimit')
			->willReturnOnConsecutiveCalls([$this->makeSuite('user', 15)], []);

		$this->notificationService->expects($this->never())->method('notify');

		$this->runJob();
	}//end testSilentOffThreshold()

	/**
	 * Application-owned suites notify no user — their certs are
	 * auto-re-signed and surface in CA health instead (§6.3).
	 *
	 * @return void
	 */
	public function testApplicationSuitesAreSkipped(): void {
		$this->suiteMapper->method('findAllActiveWithLimit')
			->willReturnOnConsecutiveCalls([$this->makeSuite('application', 30)], []);

		$this->notificationService->expects($this->never())->method('notify');

		$this->runJob();
	}//end testApplicationSuitesAreSkipped()
}//end class
