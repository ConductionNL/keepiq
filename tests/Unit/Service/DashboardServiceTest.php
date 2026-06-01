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

use OCA\Doriath\Db\EncryptionSuite;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\SuiteMigration;
use OCA\Doriath\Db\SuiteMigrationMapper;
use OCA\Doriath\Service\CertificateAuthorityService;
use OCA\Doriath\Service\DashboardService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DashboardService.
 */
class DashboardServiceTest extends TestCase
{

    /**
     * The service under test.
     *
     * @var DashboardService
     */
    private DashboardService $service;

    /**
     * Mock encryption-suite mapper.
     *
     * @var EncryptionSuiteMapper
     */
    private EncryptionSuiteMapper $suiteMapper;

    /**
     * Mock suite-migration mapper.
     *
     * @var SuiteMigrationMapper
     */
    private SuiteMigrationMapper $migrationMapper;

    /**
     * Mock certificate-authority service.
     *
     * @var CertificateAuthorityService
     */
    private CertificateAuthorityService $caService;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->suiteMapper     = $this->createMock(EncryptionSuiteMapper::class);
        $this->migrationMapper = $this->createMock(SuiteMigrationMapper::class);
        $this->caService       = $this->createMock(CertificateAuthorityService::class);

        $this->service = new DashboardService(
            $this->suiteMapper,
            $this->migrationMapper,
            $this->caService,
        );

    }//end setUp()

    /**
     * A regular user gets null for the admin-only fields.
     *
     * @return void
     */
    public function testRegularUserHasNullAdminFields(): void
    {
        $this->suiteMapper->method('findByOwner')->willReturn([]);
        $this->migrationMapper->method('findInProgressBySuiteIds')->willReturn(null);

        $summary = $this->service->fetchSummary('alice', false);

        self::assertNull($summary['pending_apps_count']);
        self::assertNull($summary['ca_status']);
        self::assertArrayHasKey('total_secrets', $summary);
        self::assertArrayHasKey('shared_secrets', $summary);
        self::assertArrayHasKey('folder_count', $summary);
        self::assertArrayHasKey('compromised_count', $summary);
        self::assertNull($summary['migration_status']);

    }//end testRegularUserHasNullAdminFields()

    /**
     * An admin user gets the CA status populated from the CA service.
     *
     * @return void
     */
    public function testAdminUserGetsCaStatus(): void
    {
        $this->suiteMapper->method('findByOwner')->willReturn([]);
        $this->migrationMapper->method('findInProgressBySuiteIds')->willReturn(null);
        $this->caService->method('getStatus')->willReturn(['status' => 'healthy']);

        $summary = $this->service->fetchSummary('admin', true);

        self::assertSame(['status' => 'healthy'], $summary['ca_status']);

    }//end testAdminUserGetsCaStatus()

    /**
     * An in-progress migration for the user's suite surfaces in the summary.
     *
     * @return void
     */
    public function testMigrationStatusSurfaced(): void
    {
        $suite = new EncryptionSuite();
        $suite->setId('suite-1');

        $migration = new SuiteMigration();
        $migration->setId('m-1');
        $migration->setOldSuiteId('suite-1');
        $migration->setNewSuiteId('suite-2');
        $migration->setStatus('in_progress');

        $this->suiteMapper->method('findByOwner')->willReturn([$suite]);
        $this->migrationMapper->expects($this->once())
            ->method('findInProgressBySuiteIds')
            ->with(['suite-1'])
            ->willReturn($migration);

        $summary = $this->service->fetchSummary('alice', false);

        self::assertIsArray($summary['migration_status']);
        self::assertSame('in_progress', $summary['migration_status']['status']);

    }//end testMigrationStatusSurfaced()
}//end class
