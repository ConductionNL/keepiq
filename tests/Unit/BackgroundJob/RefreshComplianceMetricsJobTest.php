<?php

/**
 * Unit tests for RefreshComplianceMetricsJob.
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

use OCA\Keepiq\BackgroundJob\RefreshComplianceMetricsJob;
use OCA\Keepiq\Service\ComplianceReportService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Tests for RefreshComplianceMetricsJob.
 *
 * @covers \OCA\Keepiq\BackgroundJob\RefreshComplianceMetricsJob
 */
class RefreshComplianceMetricsJobTest extends TestCase {
	/**
	 * The collaborator the job delegates to.
	 *
	 * @var ComplianceReportService&MockObject
	 */
	private ComplianceReportService&MockObject $service;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The job under test.
	 *
	 * @var RefreshComplianceMetricsJob
	 */
	private RefreshComplianceMetricsJob $job;

	/**
	 * Wire the job with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->service = $this->createMock(originalClassName: ComplianceReportService::class);
		$this->job = new RefreshComplianceMetricsJob(
			time: $this->createMock(originalClassName: ITimeFactory::class),
			service: $this->service,
			logger: $this->logger,
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
		$this->assertSame(86400, $this->job->getInterval());
	}//end testRunsOnItsInterval()

	/**
	 * Each run calls the sweep once and logs nothing on success.
	 *
	 * @return void
	 */
	public function testSweepsOnce(): void {
		$this->service->expects($this->once())->method('refreshMetricsCache');
		$this->logger->expects($this->never())->method('warning');

		$this->runJob();
	}//end testSweepsOnce()

	/**
	 * A failing sweep is logged and does not escape the job.
	 *
	 * @return void
	 */
	public function testAFailingSweepIsLoggedNotThrown(): void {
		$exception = new RuntimeException('database gone');
		$this->service->expects($this->once())->method('refreshMetricsCache')->willThrowException($exception);
		$this->logger->expects($this->once())->method('warning')
			->with('Keepiq: compliance metrics refresh failed: database gone', ['app' => 'keepiq']);

		$this->runJob();
	}//end testAFailingSweepIsLoggedNotThrown()
}//end class
