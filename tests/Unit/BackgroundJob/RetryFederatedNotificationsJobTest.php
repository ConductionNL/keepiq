<?php

/**
 * Unit tests for RetryFederatedNotificationsJob.
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

use OCA\Keepiq\BackgroundJob\RetryFederatedNotificationsJob;
use OCA\Keepiq\Service\FederatedNotificationDelivery;
use OCA\Keepiq\Service\PartnerShareRevoker;
use OCA\Keepiq\Service\RootRenewalNoticeDelivery;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Tests for RetryFederatedNotificationsJob.
 *
 * @covers \OCA\Keepiq\BackgroundJob\RetryFederatedNotificationsJob
 */
class RetryFederatedNotificationsJobTest extends TestCase {
	/**
	 * The service the job delegates to.
	 *
	 * @var FederatedNotificationDelivery&MockObject
	 */
	private FederatedNotificationDelivery&MockObject $delivery;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The job under test.
	 *
	 * @var RetryFederatedNotificationsJob
	 */
	private RetryFederatedNotificationsJob $job;

	/**
	 * Wire the job with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->delivery = $this->createMock(originalClassName: FederatedNotificationDelivery::class);
		$this->job = new RetryFederatedNotificationsJob(
			time: $this->createMock(originalClassName: ITimeFactory::class),
			delivery: $this->delivery,
			logger: $this->logger,
			partnerShares: $this->createStub(PartnerShareRevoker::class),
		);
	}//end setUp()

	/**
	 * Run the job's protected run() once.
	 *
	 * @return void
	 */
	private function runJob(): void {
		(new ReflectionMethod($this->job, 'run'))->invoke($this->job, null);
	}//end runJob()

	/**
	 * The job runs on its fixed interval.
	 *
	 * @return void
	 */
	public function testRunsOnItsInterval(): void {
		$this->assertSame(60, $this->job->getInterval());
	}//end testRunsOnItsInterval()

	/**
	 * A sweep that did something is logged with its count.
	 *
	 * @return void
	 */
	public function testLogsTheCountOfAProductiveSweep(): void {
		$this->delivery->expects($this->once())->method('retryDue')->with($this->isInstanceOf(\DateTime::class))->willReturn(3);
		$this->logger->expects($this->once())->method('info')->with('Keepiq: delivered 3 federated share notification(s) on retry', ['app' => 'keepiq']);
		$this->logger->expects($this->never())->method('error');

		$this->runJob();
	}//end testLogsTheCountOfAProductiveSweep()

	/**
	 * An empty sweep logs nothing.
	 *
	 * @return void
	 */
	public function testAnEmptySweepLogsNothing(): void {
		$this->delivery->expects($this->once())->method('retryDue')->willReturn(0);
		$this->logger->expects($this->never())->method('info');
		$this->logger->expects($this->never())->method('error');

		$this->runJob();
	}//end testAnEmptySweepLogsNothing()

	/**
	 * A failing sweep is logged and does not escape the job.
	 *
	 * @return void
	 */
	public function testAFailingSweepIsLoggedNotThrown(): void {
		$exception = new RuntimeException('database gone');
		$this->delivery->expects($this->once())->method('retryDue')->willThrowException($exception);
		$this->logger->expects($this->never())->method('info');
		$this->logger->expects($this->once())->method('error')->with('Keepiq: federated notification retry failed: database gone', ['exception' => $exception, 'app' => 'keepiq']);

		$this->runJob();
	}//end testAFailingSweepIsLoggedNotThrown()

	/**
	 * The same run retries the root renewal notices to partners, also when the share sweep failed, and a failing
	 * notice retry is logged, not thrown.
	 *
	 * @return void
	 */
	public function testTheRunAlsoRetriesRootRenewalNotices(): void {
		$notices = $this->createMock(originalClassName: RootRenewalNoticeDelivery::class);
		$exception = new RuntimeException('partner table gone');
		$notices->expects($this->exactly(2))->method('retryDue')
			->with($this->isInstanceOf(\DateTime::class))
			->willReturnOnConsecutiveCalls(1, $this->throwException($exception));
		$this->delivery->method('retryDue')->willThrowException(new RuntimeException('database gone'));
		$this->job = new RetryFederatedNotificationsJob(
			time: $this->createMock(originalClassName: ITimeFactory::class),
			delivery: $this->delivery,
			logger: $this->logger,
			partnerShares: $this->createStub(PartnerShareRevoker::class),
			renewalNotices: $notices,
		);
		$errors = [];
		$this->logger->method('error')->willReturnCallback(function (string $message) use (&$errors): void {
			$errors[] = $message;
		});

		$this->runJob();
		$this->runJob();

		$this->assertContains('Keepiq: root renewal notice retry failed: partner table gone', $errors);
	}//end testTheRunAlsoRetriesRootRenewalNotices()
}//end class
