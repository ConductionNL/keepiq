<?php

/**
 * Unit tests for the backup archive (admin-scheduled-vault-backups §1).
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
use OCA\Keepiq\Backup\ArchiveReader;
use OCA\Keepiq\Backup\ArchiveWriter;
use OCA\Keepiq\Backup\BackupTableRegistry;
use OCA\Keepiq\Backup\SchemaFingerprint;
use OCA\Keepiq\Backup\TableStore;
use OCA\Keepiq\Migration\Version001000Date20260908000000;
use OCP\App\IAppManager;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;

/**
 * Registry completeness, write-verify round trip, ciphertext only, encryption.
 */
class ArchiveTest extends TestCase {

	private string $dir;

	/**
	 * A scratch directory per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/keepiq-backup-test-' . bin2hex(random_bytes(4));
		mkdir($this->dir . '/work', 0700, true);
	}//end setUp()

	/**
	 * Remove the scratch directory.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach (glob($this->dir . '/work/*') ?: [] as $file) {
			unlink($file);
		}
		foreach (glob($this->dir . '/*.*') ?: [] as $file) {
			unlink($file);
		}
		rmdir($this->dir . '/work');
		rmdir($this->dir);
	}//end tearDown()

	/**
	 * §1.1: the registry holds exactly the tables of the consolidated schema,
	 * so a new table can never be left out of a backup silently.
	 *
	 * @return void
	 */
	public function testRegistryMatchesTheConsolidatedSchema(): void {
		$schema = (new ReflectionClassConstant(Version001000Date20260908000000::class, 'SCHEMA'))->getValue();
		$expected = array_keys($schema);
		sort($expected);
		$actual = BackupTableRegistry::TABLES;
		sort($actual);

		$this->assertSame($expected, $actual);
	}//end testRegistryMatchesTheConsolidatedSchema()

	/**
	 * Build a writer over fixture rows and one blob.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $rowsByTable Rows per prefixed table
	 *
	 * @return ArchiveWriter
	 */
	private function writer(array $rowsByTable): ArchiveWriter {
		$tables = $this->createMock(TableStore::class);
		$tables->method('rows')->willReturnCallback(
			static fn (string $table): array => ($rowsByTable['keepiq_' . $table] ?? [])
		);

		$blob = $this->createMock(ISimpleFile::class);
		$blob->method('getName')->willReturn('blob-1');
		$blob->method('getContent')->willReturn('AES-GCM-ATTACHMENT-CIPHERTEXT');
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('getDirectoryListing')->willReturn([$blob]);
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willReturn($folder);
		$factory = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->willReturn($appData);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('0.3.4');
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturn('oc123');
		$fingerprint = $this->createMock(SchemaFingerprint::class);
		$fingerprint->method('current')->willReturn('fp-1');

		return new ArchiveWriter(tables: $tables, appDataFactory: $factory, appManager: $appManager, config: $config, fingerprint: $fingerprint);
	}//end writer()

	/**
	 * §1.2: write then verify; the manifest counts rows, every checksum
	 * holds, rows come back as stored, and a secret's value is the stored
	 * ciphertext, never anything else.
	 *
	 * @return void
	 */
	public function testWriteThenVerifyRoundTrip(): void {
		$zip = $this->dir . '/backup.zip';
		$manifest = $this->writer([
			'keepiq_secrets' => [
				['id' => 's1', 'owner_id' => 'alice', 'name' => 'Bank', 'key' => 'RSA-CIPHERTEXT-BLOB'],
				['id' => 's2', 'owner_id' => 'alice', 'name' => 'Mail', 'key' => 'RSA-CIPHERTEXT-BLOB-2'],
			],
			'keepiq_enc_suites' => [['id' => 'suite', 'private_key' => 'AES-WRAPPED-PRIVATE-KEY']],
		])->write(zipPath: $zip, workDir: $this->dir . '/work');

		$this->assertSame(ArchiveWriter::FORMAT, $manifest['format']);
		$this->assertSame('fp-1', $manifest['schemaFingerprint']);
		$this->assertSame(2, $manifest['files']['tables/secrets.jsonl']['rows']);
		$this->assertSame(0, $manifest['files']['tables/audit_log.jsonl']['rows']);

		$reader = new ArchiveReader();
		$verified = $reader->verify(zipPath: $zip);
		$this->assertSame($manifest['files'], $verified['files']);

		$rows = iterator_to_array($reader->rows(zipPath: $zip, table: 'secrets'), false);
		$this->assertSame('RSA-CIPHERTEXT-BLOB', $rows[0]['key']);
		$this->assertSame(['blob-1' => 'AES-GCM-ATTACHMENT-CIPHERTEXT'], iterator_to_array($reader->blobs(zipPath: $zip)));
	}//end testWriteThenVerifyRoundTrip()

	/**
	 * A changed file fails the checksum check.
	 *
	 * @return void
	 */
	public function testTamperedArchiveFailsVerification(): void {
		$zip = $this->dir . '/backup.zip';
		$this->writer(['keepiq_secrets' => [['id' => 's1', 'key' => 'RSA']]])->write(zipPath: $zip, workDir: $this->dir . '/work');
		$archive = new \ZipArchive();
		$archive->open($zip);
		$archive->addFromString('tables/secrets.jsonl', "{\"id\":\"s1\",\"key\":\"FORGED\"}\n");
		$archive->close();

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Checksum mismatch');
		(new ArchiveReader())->verify(zipPath: $zip);
	}//end testTamperedArchiveFailsVerification()

	/**
	 * A key pair for the encryption tests.
	 *
	 * @return array{0:string,1:string} Public and private PEM
	 */
	private function keyPair(): array {
		$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		openssl_pkey_export($key, $private);
		return [openssl_pkey_get_details($key)['key'], $private];
	}//end keyPair()

	/**
	 * §1.3: encrypt and decrypt round trip across several segments; the
	 * encrypted file holds no plaintext; a wrong key and a cut-off file fail.
	 *
	 * @return void
	 */
	public function testEncryptionRoundTripAndRefusals(): void {
		[$public, $private] = $this->keyPair();
		[, $otherPrivate] = $this->keyPair();
		$plain = $this->dir . '/plain.zip';
		$data = str_repeat('PLAINTEXT-MARKER-', 150000);
		file_put_contents($plain, $data);
		$cipher = new ArchiveCipher();

		$cipher->encryptFile(plainPath: $plain, outPath: $this->dir . '/enc.bin', publicPem: $public);
		$this->assertTrue($cipher->isEncrypted(path: $this->dir . '/enc.bin'));
		$this->assertFalse($cipher->isEncrypted(path: $plain));
		$this->assertStringNotContainsString('PLAINTEXT-MARKER', (string)file_get_contents($this->dir . '/enc.bin'));

		$cipher->decryptFile(encPath: $this->dir . '/enc.bin', outPath: $this->dir . '/out.zip', privatePem: $private);
		$this->assertSame($data, file_get_contents($this->dir . '/out.zip'));

		try {
			$cipher->decryptFile(encPath: $this->dir . '/enc.bin', outPath: $this->dir . '/bad.zip', privatePem: $otherPrivate);
			$this->fail('A wrong key opened the backup');
		} catch (InvalidArgumentException $e) {
			$this->assertStringContainsString('does not open', $e->getMessage());
		}

		$encrypted = (string)file_get_contents($this->dir . '/enc.bin');
		file_put_contents($this->dir . '/cut.bin', substr($encrypted, 0, (int)(strlen($encrypted) * 0.6)));
		$this->expectException(InvalidArgumentException::class);
		$cipher->decryptFile(encPath: $this->dir . '/cut.bin', outPath: $this->dir . '/cut.zip', privatePem: $private);
	}//end testEncryptionRoundTripAndRefusals()

	/**
	 * A segment boundary cut (whole segments dropped) is refused too.
	 *
	 * @return void
	 */
	public function testDroppedTrailingSegmentIsRefused(): void {
		[$public, $private] = $this->keyPair();
		$plain = $this->dir . '/plain.zip';
		file_put_contents($plain, str_repeat('A', 1048576 * 2 + 10));
		$cipher = new ArchiveCipher();
		$cipher->encryptFile(plainPath: $plain, outPath: $this->dir . '/enc.bin', publicPem: $public);

		// Drop the last (third) segment exactly: header + two full segments.
		$encrypted = (string)file_get_contents($this->dir . '/enc.bin');
		$headerLength = strlen(ArchiveCipher::MAGIC) + 4 + unpack('N', substr($encrypted, strlen(ArchiveCipher::MAGIC), 4))[1];
		$segment = 4 + 12 + 1048576 + 16;
		file_put_contents($this->dir . '/short.bin', substr($encrypted, 0, $headerLength + 2 * $segment));

		$this->expectException(InvalidArgumentException::class);
		$cipher->decryptFile(encPath: $this->dir . '/short.bin', outPath: $this->dir . '/short.zip', privatePem: $private);
	}//end testDroppedTrailingSegmentIsRefused()
}//end class
