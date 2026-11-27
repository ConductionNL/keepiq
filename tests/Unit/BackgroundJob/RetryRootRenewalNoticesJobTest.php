<?php

/**
 * Unit tests for RetryRootRenewalNoticesJob.
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
use OCA\Keepiq\BackgroundJob\RetryRootRenewalNoticesJob;
use OCA\Keepiq\Service\RootRenewalNoticeDelivery;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * Tests for RetryRootRenewalNoticesJob.
 *
 * @covers \OCA\Keepiq\BackgroundJob\RetryRootRenewalNoticesJob
 */
class RetryRootRenewalNoticesJobTest extends TestCase {
	/**
	 * The service the job delegates to.
	 *
	 * @var RootRenewalNoticeDelivery&MockObject
	 */
	private RootRenewalNoticeDelivery&MockObject $notices;

	/**
	 * The job under test.
	 *
	 * @var RetryRootRenewalNoticesJob
	 */
	private RetryRootRenewalNoticesJob $job;

	/**
	 * Wire the job with a mocked delivery.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->notices = $this->createMock(originalClassName: RootRenewalNoticeDelivery::class);
		$this->job = new RetryRootRenewalNoticesJob(
			time: $this->createMock(originalClassName: ITimeFactory::class),
			notices: $this->notices,
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
	 * The job runs every minute.
	 *
	 * @return void
	 */
	public function testRunsEveryMinute(): void {
		$this->assertSame(60, $this->job->getInterval());
	}//end testRunsEveryMinute()

	/**
	 * A run sends the notices that are due.
	 *
	 * @return void
	 */
	public function testARunSendsTheDueNotices(): void {
		$this->notices->expects($this->once())->method('retryDue')->with($this->isInstanceOf(DateTime::class))->willReturn(1);

		$this->runJob();
	}//end testARunSendsTheDueNotices()

	/**
	 * A failing run is left to Nextcloud's job runner, which logs it; the notices stay due for the next run.
	 *
	 * @return void
	 */
	public function testAFailingRunIsLeftToTheJobRunner(): void {
		$this->notices->method('retryDue')->willThrowException(new RuntimeException('partner table gone'));

		$this->expectException(RuntimeException::class);
		$this->runJob();
	}//end testAFailingRunIsLeftToTheJobRunner()
}//end class
