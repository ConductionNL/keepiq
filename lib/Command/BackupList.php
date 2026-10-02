<?php

/**
 * Keepiq occ command: keepiq:backup:list
 *
 * Lists the stored vault backups with size, time, encryption and the path on disk, for copying off-site (admin-scheduled-vault-backups D4).
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
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ keepiq:backup:list
 */
class BackupList extends Command {
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
		$this->setName(name: 'keepiq:backup:list')
			->setDescription(description: 'List the stored Keepiq vault backups');
	}//end configure()

	/**
	 * Print the archive table.
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
		$rows = [];
		foreach ($this->backups->listArchives() as $archive) {
			$encrypted = 'no';
			if ($archive['encrypted'] === true) {
				$encrypted = 'yes';
			}

			$rows[] = [
				$archive['name'],
				(string)$archive['size'],
				date('Y-m-d H:i:s', $archive['createdAt']),
				$encrypted,
				$archive['path'],
			];
		}

		(new Table($output))->setHeaders(['Name', 'Bytes', 'Time', 'Encrypted', 'Path'])->setRows($rows)->render();

		return 0;
	}//end execute()
}//end class
