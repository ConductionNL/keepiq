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
use OCA\Doriath\Db\LinkShare;
use OCA\Doriath\Db\SuiteMigration;
use OCA\Doriath\Db\SuiteMigrationMapper;
use OCA\Doriath\Service\CertificateAuthorityService;
use OCA\Doriath\Service\DashboardService;
use OCA\Doriath\Db\LinkShareMapper;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the dashboard aggregation service.
 */
class DashboardServiceTest extends TestCase
{

    /**
     * Build a suite entity with the given status.
     *
     * @param string $id     The suite id.
     * @param string $status The suite status.
     *
     * @return EncryptionSuite
     */
    private function suite(string $id, string $status): EncryptionSuite
    {
        $suite = new EncryptionSuite();
        $suite->setId($id);
        $suite->setOwnerType('user');
        $suite->setOwnerId('alice');
        $suite->setStatus($status);
        return $suite;
    }//end suite()

    /**
     * fetchSummary counts active and compromised suites and shared links for a
     * normal (non-admin) user, leaving the admin-only fields null.
     *
     * @return void
     */
    public function testFetchSummaryForNormalUser(): void
    {
        $suiteMapper = $this->createMock(EncryptionSuiteMapper::class);
        $suiteMapper->method('findByOwner')->with('user', 'alice')->willReturn([
            $this->suite('s1', 'active'),
            $this->suite('s2', 'compromised'),
            $this->suite('s3', 'active'),
        ]);

        $migrationMapper = $this->createMock(SuiteMigrationMapper::class);
        $migrationMapper->method('findBySuiteId')->willReturn([]);

        $linkShareMapper = $this->createMock(LinkShareMapper::class);
        $linkShareMapper->method('findByCreatedBy')->with('alice')->willReturn([
            new LinkShare(),
            new LinkShare(),
        ]);

        $caService = $this->createMock(CertificateAuthorityService::class);
        $caService->expects($this->never())->method('getStatus');

        $service = new DashboardService($suiteMapper, $migrationMapper, $linkShareMapper, $caService);

        $summary = $service->fetchSummary('alice', false);

        self::assertSame(2, $summary['total_secrets']);
        self::assertSame(1, $summary['compromised_count']);
        self::assertSame(2, $summary['shared_secrets']);
        self::assertSame(0, $summary['folder_count']);
        self::assertNull($summary['migration_status']);
        self::assertNull($summary['pending_apps_count']);
        self::assertNull($summary['ca_status']);
    }//end testFetchSummaryForNormalUser()

    /**
     * fetchSummary surfaces an in-progress migration banner state.
     *
     * @return void
     */
    public function testFetchSummaryReportsInProgressMigration(): void
    {
        $suiteMapper = $this->createMock(EncryptionSuiteMapper::class);
        $suiteMapper->method('findByOwner')->willReturn([$this->suite('s1', 'active')]);

        $migration = new SuiteMigration();
        $migration->setId('m1');
        $migration->setStatus('in_progress');

        $migrationMapper = $this->createMock(SuiteMigrationMapper::class);
        $migrationMapper->method('findBySuiteId')->with('s1')->willReturn([$migration]);

        $linkShareMapper = $this->createMock(LinkShareMapper::class);
        $linkShareMapper->method('findByCreatedBy')->willReturn([]);

        $caService = $this->createMock(CertificateAuthorityService::class);

        $service = new DashboardService($suiteMapper, $migrationMapper, $linkShareMapper, $caService);

        $summary = $service->fetchSummary('alice', false);

        self::assertIsArray($summary['migration_status']);
        self::assertSame('in_progress', $summary['migration_status']['state']);
        self::assertSame('m1', $summary['migration_status']['migration_id']);
    }//end testFetchSummaryReportsInProgressMigration()

    /**
     * fetchSummary populates the admin-only CA status and pending-apps counter
     * when the caller is an administrator.
     *
     * @return void
     */
    public function testFetchSummaryForAdminIncludesCaStatus(): void
    {
        $suiteMapper = $this->createMock(EncryptionSuiteMapper::class);
        $suiteMapper->method('findByOwner')->willReturn([]);

        $migrationMapper = $this->createMock(SuiteMigrationMapper::class);

        $linkShareMapper = $this->createMock(LinkShareMapper::class);
        $linkShareMapper->method('findByCreatedBy')->willReturn([]);

        $caStatus = ['status' => 'healthy', 'root' => ['x' => 1], 'intermediate' => ['y' => 2]];
        $caService = $this->createMock(CertificateAuthorityService::class);
        $caService->expects($this->once())->method('getStatus')->willReturn($caStatus);

        $service = new DashboardService($suiteMapper, $migrationMapper, $linkShareMapper, $caService);

        $summary = $service->fetchSummary('admin', true);

        self::assertSame($caStatus, $summary['ca_status']);
        self::assertSame(0, $summary['pending_apps_count']);
    }//end testFetchSummaryForAdminIncludesCaStatus()
}//end class
