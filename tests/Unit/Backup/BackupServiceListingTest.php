<?php

/**
 * Keepiq BackupService listing Test
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
use OCA\Keepiq\Service\AuditService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\ITempManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What the admin section and the command line see of stored archives and
 * of the last run.
 */
#[CoversClass(BackupService::class)]
#[UsesClass(ArchiveStore::class)]
class BackupServiceListingTest extends TestCase {

	/**
	 * A service over the given archive folder and app config.
	 *
	 * @param ISimpleFolder $folder The archive folder
	 * @param IAppConfig $appConfig The app config
	 *
	 * @return BackupService
	 */
	private function service(ISimpleFolder $folder, IAppConfig $appConfig): BackupService {
		$appData = $this->createMock(originalClassName: IAppData::class);
		$appData->method('getFolder')->willReturn($folder);
		$factory = $this->createMock(originalClassName: IAppDataFactory::class);
		$factory->method('get')->willReturn($appData);
		$config = $this->createMock(originalClassName: IConfig::class);
		$config->method('getSystemValue')->willReturnMap(
			[
				['datadirectory', '', '/data'],
				['instanceid', '', 'oc1'],
			]
		);

		return new BackupService(
			writer: $this->createMock(originalClassName: ArchiveWriter::class),
			cipher: $this->createMock(originalClassName: ArchiveCipher::class),
			settings: $this->createMock(originalClassName: BackupSettings::class),
			store: new ArchiveStore(
				appDataFactory: $factory,
				config: $config,
				tempManager: $this->createMock(originalClassName: ITempManager::class)
			),
			appConfig: $appConfig,
			time: $this->createMock(originalClassName: ITimeFactory::class),
			audit: $this->createMock(originalClassName: AuditService::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end service()

	/**
	 * A stored archive file double.
	 *
	 * @param string $name The file name
	 * @param int $size The size in bytes
	 * @param int $mtime The modification time
	 *
	 * @return ISimpleFile
	 */
	private function archive(string $name, int $size, int $mtime): ISimpleFile {
		$file = $this->createMock(originalClassName: ISimpleFile::class);
		$file->method('getName')->willReturn($name);
		$file->method('getSize')->willReturn($size);
		$file->method('getMTime')->willReturn($mtime);
		$file->expects($this->never())->method('getContent');
		return $file;
	}//end archive()

	/**
	 * listArchives(): newest first by name, flags encrypted archives, gives
	 * the path on disk, and never reads an archive's content.
	 *
	 * @return void
	 */
	public function testListArchivesNewestFirstWithoutContent(): void {
		$folder = $this->createMock(originalClassName: ISimpleFolder::class);
		$folder->method('getDirectoryListing')->willReturn(
			[
				$this->archive(name: 'keepiq-backup-20261101-010000.zip', size: 100, mtime: 1000),
				$this->archive(name: 'keepiq-backup-20261103-010000.zip.enc', size: 300, mtime: 3000),
				$this->archive(name: 'keepiq-backup-20261102-010000.zip', size: 200, mtime: 2000),
			]
		);

		$list = $this->service(folder: $folder, appConfig: $this->createMock(originalClassName: IAppConfig::class))->listArchives();

		self::assertSame(
			expected: [
				[
					'name' => 'keepiq-backup-20261103-010000.zip.enc',
					'size' => 300,
					'createdAt' => 3000,
					'encrypted' => true,
					'path' => '/data/appdata_oc1/keepiq/backups/keepiq-backup-20261103-010000.zip.enc',
				],
				[
					'name' => 'keepiq-backup-20261102-010000.zip',
					'size' => 200,
					'createdAt' => 2000,
					'encrypted' => false,
					'path' => '/data/appdata_oc1/keepiq/backups/keepiq-backup-20261102-010000.zip',
				],
				[
					'name' => 'keepiq-backup-20261101-010000.zip',
					'size' => 100,
					'createdAt' => 1000,
					'encrypted' => false,
					'path' => '/data/appdata_oc1/keepiq/backups/keepiq-backup-20261101-010000.zip',
				],
			],
			actual: $list
		);
	}//end testListArchivesNewestFirstWithoutContent()

	/**
	 * listArchives(): an empty folder lists nothing.
	 *
	 * @return void
	 */
	public function testListArchivesEmpty(): void {
		$folder = $this->createMock(originalClassName: ISimpleFolder::class);
		$folder->method('getDirectoryListing')->willReturn([]);

		$service = $this->service(folder: $folder, appConfig: $this->createMock(originalClassName: IAppConfig::class));

		self::assertSame(expected: [], actual: $service->listArchives());
	}//end testListArchivesEmpty()

	/**
	 * status(): the last run read from the app config, under the Keepiq app
	 * id and with safe defaults.
	 *
	 * @return void
	 */
	public function testStatusReadsTheLastRun(): void {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->expects($this->once())->method('getValueInt')
			->with('keepiq', BackupService::LAST_RUN, 0)->willReturn(1762000000);
		$appConfig->method('getValueString')->willReturnMap(
			[
				['keepiq', BackupService::LAST_STATUS, '', false, 'failed'],
				['keepiq', BackupService::LAST_ERROR, '', false, 'disk full'],
			]
		);
		$appConfig->expects($this->once())->method('getValueBool')
			->with('keepiq', BackupService::RUN_REQUESTED, false)->willReturn(true);

		self::assertSame(
			expected: ['lastRunAt' => 1762000000, 'lastStatus' => 'failed', 'lastError' => 'disk full', 'runRequested' => true],
			actual: $this->service(folder: $this->createMock(originalClassName: ISimpleFolder::class), appConfig: $appConfig)->status()
		);
	}//end testStatusReadsTheLastRun()
}//end class
