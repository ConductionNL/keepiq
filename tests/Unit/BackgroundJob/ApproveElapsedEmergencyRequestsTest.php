<?php

/**
 * Unit tests for ApproveElapsedEmergencyRequests.
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

use OCA\Keepiq\BackgroundJob\ApproveElapsedEmergencyRequests;
use OCA\Keepiq\Service\EmergencyAccessService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Tests for ApproveElapsedEmergencyRequests.
 *
 * @covers \OCA\Keepiq\BackgroundJob\ApproveElapsedEmergencyRequests
 */
class ApproveElapsedEmergencyRequestsTest extends TestCase {
	/**
	 * The service the job delegates to.
	 *
	 * @var EmergencyAccessService&MockObject
	 */
	private EmergencyAccessService&MockObject $service;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The job under test.
	 *
	 * @var ApproveElapsedEmergencyRequests
	 */
	private ApproveElapsedEmergencyRequests $job;

	/**
	 * Wire the job with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->service = $this->createMock(originalClassName: EmergencyAccessService::class);
		$this->job = new ApproveElapsedEmergencyRequests(
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
		$this->assertSame(3600, $this->job->getInterval());
	}//end testRunsOnItsInterval()

	/**
	 * A sweep that did something is logged with its count.
	 *
	 * @return void
	 */
	public function testLogsTheCountOfAProductiveSweep(): void {
		$this->service->expects($this->once())->method('approveElapsed')->willReturn(3);
		$this->logger->expects($this->once())->method('info')->with('Keepiq: approved 3 elapsed emergency-access request(s)');
		$this->logger->expects($this->never())->method('error');

		$this->runJob();
	}//end testLogsTheCountOfAProductiveSweep()

	/**
	 * An empty sweep logs nothing.
	 *
	 * @return void
	 */
	public function testAnEmptySweepLogsNothing(): void {
		$this->service->expects($this->once())->method('approveElapsed')->willReturn(0);
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
		$this->service->expects($this->once())->method('approveElapsed')->willThrowException($exception);
		$this->logger->expects($this->never())->method('info');
		$this->logger->expects($this->once())->method('error')->with('Keepiq: emergency-access approval job failed: database gone');

		$this->runJob();
	}//end testAFailingSweepIsLoggedNotThrown()
}//end class
