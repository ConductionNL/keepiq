<?php

/**
 * Unit tests for RefreshSiteDirectoryJob.
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\BackgroundJob;

use OCA\Keepiq\BackgroundJob\RefreshSiteDirectoryJob;
use OCA\Keepiq\Service\SiteDirectoryService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * The daily refresh: runs the service, never throws.
 */
#[CoversClass(RefreshSiteDirectoryJob::class)]
class RefreshSiteDirectoryJobTest extends TestCase {

	/**
	 * Run the job's protected run().
	 *
	 * @param RefreshSiteDirectoryJob $job The job
	 *
	 * @return void
	 */
	private function runJob(RefreshSiteDirectoryJob $job): void {
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end runJob()

	/**
	 * Daily, and each run refreshes the copy.
	 *
	 * @return void
	 */
	public function testItRefreshesOnceADay(): void {
		$service = $this->createMock(SiteDirectoryService::class);
		$service->expects($this->once())->method('refresh')->willReturn(true);
		$job = new RefreshSiteDirectoryJob(
			time: $this->createMock(ITimeFactory::class),
			service: $service,
			logger: $this->createMock(LoggerInterface::class)
		);

		$this->runJob(job: $job);

		$this->assertSame(86400, (new ReflectionProperty($job, 'interval'))->getValue($job));
	}//end testItRefreshesOnceADay()

	/**
	 * A failure is logged and does not escape the job.
	 *
	 * @return void
	 */
	public function testAFailureIsLoggedNotThrown(): void {
		$service = $this->createMock(SiteDirectoryService::class);
		$service->method('refresh')->willThrowException(new RuntimeException('app data unavailable'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('app data unavailable'));

		$this->runJob(job: new RefreshSiteDirectoryJob(time: $this->createMock(ITimeFactory::class), service: $service, logger: $logger));
	}//end testAFailureIsLoggedNotThrown()
}//end class
