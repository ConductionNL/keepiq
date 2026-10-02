<?php

/**
 * Keepiq Scheduled Vault Backup Job
 *
 * Wakes hourly and writes a vault backup when one is due: backups are on and
 * the administrator's interval has passed since the last run, or an
 * administrator asked for one with "Back up now" (admin-scheduled-vault-
 * backups D3). Time-insensitive, so Nextcloud may run it in a quiet window.
 *
 * @category BackgroundJob
 * @package  OCA\Keepiq\BackgroundJob
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

namespace OCA\Keepiq\BackgroundJob;

use OCA\Keepiq\Backup\BackupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Throwable;

/**
 * Hourly check that runs a vault backup when due.
 */
class ScheduledVaultBackupJob extends TimedJob {
	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The time factory
	 * @param BackupService $backups The backup service
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		ITimeFactory $time,
		private BackupService $backups,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 3600);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
	}//end __construct()

	/**
	 * Back up when due. A failure is recorded, audited and logged by the
	 * service; it never breaks the cron run.
	 *
	 * @param mixed $argument The job argument (unused)
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is mandated by TimedJob::run().
	 *
	 * @spec openspec/changes/admin-scheduled-vault-backups/tasks.md#2.2
	 */
	protected function run($argument): void {
		if ($this->backups->isDue() === false) {
			return;
		}

		try {
			$this->backups->createBackup();
		} catch (Throwable) {
			// Recorded as the last status and as a BACKUP_FAILED audit entry.
			return;
		}
	}//end run()
}//end class
