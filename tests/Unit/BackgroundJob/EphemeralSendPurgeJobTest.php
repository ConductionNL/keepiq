<?php

/**
 * Unit tests for EphemeralSendPurgeJob.
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

use OCA\Keepiq\BackgroundJob\EphemeralSendPurgeJob;
use OCA\Keepiq\Service\EphemeralSendService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Tests for EphemeralSendPurgeJob.
 *
 * @covers \OCA\Keepiq\BackgroundJob\EphemeralSendPurgeJob
 */
class EphemeralSendPurgeJobTest extends TestCase {
	/**
	 * The service the job delegates to.
	 *
	 * @var EphemeralSendService&MockObject
	 */
	private EphemeralSendService&MockObject $service;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The job under test.
	 *
	 * @var EphemeralSendPurgeJob
	 */
	private EphemeralSendPurgeJob $job;

	/**
	 * Wire the job with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->service = $this->createMock(originalClassName: EphemeralSendService::class);
		$this->job = new EphemeralSendPurgeJob(
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
		$this->service->expects($this->once())->method('purge')->willReturn(3);
		$this->logger->expects($this->once())->method('info')->with('Keepiq: purged 3 dead ephemeral sends', ['app' => 'keepiq']);
		$this->logger->expects($this->never())->method('warning');

		$this->runJob();
	}//end testLogsTheCountOfAProductiveSweep()

	/**
	 * An empty sweep logs nothing.
	 *
	 * @return void
	 */
	public function testAnEmptySweepLogsNothing(): void {
		$this->service->expects($this->once())->method('purge')->willReturn(0);
		$this->logger->expects($this->never())->method('info');
		$this->logger->expects($this->never())->method('warning');

		$this->runJob();
	}//end testAnEmptySweepLogsNothing()

	/**
	 * A failing sweep is logged and does not escape the job.
	 *
	 * @return void
	 */
	public function testAFailingSweepIsLoggedNotThrown(): void {
		$exception = new RuntimeException('database gone');
		$this->service->expects($this->once())->method('purge')->willThrowException($exception);
		$this->logger->expects($this->never())->method('info');
		$this->logger->expects($this->once())->method('warning')->with('Keepiq: ephemeral-send purge failed: database gone', ['app' => 'keepiq']);

		$this->runJob();
	}//end testAFailingSweepIsLoggedNotThrown()
}//end class
