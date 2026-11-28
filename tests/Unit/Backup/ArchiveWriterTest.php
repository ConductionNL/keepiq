<?php

/**
 * Keepiq ArchiveWriter Test
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

use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Backup\ArchiveWriter;
use OCA\Keepiq\Backup\BackupTableRegistry;
use OCA\Keepiq\Backup\SchemaFingerprint;
use OCA\Keepiq\Backup\TableStore;
use OCP\App\IAppManager;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ZipArchive;
use RuntimeException;

/**
 * The archive the writer produces when there are no attachment blobs.
 */
#[CoversClass(ArchiveWriter::class)]
class ArchiveWriterTest extends TestCase {

	/**
	 * Scratch directory of the test.
	 *
	 * @var string
	 */
	private string $dir;

	/**
	 * A scratch directory per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/keepiq-writer-test-' . bin2hex(random_bytes(4));
		mkdir($this->dir . '/work', 0700, true);
	}//end setUp()

	/**
	 * Remove the scratch directory.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ([...(glob($this->dir . '/work/*') ?: []), ...(glob($this->dir . '/*.zip') ?: [])] as $file) {
			unlink($file);
		}

		rmdir($this->dir . '/work');
		rmdir($this->dir);
	}//end tearDown()

	/**
	 * Without a blob folder the archive holds the tables only, with each
	 * table's rows dumped one JSON line per row and counted in the manifest.
	 *
	 * @return void
	 */
	public function testWithoutABlobFolderOnlyTablesAreWritten(): void {
		$tables = $this->createMock(TableStore::class);
		$tables->method('rows')->willReturnCallback(
			static fn (string $table): array => ($table === 'secrets' ? [['id' => 's1', 'url' => 'a/b'], ['id' => 's2']] : [])
		);

		$appData = $this->createMock(IAppData::class);
		$appData->expects($this->once())->method('getFolder')->with(ArchiveWriter::BLOB_FOLDER)->willThrowException(new NotFoundException());
		$factory = $this->createMock(IAppDataFactory::class);
		$factory->expects($this->once())->method('get')->with(Application::APP_ID)->willReturn($appData);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->expects($this->once())->method('getAppVersion')->with(Application::APP_ID)->willReturn('1.2.3');
		$config = $this->createMock(IConfig::class);
		$config->expects($this->once())->method('getSystemValue')->with('instanceid', '')->willReturn('oc-inst');
		$fingerprint = $this->createMock(SchemaFingerprint::class);
		$fingerprint->method('current')->willReturn('fp-9');

		$writer = new ArchiveWriter(tables: $tables, appDataFactory: $factory, appManager: $appManager, config: $config, fingerprint: $fingerprint);
		$manifest = $writer->write(zipPath: $this->dir . '/out.zip', workDir: $this->dir . '/work');

		$expectedNames = array_map(static fn (string $t): string => 'tables/' . $t . '.jsonl', BackupTableRegistry::TABLES);
		$this->assertSame($expectedNames, array_keys($manifest['files']));
		$this->assertSame('1.2.3', $manifest['appVersion']);
		$this->assertSame('fp-9', $manifest['schemaFingerprint']);
		$this->assertSame('oc-inst', $manifest['instanceId']);
		$this->assertSame(2, $manifest['files']['tables/secrets.jsonl']['rows']);

		$zip = new ZipArchive();
		$zip->open($this->dir . '/out.zip', ZipArchive::RDONLY);
		$secrets = (string)$zip->getFromName('tables/secrets.jsonl');
		$stored = json_decode((string)$zip->getFromName('manifest.json'), true);
		$zip->close();

		$this->assertSame("{\"id\":\"s1\",\"url\":\"a/b\"}\n{\"id\":\"s2\"}\n", $secrets);
		$this->assertSame(hash('sha256', $secrets), $manifest['files']['tables/secrets.jsonl']['sha256']);
		$this->assertSame($manifest, $stored);
	}//end testWithoutABlobFolderOnlyTablesAreWritten()

	/**
	 * A writer whose collaborators answer nothing in particular.
	 *
	 * @return ArchiveWriter
	 */
	private function quietWriter(): ArchiveWriter {
		$tables = $this->createMock(TableStore::class);
		$tables->method('rows')->willReturn([]);
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willThrowException(new NotFoundException());
		$factory = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->willReturn($appData);

		return new ArchiveWriter(
			tables: $tables,
			appDataFactory: $factory,
			appManager: $this->createMock(IAppManager::class),
			config: $this->createMock(IConfig::class),
			fingerprint: $this->createMock(SchemaFingerprint::class)
		);
	}//end quietWriter()

	/**
	 * Write with PHP warnings silenced, and return the refusal.
	 *
	 * @param string $zipPath The archive path.
	 * @param string $workDir The work directory.
	 *
	 * @return RuntimeException|null The refusal, or null when it wrote.
	 */
	private function refusalOf(string $zipPath, string $workDir): ?RuntimeException {
		set_error_handler(static fn (): bool => true);
		try {
			$this->quietWriter()->write(zipPath: $zipPath, workDir: $workDir);
			return null;
		} catch (RuntimeException $e) {
			return $e;
		} finally {
			restore_error_handler();
		}
	}//end refusalOf()

	/**
	 * A path the archive cannot be created at is refused.
	 *
	 * @return void
	 */
	public function testAnArchiveAtADirectoryIsRefused(): void {
		$refusal = $this->refusalOf(zipPath: $this->dir . '/work', workDir: $this->dir . '/work');

		$this->assertSame('Cannot create the backup archive', $refusal?->getMessage());
	}//end testAnArchiveAtADirectoryIsRefused()

	/**
	 * A work directory the table dumps cannot be written to is refused, naming the file.
	 *
	 * @return void
	 */
	public function testAMissingWorkDirectoryIsRefused(): void {
		$refusal = $this->refusalOf(zipPath: $this->dir . '/out.zip', workDir: $this->dir . '/missing');

		$this->assertSame('Cannot write ' . $this->dir . '/missing/' . BackupTableRegistry::TABLES[0] . '.jsonl', $refusal?->getMessage());
	}//end testAMissingWorkDirectoryIsRefused()

	/**
	 * An archive whose folder does not exist cannot be finished, and that is refused.
	 *
	 * @return void
	 */
	public function testAnArchiveThatCannotBeFinishedIsRefused(): void {
		$refusal = $this->refusalOf(zipPath: $this->dir . '/missing/out.zip', workDir: $this->dir . '/work');

		$this->assertSame('Cannot finish the backup archive', $refusal?->getMessage());
	}//end testAnArchiveThatCannotBeFinishedIsRefused()
}//end class
