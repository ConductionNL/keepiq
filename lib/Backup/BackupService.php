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

use InvalidArgumentException;
use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Service\AuditService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\ITempManager;
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
	public const FOLDER = 'backups';

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
	 * @param IAppDataFactory $appDataFactory Archive storage
	 * @param IAppConfig $appConfig Last-run status
	 * @param IConfig $config Data directory and instance id, for the disk path
	 * @param ITempManager $tempManager Scratch files
	 * @param ITimeFactory $time The clock
	 * @param AuditService $audit The audit trail
	 * @param LoggerInterface $logger The logger
	 * @param AuditEventFactory $auditEvents The audit event factory
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private ArchiveWriter $writer,
		private ArchiveCipher $cipher,
		private BackupSettings $settings,
		private IAppDataFactory $appDataFactory,
		private IAppConfig $appConfig,
		private IConfig $config,
		private ITempManager $tempManager,
		private ITimeFactory $time,
		private AuditService $audit,
		private LoggerInterface $logger,
		private AuditEventFactory $auditEvents = new AuditEventFactory(),
	) {
	}//end __construct()

	/**
	 * Whether the scheduled job should run a backup now.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/admin-scheduled-vault-backups/tasks.md#2.2
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
	 * @spec openspec/changes/admin-scheduled-vault-backups/tasks.md#4.1
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
	 * @spec openspec/changes/admin-scheduled-vault-backups/tasks.md#2.2
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
	 * @spec openspec/changes/admin-scheduled-vault-backups/tasks.md#3.1
	 */
	public function listArchives(): array {
		$archives = [];
		foreach ($this->folder()->getDirectoryListing() as $file) {
			$archives[] = [
				'name' => $file->getName(),
				'size' => $file->getSize(),
				'createdAt' => $file->getMTime(),
				'encrypted' => str_ends_with($file->getName(), '.enc'),
				'path' => $this->diskPath(name: $file->getName()),
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
	 * @spec openspec/changes/admin-scheduled-vault-backups/tasks.md#4.1
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
	 * @throws InvalidArgumentException When no such archive exists
	 *
	 * @spec openspec/changes/admin-scheduled-vault-backups/tasks.md#3.2
	 */
	public function localCopy(string $nameOrPath): string {
		if (is_file($nameOrPath) === true) {
			return $nameOrPath;
		}

		try {
			$file = $this->folder()->getFile(basename($nameOrPath));
		} catch (NotFoundException) {
			throw new InvalidArgumentException('No such backup: ' . $nameOrPath);
		}

		$local = (string)$this->tempManager->getTemporaryFile('.keepiq-backup');
		file_put_contents($local, $file->getContent());

		return $local;
	}//end localCopy()

	/**
	 * Remove the oldest archives beyond the retention count.
	 *
	 * @param int $keep How many to keep
	 *
	 * @return int How many were removed
	 *
	 * @spec openspec/changes/admin-scheduled-vault-backups/tasks.md#2.2
	 */
	public function prune(int $keep): int {
		$files = $this->folder()->getDirectoryListing();
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
		$workDir = (string)$this->tempManager->getTemporaryFolder('keepiq-backup');
		$zipPath = (string)$this->tempManager->getTemporaryFile('.zip');
		$this->writer->write(zipPath: $zipPath, workDir: $workDir);

		$publicKey = $this->settings->read()[BackupSettings::PUBLIC_KEY];
		$encrypted = ($publicKey !== '');
		$finalPath = $zipPath;
		if ($encrypted === true) {
			$finalPath = (string)$this->tempManager->getTemporaryFile('.enc');
			$this->cipher->encryptFile(plainPath: $zipPath, outPath: $finalPath, publicPem: $publicKey);
		}

		$name = 'keepiq-backup-' . date('Ymd-His', $this->time->getTime()) . ($encrypted === true ? '.zip.enc' : '.zip');
		$handle = fopen($finalPath, 'rb');
		if ($handle === false) {
			throw new RuntimeException('Cannot read the written archive');
		}

		$this->folder()->newFile($name)->putContent($handle);
		$size = (int)filesize($finalPath);

		return ['name' => $name, 'encrypted' => $encrypted, 'size' => $size];
	}//end writeArchive()

	/**
	 * The archive folder, created on first use.
	 *
	 * @return ISimpleFolder
	 */
	private function folder(): ISimpleFolder {
		$appData = $this->appDataFactory->get(Application::APP_ID);
		try {
			return $appData->getFolder(self::FOLDER);
		} catch (NotFoundException) {
			return $appData->newFolder(self::FOLDER);
		}
	}//end folder()

	/**
	 * Where Nextcloud keeps an archive on disk, for copying off-site.
	 *
	 * @param string $name The archive name
	 *
	 * @return string
	 */
	private function diskPath(string $name): string {
		$dataDir = rtrim((string)$this->config->getSystemValue('datadirectory', ''), '/');
		$instanceId = (string)$this->config->getSystemValue('instanceid', '');

		return $dataDir . '/appdata_' . $instanceId . '/' . Application::APP_ID . '/' . self::FOLDER . '/' . $name;
	}//end diskPath()

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
				$this->auditEvents->forSystem(
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
