<?php

/**
 * Keepiq RestoreService Test
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

use InvalidArgumentException;
use OCA\Keepiq\Backup\ArchiveCipher;
use OCA\Keepiq\Backup\ArchiveReader;
use OCA\Keepiq\Backup\ArchiveStore;
use OCA\Keepiq\Backup\RestoreService;
use OCA\Keepiq\Backup\SchemaFingerprint;
use OCA\Keepiq\Backup\TableStore;
use OCA\Keepiq\Service\AuditService;
use OCA\Keepiq\Service\ShareQuarantineScanner;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Security\ICrypto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

/**
 * Key file and maintenance refusals, and the warnings shown before a restore.
 */
#[CoversClass(RestoreService::class)]
#[UsesClass(ArchiveCipher::class)]
#[UsesClass(ArchiveReader::class)]
class RestoreServiceTest extends TestCase {

	/**
	 * Scratch directory of the test.
	 *
	 * @var string
	 */
	private string $dir;

	/**
	 * @var TableStore&MockObject
	 */
	private TableStore $tables;

	/**
	 * @var ArchiveStore&MockObject
	 */
	private ArchiveStore $store;

	/**
	 * @var IConfig&MockObject
	 */
	private IConfig $config;

	/**
	 * @var IUserManager&MockObject
	 */
	private IUserManager $userManager;

	/**
	 * @var ICrypto&MockObject
	 */
	private ICrypto $crypto;

	/**
	 * @var AuditService&MockObject
	 */
	private AuditService $audit;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/keepiq-restoresvc-test-' . bin2hex(random_bytes(4));
		mkdir($this->dir, 0700, true);
		$this->tables = $this->createMock(TableStore::class);
		$this->store = $this->createMock(ArchiveStore::class);
		$this->config = $this->createMock(IConfig::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->crypto = $this->createMock(ICrypto::class);
		$this->audit = $this->createMock(AuditService::class);
	}//end setUp()

	/**
	 * Remove the scratch directory.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach (glob($this->dir . '/*') ?: [] as $file) {
			unlink($file);
		}

		rmdir($this->dir);
	}//end tearDown()

	/**
	 * The service over the mocked collaborators and the real reader and cipher.
	 *
	 * @return RestoreService
	 */
	private function service(): RestoreService {
		return new RestoreService(
			reader: new ArchiveReader(),
			cipher: new ArchiveCipher(),
			tables: $this->tables,
			fingerprint: $this->createMock(SchemaFingerprint::class),
			store: $this->store,
			config: $this->config,
			userManager: $this->userManager,
			crypto: $this->crypto,
			audit: $this->audit,
			quarantineScan: $this->createMock(ShareQuarantineScanner::class),
		);
	}//end service()

	/**
	 * A zip holding the given table lines.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $rows Rows per table
	 *
	 * @return string The zip path
	 */
	private function zip(array $rows): string {
		$path = $this->dir . '/archive.zip';
		$zip = new ZipArchive();
		$zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
		foreach ($rows as $table => $lines) {
			$zip->addFromString('tables/' . $table . '.jsonl', implode("\n", array_map('json_encode', $lines)) . "\n");
		}

		$zip->close();

		return $path;
	}//end zip()

	/**
	 * An encrypted archive with a key file that cannot be read is refused
	 * before anything is decrypted.
	 *
	 * @return void
	 */
	public function testAnUnreadableKeyFileIsRefused(): void {
		$archive = $this->dir . '/archive.zip.enc';
		file_put_contents($archive, ArchiveCipher::MAGIC . 'rest');
		$this->store->expects($this->never())->method('tempFile');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Cannot read the key file ' . $this->dir . '/missing.pem');
		$this->service()->open(localPath: $archive, keyFile: $this->dir . '/missing.pem');
	}//end testAnUnreadableKeyFileIsRefused()

	/**
	 * An encrypted archive without a key file is refused.
	 *
	 * @return void
	 */
	public function testAnEncryptedArchiveWithoutAKeyFileIsRefused(): void {
		$archive = $this->dir . '/archive.zip.enc';
		file_put_contents($archive, ArchiveCipher::MAGIC . 'rest');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The archive is encrypted. Pass the private key with --key-file.');
		$this->service()->open(localPath: $archive, keyFile: '');
	}//end testAnEncryptedArchiveWithoutAKeyFileIsRefused()

	/**
	 * A restore while maintenance mode is on is refused without touching
	 * the maintenance switch or any table.
	 *
	 * @return void
	 */
	public function testRestoreIsRefusedWhileMaintenanceIsOn(): void {
		$this->config->method('getSystemValue')->with('maintenance', false)->willReturn(true);
		$this->config->expects($this->never())->method('setSystemValue');
		$this->tables->expects($this->never())->method('replaceAll');
		$this->audit->expects($this->never())->method('record');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(RestoreService::MAINTENANCE_ALREADY_ON);
		$this->service()->restore(zip: $this->zip(rows: []), manifest: [], archiveName: 'a.zip');
	}//end testRestoreIsRefusedWhileMaintenanceIsOn()

	/**
	 * A CA row without a private key is skipped; the first one with a key
	 * decides, and when it opens there is no CA warning.
	 *
	 * @return void
	 */
	public function testCaRowsWithoutAKeyAreSkipped(): void {
		$zip = $this->zip(rows: ['ca_certs' => [['id' => 'ca0', 'private_key' => ''], ['id' => 'ca1', 'private_key' => 'BLOB-1'], ['id' => 'ca2', 'private_key' => 'BLOB-2']]]);
		$this->crypto->expects($this->once())->method('decrypt')->with('BLOB-1')->willReturn('pem');

		$warnings = $this->service()->warnings(zip: $zip, manifest: ['createdAt' => '2026-01-01T00:00:00+00:00']);

		$this->assertSame(
			[
				'Every change made after 2026-01-01T00:00:00+00:00 is lost, including key rotations.',
				'Every user unlocks with the master password that was valid when the backup ran.',
			],
			$warnings
		);
	}//end testCaRowsWithoutAKeyAreSkipped()

	/**
	 * Without any CA key to try there is no CA warning, and without a
	 * creation time the first warning says "the backup".
	 *
	 * @return void
	 */
	public function testNoCaKeysMeansNoCaWarning(): void {
		$zip = $this->zip(rows: ['ca_certs' => [['id' => 'ca0']]]);
		$this->crypto->expects($this->never())->method('decrypt');

		$warnings = $this->service()->warnings(zip: $zip, manifest: []);

		$this->assertCount(2, $warnings);
		$this->assertSame('Every change made after the backup is lost, including key rotations.', $warnings[0]);
	}//end testNoCaKeysMeansNoCaWarning()

	/**
	 * A CA key the instance secret cannot open, and user owners Nextcloud
	 * no longer knows, each add a warning; group owners are not checked.
	 *
	 * @return void
	 */
	public function testUnreadableCaKeyAndMissingOwnersAreWarned(): void {
		$zip = $this->zip(
			rows: [
				'ca_certs' => [['id' => 'ca1', 'private_key' => 'BLOB-1']],
				'secrets' => [
					['id' => 's1', 'owner_type' => 'user', 'owner_id' => 'zed'],
					['id' => 's2', 'owner_type' => 'group', 'owner_id' => 'staff'],
					['id' => 's3', 'owner_type' => 'user', 'owner_id' => 'alice'],
				],
				'enc_suites' => [['id' => 'e1', 'owner_type' => 'user', 'owner_id' => 'bob']],
			]
		);
		$this->crypto->method('decrypt')->willThrowException(new RuntimeException('bad secret'));
		$checked = [];
		$this->userManager->method('userExists')->willReturnCallback(
			static function (string $uid) use (&$checked): bool {
				$checked[] = $uid;
				return $uid === 'alice';
			}
		);

		$warnings = $this->service()->warnings(zip: $zip, manifest: []);

		$this->assertCount(4, $warnings);
		$this->assertStringStartsWith('The CA keys in this archive cannot be read', $warnings[2]);
		$this->assertSame('These owners no longer exist in Nextcloud; their rows are restored anyway: bob, zed', $warnings[3]);
		$this->assertSame(['zed', 'alice', 'bob'], $checked);
	}//end testUnreadableCaKeyAndMissingOwnersAreWarned()
}//end class
