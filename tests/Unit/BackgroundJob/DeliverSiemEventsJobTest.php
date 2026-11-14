<?php

/**
 * Unit tests for DeliverSiemEventsJob.
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

use OCA\Keepiq\BackgroundJob\DeliverSiemEventsJob;
use OCA\Keepiq\Service\SiemService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Tests for DeliverSiemEventsJob.
 *
 * @covers \OCA\Keepiq\BackgroundJob\DeliverSiemEventsJob
 * @uses   \OCA\Keepiq\Service\SiemFailureOutcome
 */
class DeliverSiemEventsJobTest extends TestCase {
	/**
	 * The SIEM service the job drains.
	 *
	 * @var SiemService&MockObject
	 */
	private SiemService&MockObject $siem;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The job under test.
	 *
	 * @var DeliverSiemEventsJob
	 */
	private DeliverSiemEventsJob $job;

	/**
	 * Wire the job with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->siem = $this->createMock(originalClassName: SiemService::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->job = new DeliverSiemEventsJob(
			time: $this->createMock(originalClassName: ITimeFactory::class),
			siemService: $this->siem,
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
	 * The drain runs every minute.
	 *
	 * @return void
	 */
	public function testRunsEveryMinute(): void {
		$this->assertSame(expected: 60, actual: $this->job->getInterval());
	}//end testRunsEveryMinute()

	/**
	 * A run drains the due queue once and logs nothing.
	 *
	 * @return void
	 */
	public function testDrainsTheDueQueue(): void {
		$this->siem->expects($this->once())->method('deliverDue')->willReturn(3);
		$this->logger->expects($this->never())->method('warning');

		$this->runJob();
	}//end testDrainsTheDueQueue()

	/**
	 * A failing drain is logged by exception class only and never escapes.
	 *
	 * @return void
	 */
	public function testAFailingDrainIsLoggedByClassOnly(): void {
		$this->siem->expects($this->once())->method('deliverDue')
			->willThrowException(new RuntimeException('token=secret-value'));
		$this->logger->expects($this->once())->method('warning')
			->with('Keepiq: SIEM delivery drain failed: RuntimeException', ['app' => 'keepiq']);

		$this->runJob();
	}//end testAFailingDrainIsLoggedByClassOnly()
}//end class
