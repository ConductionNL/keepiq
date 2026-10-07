<?php

/**
 * Keepiq Backup Archive Reader
 *
 * Verifies a plaintext backup archive before anything trusts it: the
 * manifest names the expected format, every Keepiq table is present, and
 * every file matches its recorded SHA-256 (admin-scheduled-vault-backups D4).
 * Then it streams rows and blobs back for a restore.
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
use ZipArchive;

/**
 * Reads and verifies backup archives.
 */
class ArchiveReader {
	/**
	 * Verify an archive and return its manifest.
	 *
	 * @param string $zipPath The plaintext zip
	 *
	 * @return array<string,mixed> The manifest
	 *
	 * @throws InvalidArgumentException When the archive is not a complete, unchanged backup
	 *
	 * @spec openspec/specs/vault-backups/spec.md#requirement-archives-are-verified-and-restored-from-the-command-line
	 */
	public function verify(string $zipPath): array {
		$zip = $this->open(zipPath: $zipPath);
		$manifest = json_decode((string)$zip->getFromName('manifest.json'), true);
		if (is_array($manifest) === false || ($manifest['format'] ?? '') !== ArchiveWriter::FORMAT) {
			$zip->close();
			throw new InvalidArgumentException('Not a Keepiq vault backup (no valid manifest)');
		}

		$files = (array)($manifest['files'] ?? []);
		foreach (BackupTableRegistry::TABLES as $table) {
			if (isset($files['tables/' . $table . '.jsonl']) === false) {
				$zip->close();
				throw new InvalidArgumentException('The backup has no data for table ' . $table);
			}
		}

		foreach ($files as $name => $meta) {
			$content = $zip->getFromName((string)$name);
			if ($content === false || hash('sha256', $content) !== ($meta['sha256'] ?? '')) {
				$zip->close();
				throw new InvalidArgumentException('Checksum mismatch in ' . $name);
			}
		}

		$zip->close();

		return $manifest;
	}//end verify()

	/**
	 * The stored rows of one table.
	 *
	 * @param string $zipPath The verified zip
	 * @param string $table The unprefixed table
	 *
	 * @return iterable<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/vault-backups/spec.md#requirement-archives-are-verified-and-restored-from-the-command-line
	 */
	public function rows(string $zipPath, string $table): iterable {
		$zip = $this->open(zipPath: $zipPath);
		$stream = $zip->getStream('tables/' . $table . '.jsonl');
		if ($stream === false) {
			$zip->close();
			return;
		}

		while (($line = fgets($stream)) !== false) {
			$line = trim($line);
			if ($line !== '') {
				yield (array)json_decode($line, true);
			}
		}

		fclose($stream);
		$zip->close();
	}//end rows()

	/**
	 * The attachment blobs, by blob ref.
	 *
	 * @param string $zipPath The verified zip
	 *
	 * @return iterable<string,string>
	 *
	 * @spec openspec/specs/vault-backups/spec.md#requirement-archives-are-verified-and-restored-from-the-command-line
	 */
	public function blobs(string $zipPath): iterable {
		$zip = $this->open(zipPath: $zipPath);
		for ($index = 0; $index < $zip->numFiles; $index++) {
			$name = (string)$zip->getNameIndex($index);
			if (str_starts_with($name, 'blobs/') === true) {
				yield substr($name, 6) => (string)$zip->getFromIndex($index);
			}
		}

		$zip->close();
	}//end blobs()

	/**
	 * Open a zip or say it is not one.
	 *
	 * @param string $zipPath The zip
	 *
	 * @return ZipArchive
	 *
	 * @throws InvalidArgumentException When it is not a readable zip
	 */
	private function open(string $zipPath): ZipArchive {
		$zip = new ZipArchive();
		if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
			throw new InvalidArgumentException('Not a readable backup archive');
		}

		return $zip;
	}//end open()
}//end class
