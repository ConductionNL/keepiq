<?php

/**
 * Keepiq Backup Service
 *
 * Runs a vault backup and keeps the archives (admin-scheduled-vault-backups
 * D1 to D3): writes the archive to a temporary file, encrypts it when a
 * recipient key is set, stores it in the app data folder `backups`, removes
 * the oldest archives beyond the retention count after a successful run,
 * records the last result in app config and audits every run.
 *
 * Archives are never served over HTTP; the list gives names, sizes, times and
 * the path on disk for the administrator's own backup tooling.
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

use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Service\AuditService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Creates, lists and prunes vault backup archives.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The service joins the
 *   archive, storage, settings and audit sides of one backup run.
 */
class BackupService {
	/**
	 * The app data folder holding the archives.
	 */
	public const FOLDER = ArchiveStore::FOLDER;

	public const LAST_RUN = 'backup_last_run_at';
	public const LAST_STATUS = 'backup_last_status';
	public const LAST_ERROR = 'backup_last_error';
	public const RUN_REQUESTED = 'backup_run_requested';

	/**
	 * Constructor.
	 *
	 * @param ArchiveWriter $writer Writes the archive
	 * @param ArchiveCipher $cipher Encrypts it to the recipient key
	 * @param BackupSettings $settings The schedule, retention and key
	 * @param ArchiveStore $store Archive storage and scratch files
	 * @param IAppConfig $appConfig Last-run status
	 * @param ITimeFactory $time The clock
	 * @param AuditService $audit The audit trail
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private ArchiveWriter $writer,
		private ArchiveCipher $cipher,
		private BackupSettings $settings,
		private ArchiveStore $store,
		private IAppConfig $appConfig,
		private ITimeFactory $time,
		private AuditService $audit,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether the scheduled job should run a backup now.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/vault-backups/spec.md#requirement-administrator-schedules-vault-backups
	 */
	public function isDue(): bool {
		$settings = $this->settings->read();
		if ($settings[BackupSettings::ENABLED] === false) {
			return false;
		}

		if ($this->appConfig->getValueBool(Application::APP_ID, self::RUN_REQUESTED, false) === true) {
			return true;
		}

		$lastRun = $this->appConfig->getValueInt(Application::APP_ID, self::LAST_RUN, 0);

		return ($this->time->getTime() - $lastRun) >= ($settings[BackupSettings::INTERVAL] * 3600);
	}//end isDue()

	/**
	 * Ask the next cron run to back up, whatever the interval says.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/vault-backups/spec.md#requirement-administrator-schedules-vault-backups
	 */
	public function requestRun(): void {
		$this->appConfig->setValueBool(Application::APP_ID, self::RUN_REQUESTED, true);
	}//end requestRun()

	/**
	 * Run one backup now.
	 *
	 * @return array{name:string,encrypted:bool,size:int}
	 *
	 * @throws Throwable When the backup failed (recorded and audited first)
	 *
	 * @spec openspec/specs/vault-backups/spec.md#requirement-administrator-schedules-vault-backups
	 */
	public function createBackup(): array {
		$this->appConfig->setValueBool(Application::APP_ID, self::RUN_REQUESTED, false);
		$this->appConfig->setValueInt(Application::APP_ID, self::LAST_RUN, $this->time->getTime());

		try {
			$result = $this->writeArchive();
		} catch (Throwable $exception) {
			$this->appConfig->setValueString(Application::APP_ID, self::LAST_STATUS, 'failed');
			$this->appConfig->setValueString(Application::APP_ID, self::LAST_ERROR, substr($exception->getMessage(), 0, 500));
			$this->record(eventType: AuditEventTypes::BACKUP_FAILED, name: '', metadata: ['error' => substr($exception->getMessage(), 0, 200)]);
			$this->logger->error('Keepiq vault backup failed: ' . $exception->getMessage(), ['exception' => $exception]);
			throw $exception;
		}

		$this->appConfig->setValueString(Application::APP_ID, self::LAST_STATUS, 'ok');
		$this->appConfig->setValueString(Application::APP_ID, self::LAST_ERROR, '');
		$this->record(
			eventType: AuditEventTypes::BACKUP_CREATED,
			name: $result['name'],
			metadata: ['archive' => $result['name'], 'encrypted' => $result['encrypted'], 'bytes' => $result['size']]
		);
		$this->prune(keep: $this->settings->read()[BackupSettings::RETENTION]);

		return $result;
	}//end createBackup()

	/**
	 * The stored archives, newest first. Never their content.
	 *
	 * @return array<int,array{name:string,size:int,createdAt:int,encrypted:bool,path:string}>
	 *
	 * @spec openspec/specs/vault-backups/spec.md#requirement-archives-are-verified-and-restored-from-the-command-line
	 */
	public function listArchives(): array {
		$archives = [];
		foreach ($this->store->archives()->getDirectoryListing() as $file) {
			$archives[] = [
				'name' => $file->getName(),
				'size' => $file->getSize(),
				'createdAt' => $file->getMTime(),
				'encrypted' => str_ends_with($file->getName(), '.enc'),
				'path' => $this->store->diskPath(name: $file->getName()),
			];
		}

		usort($archives, static fn (array $a, array $b): int => strcmp($b['name'], $a['name']));

		return $archives;
	}//end listArchives()

	/**
	 * The last run, for the admin section.
	 *
	 * @return array{lastRunAt:int,lastStatus:string,lastError:string,runRequested:bool}
	 *
	 * @spec openspec/specs/vault-backups/spec.md#requirement-administrator-schedules-vault-backups
	 */
	public function status(): array {
		$appId = Application::APP_ID;

		return [
			'lastRunAt' => $this->appConfig->getValueInt($appId, self::LAST_RUN, 0),
			'lastStatus' => $this->appConfig->getValueString($appId, self::LAST_STATUS, ''),
			'lastError' => $this->appConfig->getValueString($appId, self::LAST_ERROR, ''),
			'runRequested' => $this->appConfig->getValueBool($appId, self::RUN_REQUESTED, false),
		];
	}//end status()

	/**
	 * A local file for an archive given by stored name or by path on disk.
	 *
	 * @param string $nameOrPath An archive name from the list, or a file path
	 *
	 * @return string A readable local path
	 *
	 * @throws \InvalidArgumentException When no such archive exists
	 *
	 * @spec openspec/specs/vault-backups/spec.md#requirement-archives-are-verified-and-restored-from-the-command-line
	 */
	public function localCopy(string $nameOrPath): string {
		return $this->store->localCopy(nameOrPath: $nameOrPath);
	}//end localCopy()

	/**
	 * Remove the oldest archives beyond the retention count.
	 *
	 * @param int $keep How many to keep
	 *
	 * @return int How many were removed
	 *
	 * @spec openspec/specs/vault-backups/spec.md#requirement-administrator-schedules-vault-backups
	 */
	public function prune(int $keep): int {
		$files = $this->store->archives()->getDirectoryListing();
		usort($files, static fn ($a, $b): int => strcmp($b->getName(), $a->getName()));
		$removed = 0;
		foreach (array_slice($files, max(1, $keep)) as $file) {
			$file->delete();
			$removed++;
		}

		return $removed;
	}//end prune()

	/**
	 * Write, optionally encrypt, and store one archive.
	 *
	 * @return array{name:string,encrypted:bool,size:int}
	 */
	private function writeArchive(): array {
		$workDir = $this->store->tempFolder();
		$zipPath = $this->store->tempFile(suffix: '.zip');
		$this->writer->write(zipPath: $zipPath, workDir: $workDir);

		$publicKey = $this->settings->read()[BackupSettings::PUBLIC_KEY];
		$encrypted = ($publicKey !== '');
		$finalPath = $zipPath;
		if ($encrypted === true) {
			$finalPath = $this->store->tempFile(suffix: '.enc');
			$this->cipher->encryptFile(plainPath: $zipPath, outPath: $finalPath, publicPem: $publicKey);
		}

		$name = 'keepiq-backup-' . date('Ymd-His', $this->time->getTime()) . '.zip';
		if ($encrypted === true) {
			$name .= '.enc';
		}

		$handle = fopen($finalPath, 'rb');
		if ($handle === false) {
			throw new RuntimeException('Cannot read the written archive');
		}

		$this->store->archives()->newFile($name)->putContent($handle);
		$size = (int)filesize($finalPath);

		return ['name' => $name, 'encrypted' => $encrypted, 'size' => $size];
	}//end writeArchive()

	/**
	 * Record a backup audit event as the system.
	 *
	 * @param string $eventType The event type
	 * @param string $name The archive name
	 * @param array<string,mixed> $metadata Counts, names and flags only
	 *
	 * @return void
	 */
	private function record(string $eventType, string $name, array $metadata): void {
		try {
			$this->audit->record(
				(new AuditEventFactory())->forSystem(
					eventType: $eventType,
					objectType: 'backup',
					objectId: $name,
					objectName: $name,
					metadata: $metadata,
				)
			);
		} catch (Throwable $exception) {
			$this->logger->error('Keepiq: backup audit entry could not be recorded: ' . $exception->getMessage());
		}
	}//end record()
}//end class
