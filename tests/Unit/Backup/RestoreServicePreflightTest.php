<?php

/**
 * Keepiq RestoreService preflight Test
 *
 * @category Tests
 * @package  OCA\Keepiq\Tests\Unit\Backup
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

namespace OCA\Keepiq\Tests\Unit\Backup;

use OCA\Keepiq\Backup\ArchiveCipher;
use OCA\Keepiq\Backup\ArchiveReader;
use OCA\Keepiq\Backup\ArchiveStore;
use OCA\Keepiq\Backup\BackupTableRegistry;
use OCA\Keepiq\Backup\RestoreService;
use OCA\Keepiq\Backup\SchemaFingerprint;
use OCA\Keepiq\Backup\TableStore;
use OCA\Keepiq\Service\AuditService;
use OCA\Keepiq\Service\ShareQuarantineScanner;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Security\ICrypto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * What a restore checks before it starts: the refusals, the audit-age
 * comparison and the row counts it shows next to the archive's.
 */
#[CoversClass(RestoreService::class)]
class RestoreServicePreflightTest extends TestCase {

	private const SCHEMA_REFUSAL = 'The archive was written by a different Keepiq schema. Restore it on the same Keepiq version.';

	private const AGE_REFUSAL = 'The archive is older than the newest audit entry. Pass --force to roll back on purpose.';

	/**
	 * @var TableStore&MockObject
	 */
	private TableStore $tables;

	/**
	 * @var IConfig&MockObject
	 */
	private IConfig $config;

	/**
	 * @var SchemaFingerprint&MockObject
	 */
	private SchemaFingerprint $fingerprint;

	/**
	 * Build the service over doubles.
	 *
	 * @param bool        $maintenance The instance's maintenance flag
	 * @param string|null $newestAudit The newest audit entry's timestamp
	 *
	 * @return RestoreService
	 */
	private function service(bool $maintenance = false, ?string $newestAudit = null): RestoreService {
		$this->tables = $this->createMock(TableStore::class);
		$this->tables->method('newestAuditEntry')->willReturn($newestAudit);

		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getSystemValue')->willReturnCallback(
			static fn (string $key, mixed $default = ''): mixed => $key === 'maintenance' ? $maintenance : $default
		);

		$this->fingerprint = $this->createMock(SchemaFingerprint::class);
		$this->fingerprint->method('current')->willReturn('fp-current');

		return new RestoreService(
			reader: $this->createMock(ArchiveReader::class),
			cipher: $this->createMock(ArchiveCipher::class),
			tables: $this->tables,
			fingerprint: $this->fingerprint,
			store: $this->createMock(ArchiveStore::class),
			config: $this->config,
			userManager: $this->createMock(IUserManager::class),
			crypto: $this->createMock(ICrypto::class),
			audit: $this->createMock(AuditService::class),
			quarantineScan: $this->createMock(ShareQuarantineScanner::class),
		);
	}//end service()

	/**
	 * A matching, recent archive on an instance out of maintenance has no refusal.
	 *
	 * @return void
	 */
	public function testAMatchingRecentArchiveIsNotRefused(): void {
		$service = $this->service(newestAudit: '2026-03-01 10:00:00');

		$this->assertSame([], $service->refusals(['schemaFingerprint' => 'fp-current', 'createdAt' => '2026-03-02T10:00:00+00:00'], false));
	}//end testAMatchingRecentArchiveIsNotRefused()

	/**
	 * Every reason is listed, in order: maintenance, schema, age.
	 *
	 * @return void
	 */
	public function testEveryRefusalIsListedInOrder(): void {
		$service = $this->service(maintenance: true, newestAudit: '2026-03-05 10:00:00');

		$this->assertSame(
			[RestoreService::MAINTENANCE_ALREADY_ON, self::SCHEMA_REFUSAL, self::AGE_REFUSAL],
			$service->refusals(['schemaFingerprint' => 'fp-old', 'createdAt' => '2026-03-01T10:00:00+00:00'], false)
		);
	}//end testEveryRefusalIsListedInOrder()

	/**
	 * A missing fingerprint counts as a different schema.
	 *
	 * @return void
	 */
	public function testAMissingFingerprintIsADifferentSchema(): void {
		$this->assertSame([self::SCHEMA_REFUSAL], $this->service()->refusals([], false));
	}//end testAMissingFingerprintIsADifferentSchema()

	/**
	 * --force lifts only the age refusal.
	 *
	 * @return void
	 */
	public function testForceLiftsOnlyTheAgeRefusal(): void {
		$service = $this->service(maintenance: true, newestAudit: '2026-03-05 10:00:00');
		$this->tables->expects($this->never())->method('newestAuditEntry');

		$this->assertSame(
			[RestoreService::MAINTENANCE_ALREADY_ON],
			$service->refusals(['schemaFingerprint' => 'fp-current', 'createdAt' => '2026-03-01T10:00:00+00:00'], true)
		);
	}//end testForceLiftsOnlyTheAgeRefusal()

	/**
	 * The archive is older only when both timestamps are known and it predates the audit entry.
	 *
	 * @return array<string, array{0: string|null, 1: array<string, mixed>, 2: bool}>
	 */
	public static function ages(): array {
		return [
			'older' => ['2026-03-05 10:00:00', ['createdAt' => '2026-03-01T10:00:00+00:00'], true],
			'newer' => ['2026-03-05 10:00:00', ['createdAt' => '2026-03-06T10:00:00+00:00'], false],
			'same moment' => ['2026-03-05 10:00:00', ['createdAt' => '2026-03-05 10:00:00'], false],
			'empty audit log' => [null, ['createdAt' => '2026-03-01T10:00:00+00:00'], false],
			'no createdAt' => ['2026-03-05 10:00:00', [], false],
			'empty createdAt' => ['2026-03-05 10:00:00', ['createdAt' => ''], false],
		];
	}//end ages()

	/**
	 * @param string|null          $newest   Newest audit entry
	 * @param array<string, mixed> $manifest The manifest
	 * @param bool                 $expected Whether the archive is older
	 *
	 * @return void
	 */
	#[DataProvider('ages')]
	public function testTheArchiveIsOlderOnlyWhenItPredatesTheNewestAuditEntry(?string $newest, array $manifest, bool $expected): void {
		$this->assertSame($expected, $this->service(newestAudit: $newest)->isOlderThanNewestAuditEntry($manifest));
	}//end testTheArchiveIsOlderOnlyWhenItPredatesTheNewestAuditEntry()

	/**
	 * Every registered table is compared: the current count beside the archive's row count, zero when absent.
	 *
	 * @return void
	 */
	public function testCompareListsEveryTableWithBothCounts(): void {
		$service = $this->service();
		$this->tables->method('count')->willReturnCallback(static fn (string $table): int => strlen($table));

		$counts = $service->compare(
			[
				'files' => [
					'tables/audit_log.jsonl' => ['rows' => 12],
					'tables/applications.jsonl' => ['rows' => '3'],
					'tables/not_registered.jsonl' => ['rows' => 99],
				],
			]
		);

		$this->assertSame(BackupTableRegistry::TABLES, array_keys($counts));
		$this->assertSame(['current' => strlen('audit_log'), 'archive' => 12], $counts['audit_log']);
		$this->assertSame(['current' => strlen('applications'), 'archive' => 3], $counts['applications']);
		$this->assertSame(['current' => strlen('ca_certs'), 'archive' => 0], $counts['ca_certs']);
	}//end testCompareListsEveryTableWithBothCounts()
}//end class
