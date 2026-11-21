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
 * @package  OCA\Keepiq\Tests\Unit\Service
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

namespace OCA\Keepiq\Tests\Unit\Service;

use OCA\Keepiq\Db\ApplicationMapper;
use OCA\Keepiq\Db\FolderMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Service\DashboardSummaryService;
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

	/**
	 * The summary's keys come in contract order, every counter is read once
	 * in that order, and each failing counter is logged under its metric id.
	 *
	 * @return void
	 */
	public function testTheSummaryKeysAndCountersComeInContractOrder(): void {
		$calls = [];
		$secrets = $this->createMock(SecretMapper::class);
		$secrets->method('countByOwner')->willReturnCallback(static function (...$args) use (&$calls): int {
			$calls[] = ['secrets', $args[0], $args[1], $args[4] ?? null];
			return 4;
		});
		$shares = $this->createMock(ShareTargetMapper::class);
		$shares->method('findByTargetUser')->willReturnCallback(static function (string $userId) use (&$calls): array {
			$calls[] = ['shares', $userId];
			throw new RuntimeException('shares down');
		});
		$folders = $this->createMock(FolderMapper::class);
		$folders->method('findByOwner')->willReturnCallback(static function (...$args) use (&$calls): array {
			$calls[] = ['folders', $args[0], $args[1]];
			return [1, 2];
		});
		$flags = $this->createMock(\OCA\Keepiq\Db\RotationFlagMapper::class);
		$flags->method('findOpenForOwner')->willReturnCallback(static function (string $ownerId) use (&$calls): array {
			$calls[] = ['flags', $ownerId];
			return [1];
		});
		$apps = $this->createMock(ApplicationMapper::class);
		$apps->method('countPending')->willReturnCallback(static function () use (&$calls): int {
			$calls[] = ['apps'];
			return 2;
		});
		$honey = $this->createMock(\OCA\Keepiq\Db\HoneyAlertMapper::class);
		$honey->method('countUnacknowledged')->willReturnCallback(static function () use (&$calls): int {
			$calls[] = ['honey'];
			throw new RuntimeException('honey down');
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(static function (string $message) use (&$calls): void {
			$calls[] = ['warning', $message];
		});

		$service = new DashboardSummaryService(
			logger: $logger,
			secretMapper: $secrets,
			folderMapper: $folders,
			shareTargetMapper: $shares,
			applicationMapper: $apps,
			rotationFlagMapper: $flags,
			honeyAlertMapper: $honey,
		);

		$admin = $service->fetchSummary(userId: 'alice', isAdmin: true);
		$this->assertSame(
			['total_secrets', 'shared_with_me_count', 'folders_count', 'rotation_due_count', 'pending_apps_count', 'is_admin', 'last_updated', 'ca_health', 'honey_alert_count'],
			array_keys($admin)
		);
		$this->assertSame([4, 0, 2, 1, 2, true, null, 0], [$admin['total_secrets'], $admin['shared_with_me_count'], $admin['folders_count'], $admin['rotation_due_count'], $admin['pending_apps_count'], $admin['is_admin'], $admin['ca_health'], $admin['honey_alert_count']]);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $admin['last_updated']);
		$this->assertSame(
			[
				['secrets', 'user', 'alice', SecretMapper::STATE_LIVE],
				['shares', 'alice'],
				['warning', 'DashboardSummaryService::fetchSummary() failed to compute shared_with_me_count: shares down'],
				['folders', 'user', 'alice'],
				['flags', 'alice'],
				['apps'],
				['honey'],
				['warning', 'DashboardSummaryService::fetchSummary() failed to compute honey_alert_count: honey down'],
			],
			$calls
		);

		$member = $service->fetchSummary(userId: 'bob', isAdmin: false);
		$this->assertSame(['total_secrets', 'shared_with_me_count', 'folders_count', 'rotation_due_count', 'pending_apps_count', 'is_admin', 'last_updated'], array_keys($member));
		$this->assertNull($member['pending_apps_count']);
	}//end testTheSummaryKeysAndCountersComeInContractOrder()
}//end class
