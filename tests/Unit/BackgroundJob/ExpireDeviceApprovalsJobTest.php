<?php

/**
 * Unit tests for ExpireDeviceApprovalsJob.
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
use OCA\Keepiq\BackgroundJob\ExpireDeviceApprovalsJob;
use OCA\Keepiq\Service\DeviceApprovalService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Tests for ExpireDeviceApprovalsJob.
 *
 * @covers \OCA\Keepiq\BackgroundJob\ExpireDeviceApprovalsJob
 */
class ExpireDeviceApprovalsJobTest extends TestCase {
	/**
	 * The collaborator the job delegates to.
	 *
	 * @var DeviceApprovalService&MockObject
	 */
	private DeviceApprovalService&MockObject $approvals;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The job under test.
	 *
	 * @var ExpireDeviceApprovalsJob
	 */
	private ExpireDeviceApprovalsJob $job;

	/**
	 * Wire the job with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->approvals = $this->createMock(originalClassName: DeviceApprovalService::class);
		$this->job = new ExpireDeviceApprovalsJob(
			time: $this->createMock(originalClassName: ITimeFactory::class),
			approvals: $this->approvals,
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
		$this->assertSame(300, $this->job->getInterval());
	}//end testRunsOnItsInterval()

	/**
	 * The sweep is asked to expire as of the current time.
	 *
	 * @return void
	 */
	public function testSweepsAsOfNow(): void {
		$seen = null;
		$this->approvals->expects($this->once())->method('expireLapsed')
			->willReturnCallback(
				function (DateTime $now) use (&$seen): int {
					$seen = $now->getTimestamp();
					return 2;
				}
			);
		$this->logger->expects($this->never())->method('error');

		$this->runJob();

		$this->assertEqualsWithDelta(time(), $seen, 5);
	}//end testSweepsAsOfNow()

	/**
	 * A failing sweep is logged and does not escape the job.
	 *
	 * @return void
	 */
	public function testAFailingSweepIsLoggedNotThrown(): void {
		$exception = new RuntimeException('database gone');
		$this->approvals->expects($this->once())->method('expireLapsed')->willThrowException($exception);
		$this->logger->expects($this->once())->method('error')
			->with('Keepiq: device approval expiry failed: database gone', ['exception' => $exception, 'app' => 'keepiq']);

		$this->runJob();
	}//end testAFailingSweepIsLoggedNotThrown()
}//end class
