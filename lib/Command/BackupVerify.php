<?php

/**
 * Keepiq occ command: keepiq:backup:verify
 *
 * Decrypts an archive when needed and checks its format and every checksum (admin-scheduled-vault-backups D4).
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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ keepiq:backup:verify <file> [--key-file=]
 */
class BackupVerify extends Command {
	/**
	 * Constructor.
	 *
	 * @param BackupService $backups Finds the archive
	 * @param RestoreService $restore Opens and verifies it
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
	 * @spec openspec/changes/admin-scheduled-vault-backups/tasks.md#3.2
	 */
	protected function configure(): void {
		$this->setName(name: 'keepiq:backup:verify')
			->setDescription(description: 'Check a Keepiq vault backup: format and every checksum')
			->addArgument(name: 'file', mode: InputArgument::REQUIRED, description: 'An archive name from keepiq:backup:list, or a file path')
			->addOption(name: 'key-file', mode: InputOption::VALUE_REQUIRED, description: 'The private key for an encrypted archive');
	}//end configure()

	/**
	 * Verify the archive.
	 *
	 * @param InputInterface $input The input
	 * @param OutputInterface $output The output
	 *
	 * @return int
	 *
	 * @spec openspec/changes/admin-scheduled-vault-backups/tasks.md#3.2
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$opened = $this->restore->open(
				localPath: $this->backups->localCopy(nameOrPath: (string)$input->getArgument('file')),
				keyFile: self::keyFile(input: $input)
			);
		} catch (InvalidArgumentException $exception) {
			$output->writeln('<error>' . $exception->getMessage() . '</error>');
			return 1;
		}

		$manifest = $opened['manifest'];
		$output->writeln('The archive is complete and unchanged.');
		$output->writeln('Written: ' . (string)($manifest['createdAt'] ?? '') . ', Keepiq ' . (string)($manifest['appVersion'] ?? ''));
		$output->writeln('Files checked: ' . count((array)($manifest['files'] ?? [])));

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
