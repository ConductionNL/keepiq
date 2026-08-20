<?php

/**
 * Unit tests for DashboardSummaryService.
 *
 * These four cases were relocated verbatim from DashboardServiceTest when
 * the summary aggregator was extracted out of DashboardService; only the
 * constructor call changed (the aggregator no longer takes the
 * DashboardSettingMapper, which it never used).
 *
 * @category Test
 * @package  OCA\Doriath\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Doriath\Tests\Unit\Service;

use OCA\Doriath\Db\ApplicationMapper;
use OCA\Doriath\Db\FolderMapper;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\ShareTargetMapper;
use OCA\Doriath\Service\DashboardSummaryService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for DashboardSummaryService.
 */
class DashboardSummaryServiceTest extends TestCase {

	/**
	 * Service under test, wired with no aggregator dependencies.
	 *
	 * @var DashboardSummaryService
	 */
	private DashboardSummaryService $service;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->service = new DashboardSummaryService(logger: $logger);
	}//end setUp()

	/**
	 * fetchSummary aggregates counts from the wired mappers for an admin.
	 *
	 * @return void
	 */
	public function testFetchSummaryAggregatesForAdmin(): void {
		$secretMapper = $this->createMock(originalClassName: SecretMapper::class);
		$secretMapper->expects($this->once())
			->method('countByOwner')
			->with('user', 'alice', null)
			->willReturn(7);

		$folderMapper = $this->createMock(originalClassName: FolderMapper::class);
		$folderMapper->expects($this->once())
			->method('findByOwner')
			->with('user', 'alice')
			->willReturn([1, 2, 3]);

		$shareMapper = $this->createMock(originalClassName: ShareTargetMapper::class);
		$shareMapper->expects($this->once())
			->method('findByTargetUser')
			->with('alice')
			->willReturn([1, 2]);

		$appMapper = $this->createMock(originalClassName: ApplicationMapper::class);
		$appMapper->expects($this->once())
			->method('countPending')
			->willReturn(5);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$service = new DashboardSummaryService(
			logger: $logger,
			secretMapper: $secretMapper,
			folderMapper: $folderMapper,
			shareTargetMapper: $shareMapper,
			applicationMapper: $appMapper,
		);

		$summary = $service->fetchSummary(userId: 'alice', isAdmin: true);

		$this->assertSame(7, $summary['total_secrets']);
		$this->assertSame(2, $summary['shared_with_me_count']);
		$this->assertSame(3, $summary['folders_count']);
		$this->assertSame(5, $summary['pending_apps_count']);
		$this->assertTrue($summary['is_admin']);
		$this->assertArrayHasKey('last_updated', $summary);
	}//end testFetchSummaryAggregatesForAdmin()

	/**
	 * fetchSummary omits the pending-apps count for non-admins.
	 *
	 * @return void
	 */
	public function testFetchSummaryOmitsPendingAppsForNonAdmin(): void {
		$secretMapper = $this->createMock(originalClassName: SecretMapper::class);
		$secretMapper->method('countByOwner')->willReturn(1);
		$folderMapper = $this->createMock(originalClassName: FolderMapper::class);
		$folderMapper->method('findByOwner')->willReturn([]);
		$shareMapper = $this->createMock(originalClassName: ShareTargetMapper::class);
		$shareMapper->method('findByTargetUser')->willReturn([]);

		$appMapper = $this->createMock(originalClassName: ApplicationMapper::class);
		$appMapper->expects($this->never())->method('countPending');

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$service = new DashboardSummaryService(
			logger: $logger,
			secretMapper: $secretMapper,
			folderMapper: $folderMapper,
			shareTargetMapper: $shareMapper,
			applicationMapper: $appMapper,
		);

		$summary = $service->fetchSummary(userId: 'bob', isAdmin: false);

		$this->assertNull($summary['pending_apps_count']);
		$this->assertFalse($summary['is_admin']);
	}//end testFetchSummaryOmitsPendingAppsForNonAdmin()

	/**
	 * fetchSummary degrades a failing mapper to zero rather than throwing.
	 *
	 * @return void
	 */
	public function testFetchSummaryDegradesOnMapperFailure(): void {
		$secretMapper = $this->createMock(originalClassName: SecretMapper::class);
		$secretMapper->method('countByOwner')->willThrowException(new RuntimeException('db down'));
		$folderMapper = $this->createMock(originalClassName: FolderMapper::class);
		$folderMapper->method('findByOwner')->willReturn([]);
		$shareMapper = $this->createMock(originalClassName: ShareTargetMapper::class);
		$shareMapper->method('findByTargetUser')->willReturn([]);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->atLeastOnce())->method('warning');
		$service = new DashboardSummaryService(
			logger: $logger,
			secretMapper: $secretMapper,
			folderMapper: $folderMapper,
			shareTargetMapper: $shareMapper,
			applicationMapper: null,
		);

		$summary = $service->fetchSummary(userId: 'alice', isAdmin: false);

		$this->assertSame(0, $summary['total_secrets']);
	}//end testFetchSummaryDegradesOnMapperFailure()

	/**
	 * fetchSummary tolerates missing aggregator dependencies by reporting
	 * zeroes.
	 *
	 * @return void
	 */
	public function testFetchSummaryWithoutAggregatorDepsReturnsZeroes(): void {
		$summary = $this->service->fetchSummary(userId: 'alice', isAdmin: false);

		$this->assertSame(0, $summary['total_secrets']);
		$this->assertSame(0, $summary['shared_with_me_count']);
		$this->assertSame(0, $summary['folders_count']);
		$this->assertNull($summary['pending_apps_count']);
	}//end testFetchSummaryWithoutAggregatorDepsReturnsZeroes()
}//end class
