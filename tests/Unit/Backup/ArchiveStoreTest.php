<?php

/**
 * Keepiq ArchiveStore Test
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
use OCA\Keepiq\Backup\ArchiveStore;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IConfig;
use OCP\ITempManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Where archives live, and how a named or on-disk archive becomes a local file.
 */
#[CoversClass(ArchiveStore::class)]
class ArchiveStoreTest extends TestCase {

	/**
	 * Scratch files created by a test.
	 *
	 * @var array<int,string>
	 */
	private array $scratch = [];

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
	 * A new empty scratch file.
	 *
	 * @return string
	 */
	private function scratchFile(): string {
		$path = (string)tempnam(sys_get_temp_dir(), 'keepiq-test');
		$this->scratch[] = $path;
		return $path;
	}//end scratchFile()

	/**
	 * A store over the given app data and temp manager.
	 *
	 * @param IAppData $appData The Keepiq app data
	 * @param ITempManager|null $temp The temp manager
	 * @param IConfig|null $config The system config
	 *
	 * @return ArchiveStore
	 */
	private function store(IAppData $appData, ?ITempManager $temp = null, ?IConfig $config = null): ArchiveStore {
		$factory = $this->createMock(originalClassName: IAppDataFactory::class);
		$factory->method('get')->with('keepiq')->willReturn($appData);

		return new ArchiveStore(
			appDataFactory: $factory,
			config: $config ?? $this->createMock(originalClassName: IConfig::class),
			tempManager: $temp ?? $this->createMock(originalClassName: ITempManager::class),
		);
	}//end store()

	/**
	 * archives(): an existing folder is returned as is.
	 *
	 * @return void
	 */
	public function testArchivesReturnsTheExistingFolder(): void {
		$folder = $this->createMock(originalClassName: ISimpleFolder::class);
		$appData = $this->createMock(originalClassName: IAppData::class);
		$appData->expects($this->once())->method('getFolder')->with('backups')->willReturn($folder);
		$appData->expects($this->never())->method('newFolder');

		self::assertSame(expected: $folder, actual: $this->store(appData: $appData)->archives());
	}//end testArchivesReturnsTheExistingFolder()

	/**
	 * blobs(): a missing folder is created.
	 *
	 * @return void
	 */
	public function testBlobsCreatesAMissingFolder(): void {
		$folder = $this->createMock(originalClassName: ISimpleFolder::class);
		$appData = $this->createMock(originalClassName: IAppData::class);
		$appData->method('getFolder')->with('attachments')->willThrowException(new NotFoundException());
		$appData->expects($this->once())->method('newFolder')->with('attachments')->willReturn($folder);

		self::assertSame(expected: $folder, actual: $this->store(appData: $appData)->blobs());
	}//end testBlobsCreatesAMissingFolder()

	/**
	 * tempFile() and tempFolder() come from the temp manager.
	 *
	 * @return void
	 */
	public function testTempPathsComeFromTheTempManager(): void {
		$temp = $this->createMock(originalClassName: ITempManager::class);
		$temp->expects($this->once())->method('getTemporaryFile')->with('.zip')->willReturn('/tmp/oc_tmp_1.zip');
		$temp->expects($this->once())->method('getTemporaryFolder')->with('keepiq-backup')->willReturn('/tmp/oc_tmp_2/');
		$store = $this->store(appData: $this->createMock(originalClassName: IAppData::class), temp: $temp);

		self::assertSame(expected: '/tmp/oc_tmp_1.zip', actual: $store->tempFile(suffix: '.zip'));
		self::assertSame(expected: '/tmp/oc_tmp_2/', actual: $store->tempFolder());
	}//end testTempPathsComeFromTheTempManager()

	/**
	 * localCopy(): a path on disk is used directly, without touching app data.
	 *
	 * @return void
	 */
	public function testLocalCopyUsesAFileOnDisk(): void {
		$path = $this->scratchFile();
		$appData = $this->createMock(originalClassName: IAppData::class);
		$appData->expects($this->never())->method('getFolder');

		self::assertSame(expected: $path, actual: $this->store(appData: $appData)->localCopy(nameOrPath: $path));
	}//end testLocalCopyUsesAFileOnDisk()

	/**
	 * localCopy(): a stored archive is copied, by base name only, to a temp file.
	 *
	 * @return void
	 */
	public function testLocalCopyCopiesAStoredArchive(): void {
		$local = $this->scratchFile();
		$file = $this->createMock(originalClassName: ISimpleFile::class);
		$file->method('getContent')->willReturn('archive bytes');
		$folder = $this->createMock(originalClassName: ISimpleFolder::class);
		$folder->expects($this->once())->method('getFile')->with('keepiq-backup-20261101-010000.zip')->willReturn($file);
		$appData = $this->createMock(originalClassName: IAppData::class);
		$appData->method('getFolder')->willReturn($folder);
		$temp = $this->createMock(originalClassName: ITempManager::class);
		$temp->method('getTemporaryFile')->with('.keepiq-backup')->willReturn($local);

		$copy = $this->store(appData: $appData, temp: $temp)->localCopy(nameOrPath: '../../keepiq-backup-20261101-010000.zip');

		self::assertSame(expected: $local, actual: $copy);
		self::assertSame(expected: 'archive bytes', actual: file_get_contents($local));
	}//end testLocalCopyCopiesAStoredArchive()

	/**
	 * localCopy(): an unknown name is refused with the name in the message.
	 *
	 * @return void
	 */
	public function testLocalCopyRefusesAnUnknownArchive(): void {
		$folder = $this->createMock(originalClassName: ISimpleFolder::class);
		$folder->method('getFile')->willThrowException(new NotFoundException());
		$appData = $this->createMock(originalClassName: IAppData::class);
		$appData->method('getFolder')->willReturn($folder);
		$temp = $this->createMock(originalClassName: ITempManager::class);
		$temp->expects($this->never())->method('getTemporaryFile');

		$this->expectException(exception: InvalidArgumentException::class);
		$this->expectExceptionMessage(message: 'No such backup: missing.zip');
		$this->store(appData: $appData, temp: $temp)->localCopy(nameOrPath: 'missing.zip');
	}//end testLocalCopyRefusesAnUnknownArchive()

	/**
	 * diskPath(): data directory (trailing slash trimmed), instance app data,
	 * the app id, the backups folder and the name.
	 *
	 * @return void
	 */
	public function testDiskPathPointsIntoTheInstanceAppData(): void {
		$config = $this->createMock(originalClassName: IConfig::class);
		$config->method('getSystemValue')->willReturnMap(
			[
				['datadirectory', '', '/var/www/data/'],
				['instanceid', '', 'oc1a2b3c'],
			]
		);
		$store = $this->store(appData: $this->createMock(originalClassName: IAppData::class), config: $config);

		self::assertSame(
			expected: '/var/www/data/appdata_oc1a2b3c/keepiq/backups/keepiq-backup-1.zip.enc',
			actual: $store->diskPath(name: 'keepiq-backup-1.zip.enc')
		);
	}//end testDiskPathPointsIntoTheInstanceAppData()
}//end class
