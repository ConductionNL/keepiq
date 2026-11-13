<?php

/**
 * Keepiq BackupService Edge Test
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
use OCA\Keepiq\Backup\ArchiveStore;
use OCA\Keepiq\Backup\ArchiveWriter;
use OCA\Keepiq\Backup\BackupService;
use OCA\Keepiq\Backup\BackupSettings;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Service\AuditService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A failing audit does not fail a backup, the newest archive always
 * survives pruning, and local copies come from the store.
 */
#[CoversClass(BackupService::class)]
#[UsesClass(AuditEventFactory::class)]
#[UsesClass(AuditEvent::class)]
class BackupServiceEdgeTest extends TestCase {

	/**
	 * @var ArchiveStore&MockObject
	 */
	private ArchiveStore $store;

	/**
	 * @var AuditService&MockObject
	 */
	private AuditService $audit;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * @var ISimpleFolder&MockObject
	 */
	private ISimpleFolder $folder;

	/**
	 * Scratch files created by a test.
	 *
	 * @var array<int,string>
	 */
	private array $scratch = [];

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = $this->createMock(ArchiveStore::class);
		$this->audit = $this->createMock(AuditService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->folder = $this->createMock(ISimpleFolder::class);
		$this->store->method('archives')->willReturn($this->folder);
	}//end setUp()

	/**
	 * Remove scratch files.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->scratch as $path) {
			if (is_file($path) === true) {
				unlink($path);
			}
		}
	}//end tearDown()

	/**
	 * The service over the mocked collaborators.
	 *
	 * @return BackupService
	 */
	private function service(): BackupService {
		$writer = $this->createMock(ArchiveWriter::class);
		$writer->method('write')->willReturnCallback(
			static function (string $zipPath): array {
				file_put_contents($zipPath, 'ZIP');
				return [];
			}
		);

		$settings = $this->createMock(BackupSettings::class);
		$settings->method('read')->willReturn(
			[
				BackupSettings::ENABLED => true,
				BackupSettings::INTERVAL => 24,
				BackupSettings::RETENTION => 7,
				BackupSettings::PUBLIC_KEY => '',
			]
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_800_000_000);

		return new BackupService(
			writer: $writer,
			cipher: $this->createMock(ArchiveCipher::class),
			settings: $settings,
			store: $this->store,
			appConfig: $this->createMock(IAppConfig::class),
			time: $time,
			audit: $this->audit,
			logger: $this->logger,
		);
	}//end service()

	/**
	 * An archive file mock with a name that records its deletion.
	 *
	 * @param string             $name    The file name
	 * @param array<int,string> &$deleted The names deleted so far
	 *
	 * @return ISimpleFile
	 */
	private function file(string $name, array &$deleted): ISimpleFile {
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getName')->willReturn($name);
		$file->method('delete')->willReturnCallback(
			static function () use ($name, &$deleted): void {
				$deleted[] = $name;
			}
		);
		return $file;
	}//end file()

	/**
	 * When the audit entry cannot be written the backup still succeeds and
	 * the failure is logged.
	 *
	 * @return void
	 */
	public function testAFailingAuditIsLoggedAndTheBackupStillSucceeds(): void {
		$this->store->method('tempFolder')->willReturn(sys_get_temp_dir());
		$this->store->method('tempFile')->willReturnCallback(
			function (): string {
				$path = (string)tempnam(sys_get_temp_dir(), 'kqbe');
				$this->scratch[] = $path;
				return $path;
			}
		);
		$stored = '';
		$target = $this->createMock(ISimpleFile::class);
		$target->method('putContent')->willReturnCallback(
			static function ($content) use (&$stored): void {
				$stored = (string)stream_get_contents($content);
			}
		);
		$this->folder->method('newFile')->willReturn($target);
		$this->folder->method('getDirectoryListing')->willReturn([]);
		$this->audit->expects($this->once())->method('record')->willThrowException(new RuntimeException('audit down'));
		$this->logger->expects($this->once())->method('error')->with('Keepiq: backup audit entry could not be recorded: audit down');

		$result = $this->service()->createBackup();

		$this->assertSame(['name' => 'keepiq-backup-' . date('Ymd-His', 1_800_000_000) . '.zip', 'encrypted' => false, 'size' => 3], $result);
		$this->assertSame('ZIP', $stored);
	}//end testAFailingAuditIsLoggedAndTheBackupStillSucceeds()

	/**
	 * Pruning to zero still keeps the newest archive.
	 *
	 * @return void
	 */
	public function testPruneAlwaysKeepsTheNewestArchive(): void {
		$deleted = [];
		$this->folder->method('getDirectoryListing')->willReturn(
			[
				$this->file(name: 'keepiq-backup-20260101-000000.zip', deleted: $deleted),
				$this->file(name: 'keepiq-backup-20260301-000000.zip', deleted: $deleted),
				$this->file(name: 'keepiq-backup-20260201-000000.zip', deleted: $deleted),
			]
		);

		$removed = $this->service()->prune(keep: 0);

		$this->assertSame(2, $removed);
		$this->assertSame(['keepiq-backup-20260201-000000.zip', 'keepiq-backup-20260101-000000.zip'], $deleted);
	}//end testPruneAlwaysKeepsTheNewestArchive()

	/**
	 * A local copy is whatever the store hands back for the name or path.
	 *
	 * @return void
	 */
	public function testLocalCopyComesFromTheStore(): void {
		$this->store->expects($this->once())->method('localCopy')->with('keepiq-backup-x.zip')->willReturn('/tmp/copy.zip');

		$this->assertSame('/tmp/copy.zip', $this->service()->localCopy(nameOrPath: 'keepiq-backup-x.zip'));
	}//end testLocalCopyComesFromTheStore()
}//end class
