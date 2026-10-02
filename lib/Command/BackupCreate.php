<?php

/**
 * Keepiq occ command: keepiq:backup:create
 *
 * Runs a vault backup now, with the same code as the scheduled job (admin-scheduled-vault-backups D4).
 *
 * @category Command
 * @package  OCA\Keepiq\Command
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

namespace OCA\Keepiq\Command;

use OCA\Keepiq\Backup\BackupService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * occ keepiq:backup:create
 */
class BackupCreate extends Command {
	/**
	 * Constructor.
	 *
	 * @param BackupService $backups The backup service
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private BackupService $backups,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Name and help.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-scheduled-vault-backups/tasks.md#3.1
	 */
	protected function configure(): void {
		$this->setName(name: 'keepiq:backup:create')
			->setDescription(description: 'Write a backup of every Keepiq vault now');
	}//end configure()

	/**
	 * Run the backup.
	 *
	 * @param InputInterface $input The input
	 * @param OutputInterface $output The output
	 *
	 * @return int
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $input is mandated by Command::execute().
	 *
	 * @spec openspec/changes/admin-scheduled-vault-backups/tasks.md#3.1
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$result = $this->backups->createBackup();
		} catch (Throwable $exception) {
			$output->writeln('<error>Backup failed: ' . $exception->getMessage() . '</error>');
			return 1;
		}

		$encryption = 'not encrypted';
		if ($result['encrypted'] === true) {
			$encryption = 'encrypted';
		}

		$output->writeln(sprintf('Wrote %s (%d bytes, %s)', $result['name'], $result['size'], $encryption));

		return 0;
	}//end execute()
}//end class
