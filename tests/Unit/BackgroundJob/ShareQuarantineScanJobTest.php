<?php

/**
 * Unit tests for ShareQuarantineScanJob.
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

use OCA\Keepiq\BackgroundJob\ShareQuarantineScanJob;
use OCA\Keepiq\Service\ShareQuarantineScanner;
use OCA\Keepiq\Service\ShareRestrictionResolver;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Tests for ShareQuarantineScanJob.
 *
 * @covers \OCA\Keepiq\BackgroundJob\ShareQuarantineScanJob
 */
class ShareQuarantineScanJobTest extends TestCase {
	/**
	 * The quarantine scanner.
	 *
	 * @var ShareQuarantineScanner&MockObject
	 */
	private ShareQuarantineScanner&MockObject $scanner;

	/**
	 * The restriction resolver.
	 *
	 * @var ShareRestrictionResolver&MockObject
	 */
	private ShareRestrictionResolver&MockObject $restrictions;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The job under test.
	 *
	 * @var ShareQuarantineScanJob
	 */
	private ShareQuarantineScanJob $job;

	/**
	 * Wire the job with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->scanner      = $this->createMock(originalClassName: ShareQuarantineScanner::class);
		$this->restrictions = $this->createMock(originalClassName: ShareRestrictionResolver::class);
		$this->job          = new ShareQuarantineScanJob(
			time: $this->createMock(originalClassName: ITimeFactory::class),
			scanner: $this->scanner,
			restrictions: $this->restrictions,
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
	 * The job runs every quarter hour.
	 *
	 * @return void
	 */
	public function testRunsEveryQuarterHour(): void {
		$this->assertSame(900, $this->job->getInterval());
	}//end testRunsEveryQuarterHour()

	/**
	 * An incomplete scan runs; if it is still incomplete nothing is resolved.
	 *
	 * @return void
	 */
	public function testAnIncompleteScanRunsAndHoldsTheResolve(): void {
		$this->scanner->method('isComplete')->willReturn(false);
		$this->scanner->expects($this->once())->method('run');
		$this->scanner->expects($this->never())->method('heldBackResolve');
		$this->restrictions->expects($this->never())->method('resolveAll');

		$this->runJob();
	}//end testAnIncompleteScanRunsAndHoldsTheResolve()

	/**
	 * A scan that completes in this run resolves the held-back copies and clears the record.
	 *
	 * @return void
	 */
	public function testAScanCompletingNowResolvesTheHeldBackCopies(): void {
		$this->scanner->method('isComplete')->willReturnOnConsecutiveCalls(false, true);
		$this->scanner->expects($this->once())->method('run');
		$this->scanner->method('heldBackResolve')->willReturn('rec-1');
		$this->restrictions->expects($this->once())->method('resolveAll')->willReturn(6);
		$this->scanner->expects($this->once())->method('clearHeldBackResolve')->with('rec-1');
		$this->logger->expects($this->once())->method('info')
			->with(
				'GHSA-m5p9-6pw7-pf3g: resolved the copies the pending share quarantine scan held back; 6 copy(ies) written',
				['app' => 'keepiq']
			);

		$this->runJob();
	}//end testAScanCompletingNowResolvesTheHeldBackCopies()

	/**
	 * A complete scan is not run again, and without a record nothing is resolved.
	 *
	 * @return void
	 */
	public function testACompleteScanWithoutARecordDoesNothing(): void {
		$this->scanner->method('isComplete')->willReturn(true);
		$this->scanner->expects($this->never())->method('run');
		$this->scanner->method('heldBackResolve')->willReturn(null);
		$this->restrictions->expects($this->never())->method('resolveAll');
		$this->scanner->expects($this->never())->method('clearHeldBackResolve');
		$this->logger->expects($this->never())->method('info');

		$this->runJob();
	}//end testACompleteScanWithoutARecordDoesNothing()

	/**
	 * A failing resolve keeps the record so the next run tries again.
	 *
	 * @return void
	 */
	public function testAFailingResolveKeepsTheRecord(): void {
		$this->scanner->method('isComplete')->willReturn(true);
		$this->scanner->method('heldBackResolve')->willReturn('rec-2');
		$exception = new RuntimeException('busy');
		$this->restrictions->method('resolveAll')->willThrowException($exception);
		$this->scanner->expects($this->never())->method('clearHeldBackResolve');
		$this->logger->expects($this->never())->method('info');
		$this->logger->expects($this->once())->method('error')
			->with(
				'GHSA-m5p9-6pw7-pf3g: resolving the copies the pending share quarantine scan held back failed and will run again: busy',
				['app' => 'keepiq', 'exception' => $exception]
			);

		$this->runJob();
	}//end testAFailingResolveKeepsTheRecord()
}//end class
