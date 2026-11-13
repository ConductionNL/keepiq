<?php

/**
 * Unit tests for PurgeAuditLogJob.
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

use OCA\Keepiq\BackgroundJob\PurgeAuditLogJob;
use OCA\Keepiq\Service\AuditService;
use OCA\Keepiq\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Tests for PurgeAuditLogJob.
 *
 * @covers \OCA\Keepiq\BackgroundJob\PurgeAuditLogJob
 */
class PurgeAuditLogJobTest extends TestCase {
	/**
	 * The audit service that purges.
	 *
	 * @var AuditService&MockObject
	 */
	private AuditService&MockObject $auditService;

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
	 * @var PurgeAuditLogJob
	 */
	private PurgeAuditLogJob $job;

	/**
	 * Wire the job with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->auditService = $this->createMock(originalClassName: AuditService::class);
		$this->appConfig    = $this->createMock(originalClassName: IAppConfig::class);
		$this->job          = new PurgeAuditLogJob(
			time: $this->createMock(originalClassName: ITimeFactory::class),
			auditService: $this->auditService,
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
	 * Stub the stored retention.
	 *
	 * @param int $days The stored value.
	 *
	 * @return void
	 */
	private function storedRetention(int $days): void {
		$this->appConfig->expects($this->once())
			->method('getValueInt')
			->with('keepiq', 'audit_retention_days', SettingsService::AUDIT_RETENTION_DEFAULT)
			->willReturn($days);
	}//end storedRetention()

	/**
	 * The job runs daily.
	 *
	 * @return void
	 */
	public function testRunsDaily(): void {
		$this->assertSame(86400, $this->job->getInterval());
	}//end testRunsDaily()

	/**
	 * The stored retention is passed to the purge and a deletion is logged.
	 *
	 * @return void
	 */
	public function testPurgesWithTheStoredRetention(): void {
		$days = SettingsService::AUDIT_RETENTION_MIN + 10;
		$this->storedRetention($days);
		$this->auditService->expects($this->once())->method('purge')->with($days)->willReturn(7);
		$this->logger->expects($this->once())->method('info')
			->with('Keepiq: purged 7 audit entries older than '.$days.' days');

		$this->runJob();
	}//end testPurgesWithTheStoredRetention()

	/**
	 * A tampered retention below the floor is raised to the floor.
	 *
	 * @return void
	 */
	public function testRetentionBelowTheFloorIsRaised(): void {
		$this->storedRetention(1);
		$this->auditService->expects($this->once())->method('purge')
			->with(SettingsService::AUDIT_RETENTION_MIN)->willReturn(0);
		$this->logger->expects($this->never())->method('info');

		$this->runJob();
	}//end testRetentionBelowTheFloorIsRaised()

	/**
	 * Exactly the floor is kept as is.
	 *
	 * @return void
	 */
	public function testRetentionAtTheFloorIsKept(): void {
		$this->storedRetention(SettingsService::AUDIT_RETENTION_MIN);
		$this->auditService->expects($this->once())->method('purge')
			->with(SettingsService::AUDIT_RETENTION_MIN)->willReturn(0);

		$this->runJob();
	}//end testRetentionAtTheFloorIsKept()

	/**
	 * A failing purge is logged with its exception and not thrown.
	 *
	 * @return void
	 */
	public function testAFailingPurgeIsLoggedNotThrown(): void {
		$this->storedRetention(SettingsService::AUDIT_RETENTION_MIN);
		$exception = new RuntimeException('locked');
		$this->auditService->method('purge')->willThrowException($exception);
		$this->logger->expects($this->once())->method('error')
			->with('Keepiq: audit-log purge failed: locked', ['exception' => $exception]);

		$this->runJob();
	}//end testAFailingPurgeIsLoggedNotThrown()
}//end class
