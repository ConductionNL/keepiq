<?php

/**
 * Unit tests for the computed sections, metrics cache and audit beacons of
 * ComplianceReportService.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
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

namespace OCA\Keepiq\Tests\Unit\Service;

use OCA\Keepiq\Db\ComplianceReport;
use OCA\Keepiq\Db\ComplianceReportMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Service\ComplianceReportService;
use OCP\App\IAppManager;
use OCP\DB\IResult;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Section arithmetic, cache behaviour and audit beacons.
 */
class ComplianceReportServiceSectionsTest extends TestCase {

	/** @var ComplianceReportMapper&MockObject */
	private ComplianceReportMapper $mapper;

	/** @var IAppConfig&MockObject */
	private IAppConfig $appConfig;

	/**
	 * The in-memory app config string values.
	 *
	 * @var array<string,string>
	 */
	private array $strings = [];

	/**
	 * Row sets per SQL prefix, for fetch().
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	private array $rows = [];

	/**
	 * Scalar per exact SQL, for fetchOne(); a Throwable is thrown.
	 *
	 * @var array<string,mixed>
	 */
	private array $scalars = [];

	/**
	 * Every audit event dispatched.
	 *
	 * @var array<int,AuditEvent>
	 */
	private array $events = [];

	/**
	 * Fresh doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(ComplianceReportMapper::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default = 0): int => $default
		);
		$this->appConfig->method('getValueBool')->willReturnCallback(
			static fn (string $app, string $key, bool $default = false): bool => $default
		);
		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->strings[$key] ?? $default)
		);
		$this->appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->strings[$key] = $value;
				return true;
			}
		);
	}//end setUp()

	/**
	 * The service under test, over a database double answering from the fixtures.
	 *
	 * @param IAppManager|null $appManager The app manager (default: version 1.2.3)
	 *
	 * @return ComplianceReportService
	 */
	private function service(?IAppManager $appManager = null): ComplianceReportService {
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturnCallback(
			function (string $sql): IResult {
				foreach ($this->rows as $prefix => $rows) {
					if (str_starts_with($sql, $prefix) === true) {
						return $this->queryResult(rows: $rows, scalar: null);
					}
				}

				$scalar = $this->scalars[$sql] ?? 0;
				if ($scalar instanceof \Throwable) {
					throw $scalar;
				}

				return $this->queryResult(rows: [], scalar: $scalar);
			}
		);

		if ($appManager === null) {
			$appManager = $this->createMock(IAppManager::class);
			$appManager->method('getAppVersion')->willReturn('1.2.3');
		}

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (object $event): void {
				$this->events[] = $event;
			}
		);

		return new ComplianceReportService(
			mapper: $this->mapper,
			db: $db,
			appConfig: $this->appConfig,
			appManager: $appManager,
			eventDispatcher: $dispatcher,
		);
	}//end service()

	/**
	 * A result double yielding the rows, then false, and the scalar.
	 *
	 * @param array<int,array<string,mixed>> $rows The rows
	 * @param mixed $scalar The fetchOne value
	 *
	 * @return IResult
	 */
	private function queryResult(array $rows, mixed $scalar): IResult {
		$result = $this->createMock(IResult::class);
		$rows[] = false;
		$result->method('fetch')->willReturnOnConsecutiveCalls(...$rows);
		$result->method('fetchOne')->willReturn($scalar);

		return $result;
	}//end queryResult()

	/**
	 * Secrets per owner report the total, owner count, minimum, median and maximum.
	 *
	 * @return void
	 */
	public function testSecretsPerUserSummarisesTheOwnerCounts(): void {
		$this->rows['SELECT COUNT(*) AS c FROM *PREFIX*keepiq_secrets'] = [['c' => '7'], ['c' => '1'], ['c' => '3'], ['c' => '4']];

		$section = $this->service()->aggregate()['secretsPerUser'];

		$this->assertSame(
			['totalSecrets' => 15, 'ownersWithSecrets' => 4, 'minPerOwner' => 1, 'medianPerOwner' => 4, 'maxPerOwner' => 7],
			$section
		);
	}//end testSecretsPerUserSummarisesTheOwnerCounts()

	/**
	 * With no secrets every per-owner figure is zero.
	 *
	 * @return void
	 */
	public function testSecretsPerUserIsAllZeroWithoutSecrets(): void {
		$section = $this->service()->aggregate()['secretsPerUser'];

		$this->assertSame(
			['totalSecrets' => 0, 'ownersWithSecrets' => 0, 'minPerOwner' => 0, 'medianPerOwner' => 0, 'maxPerOwner' => 0],
			$section
		);
	}//end testSecretsPerUserIsAllZeroWithoutSecrets()

	/**
	 * The rotation posture groups open flags by reason, bands ciphertext age
	 * and carries the counted secrets.
	 *
	 * @return void
	 */
	public function testRotationPostureCountsFlagsAndAgeBands(): void {
		$day = 86400;
		$this->rows['SELECT reason, COUNT(*) AS c'] = [['reason' => 'expired', 'c' => '2'], ['reason' => 'compromised', 'c' => '1']];
		$this->rows['SELECT COALESCE(key_updated_at, created_at)'] = [
			['aged_at' => date('Y-m-d H:i:s', (time() - (10 * $day)))],
			['aged_at' => date('Y-m-d H:i:s', (time() - (200 * $day)))],
			['aged_at' => date('Y-m-d H:i:s', (time() - (800 * $day)))],
			['aged_at' => date('Y-m-d H:i:s', (time() - (30 * $day)))],
		];
		$this->scalars['SELECT COUNT(*) FROM *PREFIX*keepiq_expiry_policies'] = '3';
		$this->scalars['SELECT COUNT(*) FROM *PREFIX*keepiq_secrets WHERE expires_at IS NOT NULL'] = '9';
		$this->scalars['SELECT COUNT(*) FROM *PREFIX*keepiq_secrets WHERE expires_at IS NOT NULL AND expires_at < CURRENT_TIMESTAMP'] = '4';
		$this->scalars['SELECT COUNT(*) FROM *PREFIX*keepiq_secrets WHERE possibly_compromised_at IS NOT NULL'] = '5';

		$this->assertSame(
			[
				'available' => true,
				'expiryPolicies' => 3,
				'secretsWithExpiry' => 9,
				'overdueSecrets' => 4,
				'openFlagsByReason' => ['expired' => 2, 'compromised' => 1],
				'ciphertextAgeBands' => ['under90Days' => 2, 'under1Year' => 1, 'over1Year' => 1],
				'possiblyCompromised' => 5,
			],
			$this->service()->aggregate()['rotationPosture']
		);
	}//end testRotationPostureCountsFlagsAndAgeBands()

	/**
	 * A distinct count over a table that does not exist reads as zero; the
	 * other adoption figures are still counted.
	 *
	 * @return void
	 */
	public function testAMissingOptionalTableCountsAsZero(): void {
		$this->scalars["SELECT COUNT(DISTINCT owner_id) FROM *PREFIX*keepiq_enc_suites WHERE status = 'active' AND owner_type = 'user'"] = '6';
		$this->scalars["SELECT COUNT(DISTINCT owner_id) FROM *PREFIX*keepiq_secrets WHERE owner_type = 'user'"] = '5';
		$this->scalars['SELECT COUNT(DISTINCT grantor_user_id) FROM *PREFIX*keepiq_emergency_contacts'] = new RuntimeException('no such table');

		$this->assertSame(
			['usersWithActiveSuite' => 6, 'usersWithSecrets' => 5, 'usersWithEmergencyContact' => 0],
			$this->service()->aggregate()['adoption']
		);
	}//end testAMissingOptionalTableCountsAsZero()

	/**
	 * The audit section reports the configured retention, the entry count,
	 * the first entry's time and append-only.
	 *
	 * @return void
	 */
	public function testAuditIntegrityCarriesCountAndFirstEntry(): void {
		$this->scalars['SELECT COUNT(*) FROM *PREFIX*keepiq_audit_log'] = '120';
		$this->scalars['SELECT MIN(occurred_at) FROM *PREFIX*keepiq_audit_log'] = '2026-01-02 03:04:05';

		$this->assertSame(
			['retentionDays' => 365, 'totalEntries' => 120, 'firstEntryAt' => '2026-01-02 03:04:05', 'appendOnly' => true],
			$this->service()->aggregate()['auditIntegrity']
		);
	}//end testAuditIntegrityCarriesCountAndFirstEntry()

	/**
	 * A cold metrics cache is computed, stored with its time, and returned.
	 *
	 * @return void
	 */
	public function testACachedMetricsMissComputesAndStoresThem(): void {
		$this->scalars['SELECT COUNT(*) FROM *PREFIX*keepiq_audit_log'] = '8';

		$cached = $this->service()->cachedMetrics();

		$this->assertSame(8, $cached['metrics']['auditIntegrity']['totalEntries']);
		$this->assertSame($this->strings['compliance_metrics_computed_at'], $cached['computedAt']);
		$this->assertNotFalse(strtotime($cached['computedAt']));
		$this->assertSame($cached['metrics'], json_decode($this->strings['compliance_metrics_cache'], true));
	}//end testACachedMetricsMissComputesAndStoresThem()

	/**
	 * A warm cache is returned as stored, without recomputing; an unreadable
	 * one reads as no metrics.
	 *
	 * @return void
	 */
	public function testAWarmCacheIsReturnedAsStored(): void {
		$this->strings['compliance_metrics_cache'] = '{"adoption":{"usersWithSecrets":4}}';
		$this->strings['compliance_metrics_computed_at'] = '2026-11-01T00:00:00+00:00';
		$this->appConfig->expects($this->never())->method('setValueString');

		$this->assertSame(
			['computedAt' => '2026-11-01T00:00:00+00:00', 'metrics' => ['adoption' => ['usersWithSecrets' => 4]]],
			$this->service()->cachedMetrics()
		);

		$this->strings['compliance_metrics_cache'] = '"not an object"';
		$this->assertSame([], $this->service()->cachedMetrics()['metrics']);
	}//end testAWarmCacheIsReturnedAsStored()

	/**
	 * Listing and reading reports go straight to the mapper.
	 *
	 * @return void
	 */
	public function testListAndGetReadThroughTheMapper(): void {
		$report = new ComplianceReport();
		$report->setId('report-1');
		$this->mapper->expects($this->once())->method('findAll')->willReturn([$report]);
		$this->mapper->expects($this->once())->method('findById')->with('report-1')->willReturn($report);

		$service = $this->service();
		$this->assertSame([$report], $service->listReports());
		$this->assertSame($report, $service->getReport(id: 'report-1'));
	}//end testListAndGetReadThroughTheMapper()

	/**
	 * An export is audited with the report id and format.
	 *
	 * @return void
	 */
	public function testAnExportIsAuditedWithItsFormat(): void {
		$this->service()->recordExport(adminUid: 'admin', reportId: 'report-1', format: 'csv');

		$this->assertCount(1, $this->events);
		$this->assertSame(AuditEventTypes::COMPLIANCE_REPORT_EXPORTED, $this->events[0]->getEventType());
		$this->assertSame('admin', $this->events[0]->getActorId());
		$this->assertSame('compliance_report', $this->events[0]->getObjectType());
		$this->assertSame('report-1', $this->events[0]->getObjectId());
		$this->assertSame(['reportId' => 'report-1', 'format' => 'csv'], $this->events[0]->getMetadata());
	}//end testAnExportIsAuditedWithItsFormat()

	/**
	 * Generating a report audits it without a format, and an app version the
	 * app manager cannot give is recorded as unknown.
	 *
	 * @return void
	 */
	public function testGenerateAuditsAndRecordsAnUnknownVersion(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willThrowException(new RuntimeException('not installed'));
		$this->mapper->method('insert')->willReturnArgument(0);

		$report = $this->service(appManager: $appManager)->generate(adminUid: 'admin');

		$this->assertSame('unknown', $report->getAppVersion());
		$this->assertSame('unknown', $report->getConfigSnapshotArray()['appVersion']);
		$this->assertCount(1, $this->events);
		$this->assertSame(AuditEventTypes::COMPLIANCE_REPORT_GENERATED, $this->events[0]->getEventType());
		$this->assertSame(['reportId' => $report->getId()], $this->events[0]->getMetadata());
	}//end testGenerateAuditsAndRecordsAnUnknownVersion()
}//end class
