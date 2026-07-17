<?php

/**
 * Unit tests for DashboardService.
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

use InvalidArgumentException;
use OCA\Doriath\Db\ApplicationMapper;
use OCA\Doriath\Db\DashboardSetting;
use OCA\Doriath\Db\DashboardSettingMapper;
use OCA\Doriath\Db\FolderMapper;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\ShareTargetMapper;
use OCA\Doriath\Service\DashboardService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for DashboardService.
 */
class DashboardServiceTest extends TestCase
{

    /**
     * Service under test.
     *
     * @var DashboardService
     */
    private DashboardService $service;

    /**
     * Mock mapper.
     *
     * @var DashboardSettingMapper
     */
    private DashboardSettingMapper $mapper;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->mapper  = $this->createMock(originalClassName: DashboardSettingMapper::class);
        $logger        = $this->createMock(originalClassName: LoggerInterface::class);
        $this->service = new DashboardService(mapper: $this->mapper, logger: $logger);
    }//end setUp()

    /**
     * Test set inserts a fresh row when no existing setting matches.
     *
     * @return void
     */
    public function testSetInsertsWhenAbsent(): void
    {
        $this->mapper->expects($this->once())
            ->method('findByUserAndKey')
            ->with('alice', 'layout')
            ->willThrowException(new DoesNotExistException('absent'));

        $captured = null;
        $this->mapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(
                static function (DashboardSetting $entity) use (&$captured) {
                    $captured = $entity;
                    return $entity;
                }
            );

        $result = $this->service->set('alice', 'layout', ['grid' => 4]);

        $this->assertSame($captured, $result);
        $this->assertSame('alice', $result->getUserId());
        $this->assertSame('layout', $result->getSettingKey());
        $this->assertSame(['grid' => 4], $result->getDecodedValue());
        $this->assertNotNull($result->getCreatedAt());
        $this->assertNotNull($result->getUpdatedAt());
    }//end testSetInsertsWhenAbsent()

    /**
     * Test set updates an existing row when the user already has the key.
     *
     * @return void
     */
    public function testSetUpdatesWhenPresent(): void
    {
        $existing = new DashboardSetting();
        $existing->setId('dsh-1');
        $existing->setUserId('alice');
        $existing->setSettingKey('layout');
        $existing->setSettingValue('"old"');

        $this->mapper->expects($this->once())
            ->method('findByUserAndKey')
            ->willReturn($existing);

        $this->mapper->expects($this->never())->method('insert');
        $this->mapper->expects($this->once())
            ->method('update')
            ->willReturnArgument(0);

        $result = $this->service->set('alice', 'layout', 'new');

        $this->assertSame('alice', $result->getUserId());
        $this->assertSame('new', $result->getDecodedValue());
        $this->assertNotNull($result->getUpdatedAt());
    }//end testSetUpdatesWhenPresent()

    /**
     * Test get returns null when no row exists for the user+key combo.
     *
     * @return void
     */
    public function testGetReturnsNullWhenAbsent(): void
    {
        $this->mapper->expects($this->once())
            ->method('findByUserAndKey')
            ->willThrowException(new DoesNotExistException('absent'));

        $this->assertNull($this->service->get('alice', 'default_view'));
    }//end testGetReturnsNullWhenAbsent()

    /**
     * Test set rejects an unknown setting key.
     *
     * @return void
     */
    public function testSetRejectsUnknownKey(): void
    {
        $this->mapper->expects($this->never())->method('findByUserAndKey');
        $this->mapper->expects($this->never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown dashboard setting key');

        $this->service->set('alice', 'totally_made_up_key', 'x');
    }//end testSetRejectsUnknownKey()

    /**
     * Test listForUser projects the user's rows into a flat key=>value map.
     *
     * @return void
     */
    public function testListForUserProjectsRowsToMap(): void
    {
        $a = new DashboardSetting();
        $a->setSettingKey('layout');
        $a->setSettingValue('"grid"');

        $b = new DashboardSetting();
        $b->setSettingKey('default_view');
        $b->setSettingValue('"compact"');

        $this->mapper->expects($this->once())
            ->method('findByUser')
            ->with('alice')
            ->willReturn([$a, $b]);

        $this->assertSame(
            [
                'layout'       => 'grid',
                'default_view' => 'compact',
            ],
            $this->service->listForUser('alice')
        );
    }//end testListForUserProjectsRowsToMap()

    /**
     * fetchSummary aggregates counts from the wired mappers for an admin.
     *
     * @return void
     */
    public function testFetchSummaryAggregatesForAdmin(): void
    {
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

        $logger  = $this->createMock(originalClassName: LoggerInterface::class);
        $mapper  = $this->createMock(originalClassName: DashboardSettingMapper::class);
        $service = new DashboardService(
            mapper: $mapper,
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
    public function testFetchSummaryOmitsPendingAppsForNonAdmin(): void
    {
        $secretMapper = $this->createMock(originalClassName: SecretMapper::class);
        $secretMapper->method('countByOwner')->willReturn(1);
        $folderMapper = $this->createMock(originalClassName: FolderMapper::class);
        $folderMapper->method('findByOwner')->willReturn([]);
        $shareMapper = $this->createMock(originalClassName: ShareTargetMapper::class);
        $shareMapper->method('findByTargetUser')->willReturn([]);

        $appMapper = $this->createMock(originalClassName: ApplicationMapper::class);
        $appMapper->expects($this->never())->method('countPending');

        $logger  = $this->createMock(originalClassName: LoggerInterface::class);
        $mapper  = $this->createMock(originalClassName: DashboardSettingMapper::class);
        $service = new DashboardService(
            mapper: $mapper,
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
    public function testFetchSummaryDegradesOnMapperFailure(): void
    {
        $secretMapper = $this->createMock(originalClassName: SecretMapper::class);
        $secretMapper->method('countByOwner')->willThrowException(new RuntimeException('db down'));
        $folderMapper = $this->createMock(originalClassName: FolderMapper::class);
        $folderMapper->method('findByOwner')->willReturn([]);
        $shareMapper = $this->createMock(originalClassName: ShareTargetMapper::class);
        $shareMapper->method('findByTargetUser')->willReturn([]);

        $logger = $this->createMock(originalClassName: LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('warning');
        $mapper  = $this->createMock(originalClassName: DashboardSettingMapper::class);
        $service = new DashboardService(
            mapper: $mapper,
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
     * fetchSummary tolerates missing aggregator dependencies (legacy
     * 2-arg constructor) by reporting zeroes.
     *
     * @return void
     */
    public function testFetchSummaryWithoutAggregatorDepsReturnsZeroes(): void
    {
        $summary = $this->service->fetchSummary(userId: 'alice', isAdmin: false);

        $this->assertSame(0, $summary['total_secrets']);
        $this->assertSame(0, $summary['shared_with_me_count']);
        $this->assertSame(0, $summary['folders_count']);
        $this->assertNull($summary['pending_apps_count']);
    }//end testFetchSummaryWithoutAggregatorDepsReturnsZeroes()
}//end class
