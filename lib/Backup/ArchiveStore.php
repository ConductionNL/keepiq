<?php

/**
 * Keepiq Backup Archive Store
 *
 * Where backups and their scratch files live (admin-scheduled-vault-backups
 * D3): the app data folder `backups` for archives, the attachment blob
 * folder a restore writes back, temporary files, and the path on disk an
 * administrator copies off-site. Archives are never served over HTTP.
 *
 * @category Backup
 * @package  OCA\Keepiq\Backup
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

namespace OCA\Keepiq\Backup;

use InvalidArgumentException;
use OCA\Keepiq\AppInfo\Application;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IConfig;
use OCP\ITempManager;

/**
 * Archive and blob storage plus scratch files.
 */
class ArchiveStore {
	/**
	 * The app data folder holding the archives.
	 */
	public const FOLDER = 'backups';

	/**
	 * Constructor.
	 *
	 * @param IAppDataFactory $appDataFactory App data storage
	 * @param IConfig $config Data directory and instance id
	 * @param ITempManager $tempManager Scratch files
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private IAppDataFactory $appDataFactory,
		private IConfig $config,
		private ITempManager $tempManager,
	) {
	}//end __construct()

	/**
	 * The archive folder, created on first use.
	 *
	 * @return ISimpleFolder
	 *
	 * @spec openspec/specs/vault-backups/spec.md#requirement-administrator-schedules-vault-backups
	 */
	public function archives(): ISimpleFolder {
		return $this->folder(name: self::FOLDER);
	}//end archives()

	/**
	 * The attachment blob folder, created on first use.
	 *
	 * @return ISimpleFolder
	 *
	 * @spec openspec/specs/vault-backups/spec.md#requirement-archives-are-verified-and-restored-from-the-command-line
	 */
	public function blobs(): ISimpleFolder {
		return $this->folder(name: ArchiveWriter::BLOB_FOLDER);
	}//end blobs()

	/**
	 * A new temporary file path.
	 *
	 * @param string $suffix The file suffix
	 *
	 * @return string
	 *
	 * @spec openspec/specs/vault-backups/spec.md#requirement-administrator-schedules-vault-backups
	 */
	public function tempFile(string $suffix): string {
		return (string)$this->tempManager->getTemporaryFile($suffix);
	}//end tempFile()

	/**
	 * A new temporary directory.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/vault-backups/spec.md#requirement-administrator-schedules-vault-backups
	 */
	public function tempFolder(): string {
		return (string)$this->tempManager->getTemporaryFolder('keepiq-backup');
	}//end tempFolder()

	/**
	 * A local file for an archive given by stored name or by path on disk.
	 *
	 * @param string $nameOrPath An archive name from the list, or a file path
	 *
	 * @return string A readable local path
	 *
	 * @throws InvalidArgumentException When no such archive exists
	 *
	 * @spec openspec/specs/vault-backups/spec.md#requirement-archives-are-verified-and-restored-from-the-command-line
	 */
	public function localCopy(string $nameOrPath): string {
		if (is_file($nameOrPath) === true) {
			return $nameOrPath;
		}

		try {
			$file = $this->archives()->getFile(basename($nameOrPath));
		} catch (NotFoundException) {
			throw new InvalidArgumentException('No such backup: ' . $nameOrPath);
		}

		$local = $this->tempFile(suffix: '.keepiq-backup');
		file_put_contents($local, $file->getContent());

		return $local;
	}//end localCopy()

	/**
	 * Where Nextcloud keeps an archive on disk, for copying off-site.
	 *
	 * @param string $name The archive name
	 *
	 * @return string
	 *
	 * @spec openspec/specs/vault-backups/spec.md#requirement-archives-are-verified-and-restored-from-the-command-line
	 */
	public function diskPath(string $name): string {
		$dataDir = rtrim((string)$this->config->getSystemValue('datadirectory', ''), '/');
		$instanceId = (string)$this->config->getSystemValue('instanceid', '');

		return $dataDir . '/appdata_' . $instanceId . '/' . Application::APP_ID . '/' . self::FOLDER . '/' . $name;
	}//end diskPath()

	/**
	 * An app data folder, created when absent.
	 *
	 * @param string $name The folder name
	 *
	 * @return ISimpleFolder
	 */
	private function folder(string $name): ISimpleFolder {
		$appData = $this->appDataFactory->get(Application::APP_ID);
		try {
			return $appData->getFolder($name);
		} catch (NotFoundException) {
			return $appData->newFolder($name);
		}
	}//end folder()
}//end class
