<?php

/**
 * Keepiq Restore Service
 *
 * Restores a vault backup (admin-scheduled-vault-backups D4 and D5). A
 * restore switches Nextcloud maintenance mode on for itself and always off
 * again, refuses when it is already on, decrypts with the administrator's
 * key file when the archive is encrypted, verifies every checksum, refuses
 * an archive from a different schema, and refuses an archive older than the
 * newest audit entry unless forced or a dry run. It then replaces every Keepiq table in
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
	 * Why a restore refuses to start while maintenance mode is on.
	 */
	public const MAINTENANCE_ALREADY_ON = 'Maintenance mode is already on. The restore switches it on and off by itself, '
		. 'and switching it off at the end would cut short maintenance someone else started. '
		. 'Run occ maintenance:mode --off when that work is done, then restore again.';

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
	 * @param bool $dryRun Whether this is a dry run, which skips the age rule
	 *
	 * @return string[]
	 *
	 * @spec openspec/changes/admin-scheduled-vault-backups/tasks.md#3.3
	 * @spec openspec/changes/admin-scheduled-vault-backups/tasks.md#4.2
	 */
	public function refusals(array $manifest, bool $force, bool $dryRun=false): array {
		$refusals = [];
		if ($this->maintenanceIsOn() === true) {
			$refusals[] = self::MAINTENANCE_ALREADY_ON;
		}

		if (($manifest['schemaFingerprint'] ?? '') !== $this->fingerprint->current()) {
			$refusals[] = 'The archive was written by a different Keepiq schema. Restore it on the same Keepiq version.';
		}

		if ($force === false && $dryRun === false && $this->isOlderThanNewestAuditEntry(manifest: $manifest) === true) {
			$refusals[] = 'The archive is older than the newest audit entry. Pass --force to roll back on purpose.';
		}

		return $refusals;
	}//end refusals()

	/**
	 * Whether the archive was written before the newest audit entry, so a
	 * restore would roll back later changes.
	 *
	 * @param array<string,mixed> $manifest The verified manifest
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/admin-scheduled-vault-backups/tasks.md#4.2
	 */
	public function isOlderThanNewestAuditEntry(array $manifest): bool {
		$newest = $this->tables->newestAuditEntry();
		$createdAt = (string)($manifest['createdAt'] ?? '');
		if ($newest === null || $createdAt === '') {
			return false;
		}

		return new DateTime($createdAt) < new DateTime($newest);
	}//end isOlderThanNewestAuditEntry()

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
	 * Switch maintenance mode on, replace every Keepiq table, then the
	 * attachment blobs, audit, and always switch maintenance mode off again.
	 *
	 * Nextcloud loads no app commands while maintenance mode is on, so the
	 * restore command cannot ask the administrator to switch it on first.
	 *
	 * @param string $zip The verified archive
	 * @param array<string,mixed> $manifest Its manifest
	 * @param string $archiveName The name for the audit entry
	 *
	 * @return array{rows:int,blobs:int}
	 *
	 * @throws InvalidArgumentException When maintenance mode is already on
	 *
	 * @spec openspec/changes/admin-scheduled-vault-backups/tasks.md#3.3
	 * @spec openspec/changes/admin-scheduled-vault-backups/tasks.md#4.2
	 */
	public function restore(string $zip, array $manifest, string $archiveName): array {
		if ($this->maintenanceIsOn() === true) {
			throw new InvalidArgumentException(self::MAINTENANCE_ALREADY_ON);
		}

		$this->config->setSystemValue('maintenance', true);
		try {
			return $this->replace(zip: $zip, manifest: $manifest, archiveName: $archiveName);
		} finally {
			$this->config->setSystemValue('maintenance', false);
		}
	}//end restore()

	/**
	 * Whether Nextcloud maintenance mode is on.
	 *
	 * @return bool
	 */
	private function maintenanceIsOn(): bool {
		return (bool)$this->config->getSystemValue('maintenance', false) === true;
	}//end maintenanceIsOn()

	/**
	 * Replace every Keepiq table, then the attachment blobs, and audit.
	 *
	 * @param string $zip The verified archive
	 * @param array<string,mixed> $manifest Its manifest
	 * @param string $archiveName The name for the audit entry
	 *
	 * @return array{rows:int,blobs:int}
	 */
	private function replace(string $zip, array $manifest, string $archiveName): array {
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
	}//end replace()

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
