<?php

/**
 * Keepiq Backup Archive Writer
 *
 * Writes one vault backup archive (admin-scheduled-vault-backups D1): a zip
 * with `tables/<table>.jsonl` (one stored row per line, written as rows are
 * read), `blobs/<blob ref>` (attachment ciphertext as stored) and a
 * `manifest.json` with the format, app version, schema fingerprint,
 * creation time, instance id, and per file the row count and SHA-256.
 *
 * Rows are copied exactly as stored: secret values stay RSA ciphertext,
 * private keys stay wrapped with the master password. No plaintext exists on
 * the server to write.
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

use DateTime;
use OCA\Keepiq\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use RuntimeException;
use ZipArchive;

/**
 * Writes a backup archive from the live tables and blobs.
 */
class ArchiveWriter {
	/**
	 * The archive format name in the manifest.
	 */
	public const FORMAT = 'keepiq-vault-backup-v1';

	/**
	 * The attachment blob folder in the app data (AttachmentService::BLOB_FOLDER).
	 */
	public const BLOB_FOLDER = 'attachments';

	/**
	 * Constructor.
	 *
	 * @param TableStore $tables The table rows
	 * @param IAppDataFactory $appDataFactory Attachment blob storage
	 * @param IAppManager $appManager The app version
	 * @param IConfig $config The instance id
	 * @param SchemaFingerprint $fingerprint The installed schema fingerprint
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private TableStore $tables,
		private IAppDataFactory $appDataFactory,
		private IAppManager $appManager,
		private IConfig $config,
		private SchemaFingerprint $fingerprint,
	) {
	}//end __construct()

	/**
	 * Write a complete archive to a local zip path.
	 *
	 * @param string $zipPath Where to write the zip
	 * @param string $workDir A scratch directory for the table files
	 *
	 * @return array<string,mixed> The manifest
	 *
	 * @throws RuntimeException When the zip cannot be written
	 *
	 * @spec openspec/changes/admin-scheduled-vault-backups/tasks.md#1.2
	 */
	public function write(string $zipPath, string $workDir): array {
		$zip = new ZipArchive();
		if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
			throw new RuntimeException('Cannot create the backup archive');
		}

		$files = [];
		foreach (BackupTableRegistry::TABLES as $table) {
			$local = $workDir . '/' . $table . '.jsonl';
			$rows = $this->dumpTable(table: $table, localPath: $local);
			$zip->addFile($local, 'tables/' . $table . '.jsonl');
			$files['tables/' . $table . '.jsonl'] = ['rows' => $rows, 'sha256' => (string)hash_file('sha256', $local)];
		}

		foreach ($this->blobs() as $name => $content) {
			$zip->addFromString('blobs/' . $name, $content);
			$files['blobs/' . $name] = ['rows' => 1, 'sha256' => hash('sha256', $content)];
		}

		$manifest = [
			'format' => self::FORMAT,
			'appVersion' => $this->appManager->getAppVersion(Application::APP_ID),
			'schemaFingerprint' => $this->fingerprint->current(),
			'createdAt' => (new DateTime())->format('c'),
			'instanceId' => (string)$this->config->getSystemValue('instanceid', ''),
			'files' => $files,
		];
		$zip->addFromString('manifest.json', (string)json_encode($manifest, JSON_PRETTY_PRINT));

		if ($zip->close() !== true) {
			throw new RuntimeException('Cannot finish the backup archive');
		}

		return $manifest;
	}//end write()

	/**
	 * Stream one table into a JSON lines file.
	 *
	 * @param string $table The unprefixed table
	 * @param string $localPath The file to write
	 *
	 * @return int The row count
	 *
	 * @spec openspec/changes/admin-scheduled-vault-backups/tasks.md#1.2
	 */
	private function dumpTable(string $table, string $localPath): int {
		$handle = fopen($localPath, 'wb');
		if ($handle === false) {
			throw new RuntimeException('Cannot write ' . $localPath);
		}

		$rows = 0;
		foreach ($this->tables->rows(table: $table) as $row) {
			fwrite($handle, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
			$rows++;
		}

		fclose($handle);

		return $rows;
	}//end dumpTable()

	/**
	 * Every attachment blob, by blob ref.
	 *
	 * @return iterable<string,string>
	 */
	private function blobs(): iterable {
		try {
			$folder = $this->appDataFactory->get(Application::APP_ID)->getFolder(self::BLOB_FOLDER);
		} catch (NotFoundException) {
			return;
		}

		foreach ($folder->getDirectoryListing() as $file) {
			yield $file->getName() => $file->getContent();
		}
	}//end blobs()
}//end class
