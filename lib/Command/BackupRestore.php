<?php

/**
 * Keepiq occ command: keepiq:backup:restore
 *
 * Restores a vault backup in maintenance mode, after verification, schema and age checks and a confirmation that names what a restore means (admin-scheduled-vault-backups D4 and D5).
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

use InvalidArgumentException;
use OCA\Keepiq\Backup\BackupService;
use OCA\Keepiq\Backup\RestoreService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * occ keepiq:backup:restore <file> [--key-file=] [--dry-run] [--force]
 */
class BackupRestore extends Command {
	/**
	 * Constructor.
	 *
	 * @param BackupService $backups Finds the archive
	 * @param RestoreService $restore Checks and runs the restore
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private BackupService $backups,
		private RestoreService $restore,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Name, argument and options.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-scheduled-vault-backups/tasks.md#3.3
	 */
	protected function configure(): void {
		$this->setName('keepiq:backup:restore')
			->setDescription('Restore every Keepiq vault from a backup (maintenance mode only)')
			->addArgument('file', InputArgument::REQUIRED, 'An archive name from keepiq:backup:list, or a file path')
			->addOption('key-file', null, InputOption::VALUE_REQUIRED, 'The private key for an encrypted archive')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show current and archive row counts and change nothing')
			->addOption('force', null, InputOption::VALUE_NONE, 'Restore an archive older than the newest audit entry');
	}//end configure()

	/**
	 * Check, show, confirm, restore.
	 *
	 * @param InputInterface $input The input
	 * @param OutputInterface $output The output
	 *
	 * @return int
	 *
	 * @spec openspec/changes/admin-scheduled-vault-backups/tasks.md#3.3
	 * @spec openspec/changes/admin-scheduled-vault-backups/tasks.md#3.4
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$file = (string)$input->getArgument('file');
		try {
			$opened = $this->restore->open(
				localPath: $this->backups->localCopy(nameOrPath: $file),
				keyFile: ($input->getOption('key-file') === null ? null : (string)$input->getOption('key-file'))
			);
		} catch (InvalidArgumentException $exception) {
			$output->writeln('<error>' . $exception->getMessage() . '</error>');
			return 1;
		}

		$refusals = $this->restore->refusals(manifest: $opened['manifest'], force: (bool)$input->getOption('force'));
		if ($refusals !== []) {
			foreach ($refusals as $refusal) {
				$output->writeln('<error>' . $refusal . '</error>');
			}

			return 1;
		}

		$rows = [];
		foreach ($this->restore->compare(manifest: $opened['manifest']) as $table => $counts) {
			$rows[] = [$table, (string)$counts['current'], (string)$counts['archive']];
		}

		(new Table($output))->setHeaders(['Table', 'Rows now', 'Rows in archive'])->setRows($rows)->render();
		if ((bool)$input->getOption('dry-run') === true) {
			$output->writeln('Dry run: nothing changed.');
			return 0;
		}

		foreach ($this->restore->warnings(zip: $opened['zip'], manifest: $opened['manifest']) as $warning) {
			$output->writeln('<comment>' . $warning . '</comment>');
		}

		$helper = $this->getHelper('question');
		if (($helper instanceof QuestionHelper) === false
			|| $helper->ask($input, $output, new ConfirmationQuestion('Replace every Keepiq vault with this backup? [y/N] ', false)) !== true
		) {
			$output->writeln('Restore cancelled. Nothing changed.');
			return 1;
		}

		$result = $this->restore->restore(zip: $opened['zip'], manifest: $opened['manifest'], archiveName: basename($file));
		$output->writeln(sprintf('Restored %d rows and %d attachment blobs.', $result['rows'], $result['blobs']));

		return 0;
	}//end execute()
}//end class
