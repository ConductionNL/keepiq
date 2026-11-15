<?php

/**
 * Unit tests for ScheduledVaultBackupJob.
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

use OCA\Keepiq\Backup\BackupService;
use OCA\Keepiq\BackgroundJob\ScheduledVaultBackupJob;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * Tests for ScheduledVaultBackupJob.
 *
 * @covers \OCA\Keepiq\BackgroundJob\ScheduledVaultBackupJob
 */
class ScheduledVaultBackupJobTest extends TestCase {
	/**
	 * The backup service the job drives.
	 *
	 * @var BackupService&MockObject
	 */
	private BackupService&MockObject $backups;

	/**
	 * The job under test.
	 *
	 * @var ScheduledVaultBackupJob
	 */
	private ScheduledVaultBackupJob $job;

	/**
	 * Wire the job with a mocked backup service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->backups = $this->createMock(originalClassName: BackupService::class);
		$this->job = new ScheduledVaultBackupJob(
			time: $this->createMock(originalClassName: ITimeFactory::class),
			backups: $this->backups,
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
	 * The job wakes hourly and may be deferred to a quiet window.
	 *
	 * @return void
	 */
	public function testWakesHourlyAndIsTimeInsensitive(): void {
		$this->assertSame(expected: 3600, actual: $this->job->getInterval());
		$this->assertFalse(condition: $this->job->isTimeSensitive());
	}//end testWakesHourlyAndIsTimeInsensitive()

	/**
	 * Nothing is written when no backup is due.
	 *
	 * @return void
	 */
	public function testDoesNothingWhenNoBackupIsDue(): void {
		$this->backups->expects($this->once())->method('isDue')->willReturn(false);
		$this->backups->expects($this->never())->method('createBackup');

		$this->runJob();
	}//end testDoesNothingWhenNoBackupIsDue()

	/**
	 * A due backup is written once.
	 *
	 * @return void
	 */
	public function testWritesABackupWhenDue(): void {
		$this->backups->expects($this->once())->method('isDue')->willReturn(true);
		$this->backups->expects($this->once())->method('createBackup')->willReturn(['id' => 'b1']);

		$this->runJob();
	}//end testWritesABackupWhenDue()

	/**
	 * A failing backup never breaks the cron run.
	 *
	 * @return void
	 */
	public function testAFailingBackupDoesNotEscape(): void {
		$this->backups->method('isDue')->willReturn(true);
		$this->backups->expects($this->once())->method('createBackup')
			->willThrowException(new RuntimeException('disk full'));

		$this->runJob();

		// Reaching this line is the assertion: run() swallowed the failure.
		$this->addToAssertionCount(count: 1);
	}//end testAFailingBackupDoesNotEscape()
}//end class
