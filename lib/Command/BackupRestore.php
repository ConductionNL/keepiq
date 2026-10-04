<?php

/**
 * Keepiq occ command: keepiq:backup:restore
 *
 * Restores a vault backup after verification, schema and age checks and a
 * confirmation that names what a restore means. The restore switches
 * maintenance mode on for itself and always off again
 * (admin-scheduled-vault-backups D4 and D5).
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
use Throwable;
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
	 * @spec openspec/specs/vault-backups/spec.md#requirement-archives-are-verified-and-restored-from-the-command-line
	 */
	protected function configure(): void {
		$this->setName(name: 'keepiq:backup:restore')
			->setDescription(description: 'Restore every Keepiq vault from a backup, in maintenance mode it switches on and off itself')
			->addArgument(name: 'file', mode: InputArgument::REQUIRED, description: 'An archive name from keepiq:backup:list, or a file path')
			->addOption(name: 'key-file', mode: InputOption::VALUE_REQUIRED, description: 'The private key for an encrypted archive')
			->addOption(name: 'dry-run', mode: InputOption::VALUE_NONE, description: 'Show current and archive row counts and change nothing')
			->addOption(name: 'force', mode: InputOption::VALUE_NONE, description: 'Restore an archive older than the newest audit entry');
	}//end configure()

	/**
	 * Check, show, confirm, restore.
	 *
	 * @param InputInterface $input The input
	 * @param OutputInterface $output The output
	 *
	 * @return int
	 *
	 * @spec openspec/specs/vault-backups/spec.md#requirement-archives-are-verified-and-restored-from-the-command-line
	 * @spec openspec/specs/vault-backups/spec.md#requirement-a-restore-returns-ciphertext-that-still-needs-each-users-key
	 * @spec openspec/specs/vault-backups/spec.md#requirement-archives-are-verified-and-restored-from-the-command-line
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$file = (string)$input->getArgument('file');
		try {
			$opened = $this->restore->open(
				localPath: $this->backups->localCopy(nameOrPath: $file),
				keyFile: self::keyFile(input: $input)
			);
		} catch (InvalidArgumentException $exception) {
			$output->writeln('<error>' . $exception->getMessage() . '</error>');
			return 1;
		}

		$force    = (bool)$input->getOption('force');
		$dryRun   = (bool)$input->getOption('dry-run');
		$refusals = $this->restore->refusals(manifest: $opened['manifest'], force: $force, dryRun: $dryRun);
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
		if ($dryRun === true) {
			if ($force === false && $this->restore->isOlderThanNewestAuditEntry(manifest: $opened['manifest']) === true) {
				$output->writeln('<comment>The archive is older than the newest audit entry. A real restore needs --force to roll back on purpose.</comment>');
			}

			$output->writeln('Dry run: nothing changed.');
			return 0;
		}

		foreach ($this->restore->warnings(zip: $opened['zip'], manifest: $opened['manifest']) as $warning) {
			$output->writeln('<comment>' . $warning . '</comment>');
		}

		$helper = $this->getHelper(name: 'question');
		if (($helper instanceof QuestionHelper) === false
			|| $helper->ask($input, $output, new ConfirmationQuestion('Replace every Keepiq vault with this backup? [y/N] ', false)) !== true
		) {
			$output->writeln('Restore cancelled. Nothing changed.');
			return 1;
		}

		$output->writeln('Switching maintenance mode on for the restore.');
		try {
			$result = $this->restore->restore(zip: $opened['zip'], manifest: $opened['manifest'], archiveName: basename($file));
		} catch (Throwable $exception) {
			$output->writeln('<error>Restore failed: ' . $exception->getMessage() . '</error>');
			$output->writeln('<error>Maintenance mode is off again.</error>');
			return 1;
		}

		$output->writeln('Maintenance mode is off again.');
		$output->writeln(sprintf('Restored %d rows and %d attachment blobs.', $result['rows'], $result['blobs']));

		return 0;
	}//end execute()

	/**
	 * The --key-file option, or null when not given.
	 *
	 * @param InputInterface $input The input
	 *
	 * @return string|null
	 */
	private static function keyFile(InputInterface $input): ?string {
		$keyFile = $input->getOption('key-file');
		if ($keyFile === null) {
			return null;
		}

		return (string)$keyFile;
	}//end keyFile()
}//end class
