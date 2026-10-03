<?php

/**
 * Unit tests for verify and restore, through the occ commands
 * (admin-scheduled-vault-backups §3).
 *
 * A fake database (an array per table) sits behind TableStore; the archive,
 * cipher, reader, restore service and commands are the real ones.
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

use OCA\Keepiq\Backup\ArchiveCipher;
use OCA\Keepiq\Backup\ArchiveReader;
use OCA\Keepiq\Backup\ArchiveWriter;
use OCA\Keepiq\Backup\BackupService;
use OCA\Keepiq\Backup\BackupTableRegistry;
use OCA\Keepiq\Backup\RestoreService;
use OCA\Keepiq\Backup\SchemaFingerprint;
use OCA\Keepiq\Backup\TableStore;
use OCA\Keepiq\Command\BackupRestore;
use OCA\Keepiq\Command\BackupVerify;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Service\AuditService;
use OCP\App\IAppManager;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IConfig;
use OCP\ITempManager;
use OCP\IUserManager;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Backup of a small vault, then verify and restore through the commands.
 */
class RestoreCommandTest extends TestCase {

	/** @var array<string,array<int,array<string,mixed>>> The fake database */
	private array $db = [];

	/** @var array<string,string> The attachment blobs */
	private array $blobs = [];

	private bool $maintenance = true;

	private string $fingerprint = 'fp-1';

	private ?string $newestAudit = null;

	/** @var array<int,AuditEvent> */
	private array $events = [];

	private string $dir;

	private string $archive;

	private TableStore $tables;

	private RestoreService $restore;

	private BackupService $backups;

	/**
	 * Write a backup of a two-user vault.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/keepiq-restore-test-' . bin2hex(random_bytes(4));
		mkdir($this->dir . '/work', 0700, true);
		$this->db = array_fill_keys(BackupTableRegistry::TABLES, []);
		$this->db['secrets'] = [
			['id' => 's1', 'owner_type' => 'user', 'owner_id' => 'alice', 'name' => 'Bank', 'key' => 'RSA-CIPHERTEXT-1'],
			['id' => 's2', 'owner_type' => 'user', 'owner_id' => 'gone', 'name' => 'Old', 'key' => 'RSA-CIPHERTEXT-2'],
		];
		$this->db['enc_suites'] = [['id' => 'suite-a', 'owner_type' => 'user', 'owner_id' => 'alice', 'private_key' => 'AES-WRAPPED']];
		$this->db['ca_certs'] = [['id' => 'ca', 'private_key' => 'ICRYPTO-BLOB']];
		$this->blobs = ['blob-1' => 'ATTACHMENT-CIPHERTEXT'];

		$tables = $this->createMock(TableStore::class);
		$tables->method('rows')->willReturnCallback(fn (string $t): array => $this->db[$t]);
		$tables->method('count')->willReturnCallback(fn (string $t): int => count($this->db[$t]));
		$tables->method('newestAuditEntry')->willReturnCallback(fn (): ?string => $this->newestAudit);
		$tables->method('replaceAll')->willReturnCallback(function (callable $rowsFor): array {
			$written = [];
			foreach (BackupTableRegistry::TABLES as $table) {
				$this->db[$table] = [];
				foreach ($rowsFor($table) as $row) {
					$this->db[$table][] = $row;
				}
				$written[$table] = count($this->db[$table]);
			}
			return $written;
		});
		$this->tables = $tables;

		$fingerprint = $this->createMock(SchemaFingerprint::class);
		$fingerprint->method('current')->willReturnCallback(fn (): string => $this->fingerprint);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('0.3.4');
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(
			fn (string $key, $default = '') => ($key === 'maintenance' ? $this->maintenance : $default)
		);

		$this->archive = $this->dir . '/keepiq-backup.zip';
		(new ArchiveWriter(tables: $tables, appDataFactory: $this->blobStore(), appManager: $appManager, config: $config, fingerprint: $fingerprint))
			->write(zipPath: $this->archive, workDir: $this->dir . '/work');

		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturnCallback(fn () => tempnam($this->dir, 'kq'));
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturnCallback(static fn (string $uid): bool => $uid === 'alice');
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->willThrowException(new \Exception('HMAC does not match.'));
		$audit = $this->createMock(AuditService::class);
		$audit->method('record')->willReturnCallback(function (AuditEvent $event) {
			$this->events[] = $event;
			return new \OCA\Keepiq\Db\AuditEntry();
		});

		$this->restore = new RestoreService(
			reader: new ArchiveReader(),
			cipher: new ArchiveCipher(),
			tables: $tables,
			fingerprint: $fingerprint,
			store: new \OCA\Keepiq\Backup\ArchiveStore(appDataFactory: $this->blobStore(), config: $config, tempManager: $temp),
			config: $config,
			userManager: $users,
			crypto: $crypto,
			audit: $audit,
		);
		$this->backups = $this->createMock(BackupService::class);
		$this->backups->method('localCopy')->willReturnArgument(0);
	}//end setUp()

	/**
	 * Remove the scratch directory.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach (array_merge(glob($this->dir . '/work/*') ?: [], glob($this->dir . '/*') ?: []) as $path) {
			if (is_file($path) === true) {
				unlink($path);
			}
		}
		rmdir($this->dir . '/work');
		rmdir($this->dir);
	}//end tearDown()

	/**
	 * The attachment blob folder over $this->blobs.
	 *
	 * @return IAppDataFactory
	 */
	private function blobStore(): IAppDataFactory {
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('getDirectoryListing')->willReturnCallback(function (): array {
			$list = [];
			foreach ($this->blobs as $name => $content) {
				$file = $this->createMock(ISimpleFile::class);
				$file->method('getName')->willReturn($name);
				$file->method('getContent')->willReturn($content);
				$file->method('delete')->willReturnCallback(function () use ($name): void {
					unset($this->blobs[$name]);
				});
				$list[] = $file;
			}
			return $list;
		});
		$folder->method('newFile')->willReturnCallback(function (string $name) {
			$file = $this->createMock(ISimpleFile::class);
			$file->method('putContent')->willReturnCallback(function ($content) use ($name): void {
				$this->blobs[$name] = (string)$content;
			});
			return $file;
		});
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willReturn($folder);
		$factory = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->willReturn($appData);

		return $factory;
	}//end blobStore()

	/**
	 * A command tester for a command.
	 *
	 * @param object $command The command
	 *
	 * @return CommandTester
	 */
	private function tester(object $command): CommandTester {
		$application = new Application();
		$application->add($command);

		return new CommandTester($command);
	}//end tester()

	/**
	 * §3.2: a good archive verifies; a tampered file fails.
	 *
	 * @return void
	 */
	public function testVerifyGoodAndTampered(): void {
		$verify = $this->tester(new BackupVerify(backups: $this->backups, restore: $this->restore));
		$this->assertSame(0, $verify->execute(['file' => $this->archive]));
		$this->assertStringContainsString('complete and unchanged', $verify->getDisplay());

		$zip = new \ZipArchive();
		$zip->open($this->archive);
		$zip->addFromString('tables/secrets.jsonl', "{\"id\":\"s1\"}\n");
		$zip->close();
		$this->assertSame(1, $verify->execute(['file' => $this->archive]));
		$this->assertStringContainsString('Checksum mismatch', $verify->getDisplay());
	}//end testVerifyGoodAndTampered()

	/**
	 * §3.2 and the scenario "Archive cannot be read without the private key":
	 * an encrypted archive needs --key-file, and the wrong key fails.
	 *
	 * @return void
	 */
	public function testVerifyEncryptedNeedsTheRightKey(): void {
		$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		openssl_pkey_export($key, $private);
		$other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		openssl_pkey_export($other, $otherPrivate);
		file_put_contents($this->dir . '/right.pem', $private);
		file_put_contents($this->dir . '/wrong.pem', $otherPrivate);
		$encrypted = $this->dir . '/backup.zip.enc';
		(new ArchiveCipher())->encryptFile(plainPath: $this->archive, outPath: $encrypted, publicPem: openssl_pkey_get_details($key)['key']);
		$verify = $this->tester(new BackupVerify(backups: $this->backups, restore: $this->restore));

		$this->assertSame(1, $verify->execute(['file' => $encrypted]));
		$this->assertStringContainsString('encrypted', $verify->getDisplay());
		$this->assertSame(1, $verify->execute(['file' => $encrypted, '--key-file' => $this->dir . '/wrong.pem']));
		$this->assertSame(0, $verify->execute(['file' => $encrypted, '--key-file' => $this->dir . '/right.pem']));
	}//end testVerifyEncryptedNeedsTheRightKey()

	/**
	 * Scenario "Restore outside maintenance mode is refused": no table changes.
	 *
	 * @return void
	 */
	public function testRestoreOutsideMaintenanceIsRefused(): void {
		$this->maintenance = false;
		$before = $this->db;
		$restore = $this->tester(new BackupRestore(backups: $this->backups, restore: $this->restore));

		$this->assertSame(1, $restore->execute(['file' => $this->archive]));
		$this->assertStringContainsString('Maintenance mode is off', $restore->getDisplay());
		$this->assertSame($before, $this->db);
	}//end testRestoreOutsideMaintenanceIsRefused()

	/**
	 * A different schema and an archive older than the audit log are refused;
	 * --force allows the second only.
	 *
	 * @return void
	 */
	public function testSchemaAndAgeRules(): void {
		$restore = $this->tester(new BackupRestore(backups: $this->backups, restore: $this->restore));

		$this->fingerprint = 'fp-2';
		$this->assertSame(1, $restore->execute(['file' => $this->archive, '--dry-run' => true]));
		$this->assertStringContainsString('different Keepiq schema', $restore->getDisplay());

		$this->fingerprint = 'fp-1';
		$this->newestAudit = '2999-01-01 00:00:00';
		$this->assertSame(1, $restore->execute(['file' => $this->archive, '--dry-run' => true]));
		$this->assertStringContainsString('--force', $restore->getDisplay());
		$this->assertSame(0, $restore->execute(['file' => $this->archive, '--dry-run' => true, '--force' => true]));
	}//end testSchemaAndAgeRules()

	/**
	 * Scenario "Dry run shows the difference": counts printed, nothing changed.
	 *
	 * @return void
	 */
	public function testDryRunChangesNothing(): void {
		$this->db['secrets'][] = ['id' => 's3', 'owner_type' => 'user', 'owner_id' => 'alice', 'key' => 'NEWER'];
		$before = $this->db;
		$restore = $this->tester(new BackupRestore(backups: $this->backups, restore: $this->restore));

		$this->assertSame(0, $restore->execute(['file' => $this->archive, '--dry-run' => true]));
		$this->assertMatchesRegularExpression('/secrets\s*\|\s*3\s*\|\s*2/', $restore->getDisplay());
		$this->assertSame($before, $this->db);
	}//end testDryRunChangesNothing()

	/**
	 * §3.3 and §3.4: the warnings come first, a "no" changes nothing, a
	 * "yes" restores every table and blob exactly as written and audits.
	 *
	 * @return void
	 */
	public function testRestoreWarnsConfirmsAndReplacesEveryTable(): void {
		$archived = $this->db;
		$this->db['secrets'][0]['key'] = 'CHANGED-AFTER-BACKUP';
		$this->db['secrets'][] = ['id' => 's3', 'owner_type' => 'user', 'owner_id' => 'alice', 'key' => 'NEWER'];
		$this->blobs = ['blob-2' => 'LATER'];
		$restore = $this->tester(new BackupRestore(backups: $this->backups, restore: $this->restore));

		$restore->setInputs(['no']);
		$this->assertSame(1, $restore->execute(['file' => $this->archive]));
		$display = $restore->getDisplay();
		$this->assertStringContainsString('is lost', $display);
		$this->assertStringContainsString('master password that was valid when the backup ran', $display);
		$this->assertStringContainsString('instance\'s secret', $display);
		$this->assertStringContainsString('gone', $display);
		$this->assertSame('CHANGED-AFTER-BACKUP', $this->db['secrets'][0]['key']);

		$restore->setInputs(['yes']);
		$this->assertSame(0, $restore->execute(['file' => $this->archive]));
		foreach (BackupTableRegistry::TABLES as $table) {
			$this->assertEquals($archived[$table], $this->db[$table], $table);
		}
		$this->assertSame(['blob-1' => 'ATTACHMENT-CIPHERTEXT'], $this->blobs);
		$this->assertSame(AuditEventTypes::BACKUP_RESTORED, end($this->events)->getEventType());
	}//end testRestoreWarnsConfirmsAndReplacesEveryTable()
}//end class
