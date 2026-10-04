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

		$force  = (bool)$input->getOption('force');
		$dryRun = (bool)$input->getOption('dry-run');

		// A dry run changes nothing, so the age rule is a notice there, not a refusal.
		$refusals = $this->restore->refusals(manifest: $opened['manifest'], force: ($force === true || $dryRun === true));
		foreach ($refusals as $refusal) {
			$output->writeln('<error>' . $refusal . '</error>');
		}

		if ($refusals !== []) {
			return 1;
		}

		$this->printCounts(manifest: $opened['manifest'], output: $output);
		if ($dryRun === true) {
			return $this->finishDryRun(manifest: $opened['manifest'], force: $force, output: $output);
		}

		foreach ($this->restore->warnings(zip: $opened['zip'], manifest: $opened['manifest']) as $warning) {
			$output->writeln('<comment>' . $warning . '</comment>');
		}

		if ($this->confirmed(input: $input, output: $output) === false) {
			$output->writeln('Restore cancelled. Nothing changed.');
			return 1;
		}

		return $this->runRestore(opened: $opened, archiveName: basename($file), output: $output);
	}//end execute()

	/**
	 * Print the rows per table now and in the archive.
	 *
	 * @param array<string,mixed> $manifest The verified manifest
	 * @param OutputInterface $output The output
	 *
	 * @return void
	 */
	private function printCounts(array $manifest, OutputInterface $output): void {
		$rows = [];
		foreach ($this->restore->compare(manifest: $manifest) as $table => $counts) {
			$rows[] = [$table, (string)$counts['current'], (string)$counts['archive']];
		}

		(new Table($output))->setHeaders(['Table', 'Rows now', 'Rows in archive'])->setRows($rows)->render();
	}//end printCounts()

	/**
	 * End a dry run: name the age rule a real restore would apply.
	 *
	 * @param array<string,mixed> $manifest The verified manifest
	 * @param bool $force Whether --force was given
	 * @param OutputInterface $output The output
	 *
	 * @return int
	 */
	private function finishDryRun(array $manifest, bool $force, OutputInterface $output): int {
		if ($force === false && $this->restore->isOlderThanNewestAuditEntry(manifest: $manifest) === true) {
			$output->writeln('<comment>The archive is older than the newest audit entry. A real restore needs --force to roll back on purpose.</comment>');
		}

		$output->writeln('Dry run: nothing changed.');
		return 0;
	}//end finishDryRun()

	/**
	 * Whether the administrator confirms the restore.
	 *
	 * @param InputInterface $input The input
	 * @param OutputInterface $output The output
	 *
	 * @return bool
	 */
	private function confirmed(InputInterface $input, OutputInterface $output): bool {
		$helper = $this->getHelper(name: 'question');
		if (($helper instanceof QuestionHelper) === false) {
			return false;
		}

		return $helper->ask($input, $output, new ConfirmationQuestion('Replace every Keepiq vault with this backup? [y/N] ', false)) === true;
	}//end confirmed()

	/**
	 * Run the restore, which switches maintenance mode on and always off again.
	 *
	 * @param array{zip:string,manifest:array<string,mixed>} $opened The opened archive
	 * @param string $archiveName The name for the audit entry
	 * @param OutputInterface $output The output
	 *
	 * @return int
	 */
	private function runRestore(array $opened, string $archiveName, OutputInterface $output): int {
		$output->writeln('Switching maintenance mode on for the restore.');
		try {
			$result = $this->restore->restore(zip: $opened['zip'], manifest: $opened['manifest'], archiveName: $archiveName);
		} catch (Throwable $exception) {
			$output->writeln('<error>Restore failed: ' . $exception->getMessage() . '</error>');
			$output->writeln('<error>Maintenance mode is off again.</error>');
			return 1;
		}

		$output->writeln('Maintenance mode is off again.');
		$output->writeln(sprintf('Restored %d rows and %d attachment blobs.', $result['rows'], $result['blobs']));

		return 0;
	}//end runRestore()

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
