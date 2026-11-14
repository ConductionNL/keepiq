<?php

/**
 * Unit tests for PruneSecretVersionsJob.
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
use OCA\Keepiq\BackgroundJob\PruneSecretVersionsJob;
use OCA\Keepiq\Db\SecretVersionMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Tests for PruneSecretVersionsJob.
 *
 * @covers \OCA\Keepiq\BackgroundJob\PruneSecretVersionsJob
 */
class PruneSecretVersionsJobTest extends TestCase {
	/**
	 * The version mapper.
	 *
	 * @var SecretVersionMapper&MockObject
	 */
	private SecretVersionMapper&MockObject $mapper;

	/**
	 * The app config holding the retention.
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
	 * @var PruneSecretVersionsJob
	 */
	private PruneSecretVersionsJob $job;

	/**
	 * Wire the job with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->mapper    = $this->createMock(originalClassName: SecretVersionMapper::class);
		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->job       = new PruneSecretVersionsJob(
			time: $this->createMock(originalClassName: ITimeFactory::class),
			mapper: $this->mapper,
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
	 * Stub the two retention settings.
	 *
	 * @param int $count The kept-version count.
	 * @param int $days  The age limit in days.
	 *
	 * @return void
	 */
	private function retention(int $count, int $days): void {
		$this->appConfig->method('getValueInt')->willReturnMap(
			[
				['keepiq', 'version_retention_count', 20, false, $count],
				['keepiq', 'version_retention_days', 365, false, $days],
			]
		);
	}//end retention()

	/**
	 * The job runs daily.
	 *
	 * @return void
	 */
	public function testRunsDaily(): void {
		$this->assertSame(86400, $this->job->getInterval());
	}//end testRunsDaily()

	/**
	 * Each secret is pruned by count, then everything by age, and the total is logged.
	 *
	 * @return void
	 */
	public function testPrunesByCountPerSecretThenByAge(): void {
		$this->retention(5, 30);
		$this->mapper->method('findSecretIdsWithVersions')->willReturn(['a', 42]);
		$calls = [];
		$this->mapper->expects($this->exactly(2))->method('pruneByCount')
			->willReturnCallback(
				function (string $secretId, int $keep) use (&$calls): int {
					$calls[] = [$secretId, $keep];
					return 2;
				}
			);
		$before = new DateTime('-30 days');
		$this->mapper->expects($this->once())->method('pruneOlderThan')
			->with(
				$this->callback(
					static fn (DateTime $cutoff): bool => abs($cutoff->getTimestamp() - $before->getTimestamp()) <= 5
				)
			)
			->willReturn(1);
		$this->logger->expects($this->once())->method('info')
			->with('Keepiq: pruned 5 secret versions beyond retention', ['app' => 'keepiq']);

		$this->runJob();

		$this->assertSame([['a', 5], ['42', 5]], $calls);
	}//end testPrunesByCountPerSecretThenByAge()

	/**
	 * A count below one is raised to one, so the latest version always survives.
	 *
	 * @return void
	 */
	public function testKeepsAtLeastOneVersion(): void {
		$this->retention(0, 365);
		$this->mapper->method('findSecretIdsWithVersions')->willReturn(['s']);
		$this->mapper->expects($this->once())->method('pruneByCount')->with('s', 1)->willReturn(0);
		$this->mapper->method('pruneOlderThan')->willReturn(0);
		$this->logger->expects($this->never())->method('info');

		$this->runJob();
	}//end testKeepsAtLeastOneVersion()

	/**
	 * An age limit of zero or less disables the age prune.
	 *
	 * @return void
	 */
	public function testZeroDaysSkipsTheAgePrune(): void {
		$this->retention(20, 0);
		$this->mapper->method('findSecretIdsWithVersions')->willReturn([]);
		$this->mapper->expects($this->never())->method('pruneByCount');
		$this->mapper->expects($this->never())->method('pruneOlderThan');
		$this->logger->expects($this->never())->method('info');

		$this->runJob();
	}//end testZeroDaysSkipsTheAgePrune()

	/**
	 * A failure is logged as a warning and not thrown.
	 *
	 * @return void
	 */
	public function testAFailureIsLoggedNotThrown(): void {
		$this->retention(20, 365);
		$this->mapper->method('findSecretIdsWithVersions')->willThrowException(new RuntimeException('no table'));
		$this->mapper->expects($this->never())->method('pruneOlderThan');
		$this->logger->expects($this->once())->method('warning')
			->with('Keepiq: version prune failed: no table', ['app' => 'keepiq']);

		$this->runJob();
	}//end testAFailureIsLoggedNotThrown()
}//end class
