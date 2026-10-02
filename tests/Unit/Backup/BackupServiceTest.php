<?php

/**
 * Unit tests for backup settings, the scheduled run and retention
 * (admin-scheduled-vault-backups §2).
 *
 * @category Test
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
use OCA\Keepiq\Backup\ArchiveWriter;
use OCA\Keepiq\Backup\BackupService;
use OCA\Keepiq\Backup\BackupSettings;
use OCA\Keepiq\BackgroundJob\ScheduledVaultBackupJob;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Service\AuditService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Settings bounds, due and not-due runs, retention and audit.
 */
class BackupServiceTest extends TestCase {

	/** @var array<string,mixed> */
	private array $store = [];

	/** @var array<string,string> name => content */
	private array $files = [];

	/** @var array<int,AuditEvent> */
	private array $events = [];

	private int $now = 1_800_000_000;

	private bool $writerFails = false;

	private BackupService $service;

	private BackupSettings $settings;

	/**
	 * Wire the service over in-memory config and storage.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturnCallback(fn ($a, $k, $d = false) => (bool)($this->store[$k] ?? $d));
		$appConfig->method('getValueInt')->willReturnCallback(fn ($a, $k, $d = 0) => (int)($this->store[$k] ?? $d));
		$appConfig->method('getValueString')->willReturnCallback(fn ($a, $k, $d = '') => (string)($this->store[$k] ?? $d));
		foreach (['setValueBool', 'setValueInt', 'setValueString'] as $setter) {
			$appConfig->method($setter)->willReturnCallback(function ($a, $k, $v) {
				$this->store[$k] = $v;
				return true;
			});
		}

		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('getDirectoryListing')->willReturnCallback(function (): array {
			$list = [];
			foreach (array_keys($this->files) as $name) {
				$file = $this->createMock(ISimpleFile::class);
				$file->method('getName')->willReturn($name);
				$file->method('getSize')->willReturn(strlen($this->files[$name]));
				$file->method('getMTime')->willReturn(0);
				$file->method('delete')->willReturnCallback(function () use ($name): void {
					unset($this->files[$name]);
				});
				$list[] = $file;
			}
			return $list;
		});
		$folder->method('newFile')->willReturnCallback(function (string $name) {
			$file = $this->createMock(ISimpleFile::class);
			$file->method('putContent')->willReturnCallback(function ($content) use ($name): void {
				$this->files[$name] = is_resource($content) ? (string)stream_get_contents($content) : (string)$content;
			});
			return $file;
		});
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willReturn($folder);
		$factory = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->willReturn($appData);

		$writer = $this->createMock(ArchiveWriter::class);
		$writer->method('write')->willReturnCallback(function (string $zipPath): array {
			if ($this->writerFails === true) {
				throw new \RuntimeException('disk full');
			}
			file_put_contents($zipPath, 'ZIP-BYTES');
			return [];
		});

		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturnCallback(static fn () => tempnam(sys_get_temp_dir(), 'kqb'));
		$temp->method('getTemporaryFolder')->willReturn(sys_get_temp_dir());
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn () => $this->now);
		$audit = $this->createMock(AuditService::class);
		$audit->method('record')->willReturnCallback(function (AuditEvent $event) {
			$this->events[] = $event;
			return new \OCA\Keepiq\Db\AuditEntry();
		});

		$this->settings = new BackupSettings(appConfig: $appConfig, cipher: new ArchiveCipher());
		$this->service = new BackupService(
			writer: $writer,
			cipher: new ArchiveCipher(),
			settings: $this->settings,
			store: new \OCA\Keepiq\Backup\ArchiveStore(appDataFactory: $factory, config: $this->createMock(IConfig::class), tempManager: $temp),
			appConfig: $appConfig,
			time: $time,
			audit: $audit,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * §2.1: defaults, bounds and key parsing; a bad value writes nothing.
	 *
	 * @return void
	 */
	public function testSettingsBoundsAndKeyParsing(): void {
		$this->assertSame(
			['backup_enabled' => false, 'backup_interval_hours' => 24, 'backup_retention_count' => 7, 'backup_recipient_public_key' => ''],
			$this->settings->read()
		);

		foreach ([['backup_interval_hours' => 0], ['backup_interval_hours' => 9000], ['backup_retention_count' => 0], ['backup_recipient_public_key' => 'not a key'], ['backup_enabled' => 'maybe']] as $bad) {
			try {
				$this->settings->update($bad + ['backup_enabled' => true]);
				$this->fail('Accepted ' . json_encode($bad));
			} catch (InvalidArgumentException) {
				$this->addToAssertionCount(1);
			}
		}

		$this->assertSame([], $this->store);

		$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		$pem = openssl_pkey_get_details($key)['key'];
		$this->settings->update(['backup_enabled' => true, 'backup_interval_hours' => 6, 'backup_recipient_public_key' => $pem]);
		$this->assertSame(6, $this->settings->read()['backup_interval_hours']);
		$this->assertSame(trim($pem), $this->settings->read()['backup_recipient_public_key']);
	}//end testSettingsBoundsAndKeyParsing()

	/**
	 * §2.2: off means never due; on and the interval passed means due; a
	 * "Back up now" request is due at once.
	 *
	 * @return void
	 */
	public function testDueAndNotDue(): void {
		$this->assertFalse($this->service->isDue());

		$this->store['backup_enabled'] = true;
		$this->store['backup_last_run_at'] = $this->now - 3600;
		$this->assertFalse($this->service->isDue());

		$this->store['backup_last_run_at'] = $this->now - (24 * 3600);
		$this->assertTrue($this->service->isDue());

		$this->store['backup_last_run_at'] = $this->now;
		$this->service->requestRun();
		$this->assertTrue($this->service->isDue());
	}//end testDueAndNotDue()

	/**
	 * §2.2 and §2.3: a run stores the archive, records ok, audits
	 * BACKUP_CREATED with whitelisted metadata, and retention keeps the
	 * newest archives only.
	 *
	 * @return void
	 */
	public function testRunStoresAuditsAndPrunes(): void {
		$this->store['backup_enabled'] = true;
		$this->store['backup_retention_count'] = 2;
		$this->files = ['keepiq-backup-20200101-000000.zip' => 'old', 'keepiq-backup-20200102-000000.zip' => 'older-but-newer'];

		$result = $this->service->createBackup();

		$this->assertSame('ZIP-BYTES', $this->files[$result['name']]);
		$this->assertEqualsCanonicalizing(['keepiq-backup-20200102-000000.zip', $result['name']], array_keys($this->files));
		$this->assertCount(2, $this->files);
		$this->assertSame('ok', $this->store['backup_last_status']);
		$this->assertSame(AuditEventTypes::BACKUP_CREATED, $this->events[0]->getEventType());
		$metadata = $this->events[0]->getMetadata();
		$this->assertSame([], array_diff(array_keys($metadata), AuditEventTypes::WHITELIST[AuditEventTypes::BACKUP_CREATED]));
		$this->assertFalse($metadata['encrypted']);
	}//end testRunStoresAuditsAndPrunes()

	/**
	 * A failed run records the error, audits BACKUP_FAILED and keeps every
	 * existing archive.
	 *
	 * @return void
	 */
	public function testFailedRunIsRecordedAndKeepsArchives(): void {
		$this->writerFails = true;
		$this->files = ['keepiq-backup-20200101-000000.zip' => 'old'];

		try {
			$this->service->createBackup();
			$this->fail('A failed run reported success');
		} catch (\RuntimeException) {
			$this->addToAssertionCount(1);
		}

		$this->assertSame('failed', $this->store['backup_last_status']);
		$this->assertSame('disk full', $this->store['backup_last_error']);
		$this->assertSame(AuditEventTypes::BACKUP_FAILED, $this->events[0]->getEventType());
		$this->assertCount(1, $this->files);
	}//end testFailedRunIsRecordedAndKeepsArchives()

	/**
	 * With a recipient key the stored archive is encrypted.
	 *
	 * @return void
	 */
	public function testEncryptedRunWhenAKeyIsSet(): void {
		$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		$this->store['backup_recipient_public_key'] = openssl_pkey_get_details($key)['key'];

		$result = $this->service->createBackup();

		$this->assertTrue($result['encrypted']);
		$this->assertStringEndsWith('.zip.enc', $result['name']);
		$this->assertStringStartsWith(ArchiveCipher::MAGIC, $this->files[$result['name']]);
		$this->assertStringNotContainsString('ZIP-BYTES', $this->files[$result['name']]);
	}//end testEncryptedRunWhenAKeyIsSet()

	/**
	 * The job runs the service only when due, and never throws.
	 *
	 * @return void
	 */
	public function testJobRunsOnlyWhenDue(): void {
		$backups = $this->createMock(BackupService::class);
		$backups->method('isDue')->willReturnOnConsecutiveCalls(false, true);
		$backups->expects($this->once())->method('createBackup')->willThrowException(new \RuntimeException('x'));
		$job = new ScheduledVaultBackupJob(time: $this->createMock(ITimeFactory::class), backups: $backups);
		$run = new \ReflectionMethod($job, 'run');

		$run->invoke($job, null);
		$run->invoke($job, null);
	}//end testJobRunsOnlyWhenDue()
}//end class
