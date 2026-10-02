<?php

/**
 * Keepiq Restore Service
 *
 * Restores a vault backup (admin-scheduled-vault-backups D4 and D5). A
 * restore refuses outside maintenance mode, decrypts with the administrator's
 * key file when the archive is encrypted, verifies every checksum, refuses
 * an archive from a different schema, and refuses an archive older than the
 * newest audit entry unless forced. It then replaces every Keepiq table in
 * one database transaction and only after that the attachment blobs.
 *
 * A restore gives back ciphertext as it was: every user unlocks with the
 * master password valid at backup time. Nothing here asks for, derives or
 * stores a master password or a user private key.
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
use InvalidArgumentException;
use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Service\AuditService;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Security\ICrypto;
use Throwable;

/**
 * Plans, checks and runs a restore.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A restore joins the archive,
 *   database, blob, crypto, user and audit sides by nature.
 */
class RestoreService {
	/**
	 * Constructor.
	 *
	 * @param ArchiveReader $reader Verifies and reads the archive
	 * @param ArchiveCipher $cipher Decrypts an encrypted archive
	 * @param TableStore $tables Counts and replaces table rows
	 * @param SchemaFingerprint $fingerprint The installed schema
	 * @param ArchiveStore $store Blob storage and scratch files
	 * @param IConfig $config Maintenance mode
	 * @param IUserManager $userManager Owners that no longer exist
	 * @param ICrypto $crypto The instance secret probe
	 * @param AuditService $audit The audit trail
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private ArchiveReader $reader,
		private ArchiveCipher $cipher,
		private TableStore $tables,
		private SchemaFingerprint $fingerprint,
		private ArchiveStore $store,
		private IConfig $config,
		private IUserManager $userManager,
		private ICrypto $crypto,
		private AuditService $audit,
	) {
	}//end __construct()

	/**
	 * Open an archive: decrypt when needed, then verify every checksum.
	 *
	 * @param string $localPath The archive file
	 * @param string|null $keyFile The private key file for an encrypted archive
	 *
	 * @return array{zip:string,manifest:array<string,mixed>}
	 *
	 * @throws InvalidArgumentException When it is encrypted without a key, the key is wrong, or a check fails
	 *
	 * @spec openspec/changes/admin-scheduled-vault-backups/tasks.md#3.2
	 */
	public function open(string $localPath, ?string $keyFile): array {
		$zip = $localPath;
		if ($this->cipher->isEncrypted(path: $localPath) === true) {
			if ($keyFile === null || $keyFile === '') {
				throw new InvalidArgumentException('The archive is encrypted. Pass the private key with --key-file.');
			}

			if (is_readable($keyFile) === false) {
				throw new InvalidArgumentException('Cannot read the key file ' . $keyFile);
			}

			$zip = $this->store->tempFile(suffix: '.zip');
			$this->cipher->decryptFile(encPath: $localPath, outPath: $zip, privatePem: (string)file_get_contents($keyFile));
		}

		return ['zip' => $zip, 'manifest' => $this->reader->verify(zipPath: $zip)];
	}//end open()

	/**
	 * The checks a restore must pass, as refusal messages; empty means go.
	 *
	 * @param array<string,mixed> $manifest The verified manifest
	 * @param bool $force Whether the administrator forces an older archive
	 *
	 * @return string[]
	 *
	 * @spec openspec/changes/admin-scheduled-vault-backups/tasks.md#3.3
	 */
	public function refusals(array $manifest, bool $force): array {
		$refusals = [];
		if ((bool)$this->config->getSystemValue('maintenance', false) === false) {
			$refusals[] = 'Maintenance mode is off. Run occ maintenance:mode --on first.';
		}

		if (($manifest['schemaFingerprint'] ?? '') !== $this->fingerprint->current()) {
			$refusals[] = 'The archive was written by a different Keepiq schema. Restore it on the same Keepiq version.';
		}

		$newest = $this->tables->newestAuditEntry();
		$createdAt = (string)($manifest['createdAt'] ?? '');
		if ($force === false && $newest !== null && $createdAt !== ''
			&& new DateTime($createdAt) < new DateTime($newest)
		) {
			$refusals[] = 'The archive is older than the newest audit entry. Pass --force to roll back on purpose.';
		}

		return $refusals;
	}//end refusals()

	/**
	 * Per table: rows now, and rows in the archive.
	 *
	 * @param array<string,mixed> $manifest The verified manifest
	 *
	 * @return array<string,array{current:int,archive:int}>
	 *
	 * @spec openspec/changes/admin-scheduled-vault-backups/tasks.md#3.3
	 */
	public function compare(array $manifest): array {
		$counts = [];
		foreach (BackupTableRegistry::TABLES as $table) {
			$counts[$table] = [
				'current' => $this->tables->count(table: $table),
				'archive' => (int)($manifest['files']['tables/' . $table . '.jsonl']['rows'] ?? 0),
			];
		}

		return $counts;
	}//end compare()

	/**
	 * What the administrator must know before confirming (design D5).
	 *
	 * @param string $zip The verified archive
	 * @param array<string,mixed> $manifest Its manifest
	 *
	 * @return string[]
	 *
	 * @spec openspec/changes/admin-scheduled-vault-backups/tasks.md#3.4
	 */
	public function warnings(string $zip, array $manifest): array {
		$warnings = [
			'Every change made after ' . ($manifest['createdAt'] ?? 'the backup') . ' is lost, including key rotations.',
			'Every user unlocks with the master password that was valid when the backup ran.',
		];

		if ($this->instanceSecretOpensCaKeys(zip: $zip) === false) {
			$warnings[] = 'The CA keys in this archive cannot be read with this instance\'s secret. '
				. 'Restore on the instance that wrote it, or with the same secret in config.php.';
		}

		$missing = $this->missingOwners(zip: $zip);
		if ($missing !== []) {
			$warnings[] = 'These owners no longer exist in Nextcloud; their rows are restored anyway: '
				. implode(', ', $missing);
		}

		return $warnings;
	}//end warnings()

	/**
	 * Replace every Keepiq table, then the attachment blobs, and audit.
	 *
	 * @param string $zip The verified archive
	 * @param array<string,mixed> $manifest Its manifest
	 * @param string $archiveName The name for the audit entry
	 *
	 * @return array{rows:int,blobs:int}
	 *
	 * @spec openspec/changes/admin-scheduled-vault-backups/tasks.md#3.3
	 */
	public function restore(string $zip, array $manifest, string $archiveName): array {
		$written = $this->tables->replaceAll(
			rowsFor: fn (string $table): iterable => $this->reader->rows(zipPath: $zip, table: $table)
		);

		$folder = $this->store->blobs();
		foreach ($folder->getDirectoryListing() as $file) {
			$file->delete();
		}

		$blobs = 0;
		foreach ($this->reader->blobs(zipPath: $zip) as $name => $content) {
			$folder->newFile((string)$name)->putContent($content);
			$blobs++;
		}

		$rows = array_sum($written);
		$this->audit->record(
			(new AuditEventFactory())->forSystem(
				eventType: AuditEventTypes::BACKUP_RESTORED,
				objectType: 'backup',
				objectId: $archiveName,
				objectName: $archiveName,
				metadata: [
					'archive' => $archiveName,
					'createdAt' => (string)($manifest['createdAt'] ?? ''),
					'tables' => count($written),
					'rows' => $rows,
					'blobs' => $blobs,
				],
			)
		);

		return ['rows' => $rows, 'blobs' => $blobs];
	}//end restore()

	/**
	 * Whether this instance's secret opens the first CA key in the archive.
	 *
	 * @param string $zip The archive
	 *
	 * @return bool True when it opens, or when there is no CA key to try
	 */
	private function instanceSecretOpensCaKeys(string $zip): bool {
		foreach ($this->reader->rows(zipPath: $zip, table: 'ca_certs') as $row) {
			$encrypted = (string)($row['private_key'] ?? '');
			if ($encrypted === '') {
				continue;
			}

			try {
				$this->crypto->decrypt($encrypted);
				return true;
			} catch (Throwable) {
				return false;
			}
		}

		return true;
	}//end instanceSecretOpensCaKeys()

	/**
	 * User owners in the archive that Nextcloud no longer knows.
	 *
	 * @param string $zip The archive
	 *
	 * @return string[]
	 */
	private function missingOwners(string $zip): array {
		$owners = [];
		foreach (['secrets', 'enc_suites'] as $table) {
			foreach ($this->reader->rows(zipPath: $zip, table: $table) as $row) {
				if (($row['owner_type'] ?? '') === 'user') {
					$owners[(string)$row['owner_id']] = true;
				}
			}
		}

		$missing = [];
		foreach (array_keys($owners) as $owner) {
			if ($this->userManager->userExists((string)$owner) === false) {
				$missing[] = (string)$owner;
			}
		}

		sort($missing);

		return $missing;
	}//end missingOwners()
}//end class
