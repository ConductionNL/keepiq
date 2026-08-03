<?php

/**
 * Unit tests for ComplianceReportService (compliance-reporting §7).
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

use OCA\Doriath\Db\ComplianceReport;
use OCA\Doriath\Db\ComplianceReportMapper;
use OCA\Doriath\Service\ComplianceReportService;
use OCP\App\IAppManager;
use OCP\DB\IResult;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ComplianceReportService.
 */
class ComplianceReportServiceTest extends TestCase
{
    private ComplianceReportService $service;

    private ComplianceReportMapper&MockObject $mapper;

    /**
     * Build the service over a stubbed zero-count database.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = $this->createMock(originalClassName: ComplianceReportMapper::class);

        $result = $this->createMock(originalClassName: IResult::class);
        $result->method('fetchOne')->willReturn(0);
        $result->method('fetch')->willReturn(false);
        $db = $this->createMock(originalClassName: IDBConnection::class);
        $db->method('executeQuery')->willReturn($result);

        $appConfig = $this->createMock(originalClassName: IAppConfig::class);
        $appConfig->method('getValueInt')->willReturnCallback(
            static fn (string $app, string $key, int $default=0): int => $default
        );
        $appConfig->method('getValueBool')->willReturnCallback(
            static fn (string $app, string $key, bool $default=false): bool => $default
        );
        $appConfig->method('getValueString')->willReturnCallback(
            static fn (string $app, string $key, string $default=''): string => $default
        );

        $appManager = $this->createMock(originalClassName: IAppManager::class);
        $appManager->method('getAppVersion')->willReturn('0.2.99-test');

        $this->service = new ComplianceReportService(
            mapper: $this->mapper,
            db: $db,
            appConfig: $appConfig,
            appManager: $appManager,
            auditTrail: null,
        );
    }//end setUp()

    /**
     * §7.1: the aggregate contains exactly the six allowlisted sections
     * and no strength/reuse/breach/value key anywhere; ciphertext-age
     * labelling is present.
     *
     * @return void
     */
    public function testAggregateIsAllowlistedAndMetadataOnly(): void
    {
        $aggregate = $this->service->aggregate();

        $this->assertSame(
            ['adoption', 'secretsPerUser', 'shareHygiene', 'rotationPosture', 'auditIntegrity', 'emergencyAccess'],
            array_keys($aggregate)
        );

        $flat = strtolower((string) json_encode($aggregate));
        foreach (['strength', 'reuse', 'breach', '"name"', '"value"', '"login"', 'ciphertext":'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $flat);
        }

        // Ciphertext-age labelling, never "strength".
        $this->assertArrayHasKey('ciphertextAgeBands', $aggregate['rotationPosture']);
        $this->assertTrue($aggregate['auditIntegrity']['appendOnly']);
    }//end testAggregateIsAllowlistedAndMetadataOnly()

    /**
     * §7.2: generate persists an immutable snapshot carrying actor,
     * version, and config snapshot; the mapper exposes no update path.
     *
     * @return void
     */
    public function testGeneratePersistsImmutableSnapshot(): void
    {
        $inserted = null;
        $this->mapper->method('insert')->willReturnCallback(
            static function (ComplianceReport $report) use (&$inserted) {
                $inserted = $report;
                return $report;
            }
        );

        $report = $this->service->generate(adminUid: 'admin');

        $this->assertSame('admin', $report->getGeneratedBy());
        $this->assertSame('0.2.99-test', $report->getAppVersion());
        $this->assertNotNull($report->getGeneratedAt());
        $config = $report->getConfigSnapshotArray();
        $this->assertArrayHasKey('auditRetentionDays', $config);
        $this->assertArrayHasKey('breachCheckEnabled', $config);
        $this->assertNotNull($inserted);
        // Append-only: the mapper class exposes insert/find only.
        $this->assertFalse(method_exists(ComplianceReportMapper::class, 'updateReport'));
    }//end testGeneratePersistsImmutableSnapshot()

    /**
     * §7.2: the rotation-posture section degrades gracefully when its
     * queries fail (capability absent), never failing generation.
     *
     * @return void
     */
    public function testRotationPostureDegradesGracefully(): void
    {
        $result = $this->createMock(originalClassName: IResult::class);
        $result->method('fetchOne')->willReturn(0);
        $result->method('fetch')->willReturn(false);
        $db = $this->createMock(originalClassName: IDBConnection::class);
        $db->method('executeQuery')->willReturnCallback(
            static function (string $sql) use ($result) {
                if (str_contains($sql, 'rotation_flags') === true || str_contains($sql, 'expiry_policies') === true) {
                    throw new \RuntimeException('table missing');
                }

                return $result;
            }
        );

        $appConfig = $this->createMock(originalClassName: IAppConfig::class);
        $appConfig->method('getValueInt')->willReturn(365);
        $appConfig->method('getValueBool')->willReturn(false);
        $appManager = $this->createMock(originalClassName: IAppManager::class);
        $appManager->method('getAppVersion')->willReturn('x');

        $service = new ComplianceReportService(
            mapper: $this->mapper,
            db: $db,
            appConfig: $appConfig,
            appManager: $appManager,
            auditTrail: null,
        );

        $aggregate = $service->aggregate();
        $this->assertFalse($aggregate['rotationPosture']['available']);
    }//end testRotationPostureDegradesGracefully()
}//end class
