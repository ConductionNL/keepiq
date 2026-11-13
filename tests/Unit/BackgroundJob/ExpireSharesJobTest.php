<?php

/**
 * Unit tests for ExpireSharesJob.
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
use OCA\Keepiq\BackgroundJob\ExpireSharesJob;
use OCA\Keepiq\Service\ShareExpiryService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Tests for ExpireSharesJob.
 *
 * @covers \OCA\Keepiq\BackgroundJob\ExpireSharesJob
 */
class ExpireSharesJobTest extends TestCase {
	/**
	 * The share expiry service.
	 *
	 * @var ShareExpiryService&MockObject
	 */
	private ShareExpiryService&MockObject $expiry;

	/**
	 * The app config holding the warned-until mark.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The job under test.
	 *
	 * @var ExpireSharesJob
	 */
	private ExpireSharesJob $job;

	/**
	 * Wire the job with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->expiry    = $this->createMock(originalClassName: ShareExpiryService::class);
		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->job       = new ExpireSharesJob(
			time: $this->createMock(originalClassName: ITimeFactory::class),
			expiry: $this->expiry,
			appConfig: $this->appConfig,
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
	 * Stub the stored warned-until mark.
	 *
	 * @param int $timestamp The stored mark.
	 *
	 * @return void
	 */
	private function warnedUntil(int $timestamp): void {
		$this->appConfig->method('getValueInt')
			->with('keepiq', ExpireSharesJob::WARNED_UNTIL_KEY, 0)
			->willReturn($timestamp);
	}//end warnedUntil()

	/**
	 * The job runs every quarter hour.
	 *
	 * @return void
	 */
	public function testRunsEveryQuarterHour(): void {
		$this->assertSame(900, $this->job->getInterval());
		$this->assertSame('share_access_warned_until', ExpireSharesJob::WARNED_UNTIL_KEY);
	}//end testRunsEveryQuarterHour()

	/**
	 * Without a mark it warns from now to a day ahead, saves the day-ahead mark and expires.
	 *
	 * @return void
	 */
	public function testWarnsTheNextDayAndSavesTheMark(): void {
		$this->warnedUntil(0);
		$start = time();
		$window = [];
		$this->expiry->expects($this->once())->method('warnEnding')
			->willReturnCallback(
				function (DateTime $from, DateTime $to) use (&$window): int {
					$window = [$from->getTimestamp(), $to->getTimestamp()];
					return 0;
				}
			);
		$saved = null;
		$this->appConfig->expects($this->once())->method('setValueInt')
			->willReturnCallback(
				function (string $app, string $key, int $value) use (&$saved): bool {
					$saved = [$app, $key, $value];
					return true;
				}
			);
		$this->expiry->expects($this->once())->method('expire')->willReturn(0);
		$this->logger->expects($this->never())->method('info');
		$this->logger->expects($this->never())->method('error');

		$this->runJob();

		$this->assertEqualsWithDelta($start, $window[0], 5);
		$this->assertSame(86400, $window[1] - $window[0]);
		$this->assertSame(['keepiq', 'share_access_warned_until', $window[1]], $saved);
	}//end testWarnsTheNextDayAndSavesTheMark()

	/**
	 * A mark in the future moves the start of the window, so no share is warned twice.
	 *
	 * @return void
	 */
	public function testAFutureMarkMovesTheWindowStart(): void {
		$mark = time() + 3600;
		$this->warnedUntil($mark);
		$from = null;
		$this->expiry->method('warnEnding')
			->willReturnCallback(
				function (DateTime $start) use (&$from): int {
					$from = $start->getTimestamp();
					return 0;
				}
			);
		$this->expiry->method('expire')->willReturn(0);

		$this->runJob();

		$this->assertSame($mark, $from);
	}//end testAFutureMarkMovesTheWindowStart()

	/**
	 * A stale mark in the past is ignored.
	 *
	 * @return void
	 */
	public function testAPastMarkIsIgnored(): void {
		$this->warnedUntil(1000);
		$from = null;
		$this->expiry->method('warnEnding')
			->willReturnCallback(
				function (DateTime $start) use (&$from): int {
					$from = $start->getTimestamp();
					return 0;
				}
			);
		$this->expiry->method('expire')->willReturn(0);

		$this->runJob();

		$this->assertEqualsWithDelta(time(), $from, 5);
	}//end testAPastMarkIsIgnored()

	/**
	 * Ended shares are logged with their count.
	 *
	 * @return void
	 */
	public function testLogsEndedShares(): void {
		$this->warnedUntil(0);
		$this->expiry->method('expire')->willReturn(4);
		$this->logger->expects($this->once())->method('info')
			->with('Keepiq: ended access to 4 shared secret(s)', ['app' => 'keepiq']);

		$this->runJob();
	}//end testLogsEndedShares()

	/**
	 * A failed warning keeps the old mark and still runs the expiry.
	 *
	 * @return void
	 */
	public function testAFailedWarningKeepsTheMarkAndStillExpires(): void {
		$this->warnedUntil(0);
		$exception = new RuntimeException('mail down');
		$this->expiry->method('warnEnding')->willThrowException($exception);
		$this->appConfig->expects($this->never())->method('setValueInt');
		$this->expiry->expects($this->once())->method('expire')->willReturn(0);
		$this->logger->expects($this->once())->method('error')
			->with('Keepiq: share expiry warning failed: mail down', ['exception' => $exception, 'app' => 'keepiq']);

		$this->runJob();
	}//end testAFailedWarningKeepsTheMarkAndStillExpires()

	/**
	 * A failed expiry is logged and not thrown.
	 *
	 * @return void
	 */
	public function testAFailedExpiryIsLoggedNotThrown(): void {
		$this->warnedUntil(0);
		$exception = new RuntimeException('deadlock');
		$this->expiry->method('expire')->willThrowException($exception);
		$this->logger->expects($this->once())->method('error')
			->with('Keepiq: share expiry failed: deadlock', ['exception' => $exception, 'app' => 'keepiq']);

		$this->runJob();
	}//end testAFailedExpiryIsLoggedNotThrown()
}//end class
