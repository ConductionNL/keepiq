<?php

/**
 * Unit tests for PurgeExtensionChallengesJob.
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

use OCA\Keepiq\BackgroundJob\PurgeExtensionChallengesJob;
use OCA\Keepiq\Service\ExtensionChallengeStore;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Tests for PurgeExtensionChallengesJob.
 *
 * @covers \OCA\Keepiq\BackgroundJob\PurgeExtensionChallengesJob
 */
class PurgeExtensionChallengesJobTest extends TestCase {
	/**
	 * The collaborator the job delegates to.
	 *
	 * @var ExtensionChallengeStore&MockObject
	 */
	private ExtensionChallengeStore&MockObject $challenges;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The job under test.
	 *
	 * @var PurgeExtensionChallengesJob
	 */
	private PurgeExtensionChallengesJob $job;

	/**
	 * Wire the job with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->challenges = $this->createMock(originalClassName: ExtensionChallengeStore::class);
		$this->job = new PurgeExtensionChallengesJob(
			time: $this->createMock(originalClassName: ITimeFactory::class),
			challenges: $this->challenges,
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
	 * Each run calls the sweep once and logs nothing on success.
	 *
	 * @return void
	 */
	public function testSweepsOnce(): void {
		$this->challenges->expects($this->once())->method('purgeExpired')->willReturn(4);
		$this->logger->expects($this->never())->method('error');

		$this->runJob();
	}//end testSweepsOnce()

	/**
	 * A failing sweep is logged and does not escape the job.
	 *
	 * @return void
	 */
	public function testAFailingSweepIsLoggedNotThrown(): void {
		$exception = new RuntimeException('database gone');
		$this->challenges->expects($this->once())->method('purgeExpired')->willThrowException($exception);
		$this->logger->expects($this->once())->method('error')
			->with('Keepiq: extension passkey challenge purge failed: database gone', ['exception' => $exception, 'app' => 'keepiq']);

		$this->runJob();
	}//end testAFailingSweepIsLoggedNotThrown()
}//end class
