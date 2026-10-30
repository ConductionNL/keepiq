<?php

/**
 * Keepiq occ command: keepiq:share-quarantine
 *
 * Lists the share rows the GHSA-m5p9-6pw7-pf3g scan quarantined, releases one
 * it caught by mistake, or drops one for good while keeping its copy.
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
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Service\ShareQuarantineService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ keepiq:share-quarantine list|release <id> [--force]|drop <id> [--clear-restrictions]
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
 */
class ShareQuarantine extends Command {
	/**
	 * Constructor.
	 *
	 * @param ShareQuarantineService $quarantine The quarantine service
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private ShareQuarantineService $quarantine,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Name and arguments.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
	 */
	protected function configure(): void {
		$this->setName(name: 'keepiq:share-quarantine')
			->setDescription(description: 'List, release or drop share rows quarantined by the GHSA-m5p9-6pw7-pf3g scan')
			->addArgument(name: 'action', mode: InputArgument::REQUIRED, description: 'list, release or drop')
			->addArgument(name: 'id', mode: InputArgument::OPTIONAL, description: 'The share row id, for release and drop')
			->addOption(
				name: 'force',
				mode: InputOption::VALUE_NONE,
				description: 'Release a row whose copy was shared before the row existed, is held by anyone but the row\'s creator (the recipient, '
					.'including a copy created as a secret of its own, or a third party), or is the source of a live share, or whose share, '
					.'group share or copy has an access end date'
			)
			->addOption(
				name: 'clear-restrictions',
				mode: InputOption::VALUE_NONE,
				description: 'On drop, clear the copy\'s use-only flag and access end date when the row\'s recipient holds it. A legitimately '
					.'shared use-only copy looks the same, so on a row quarantined by mistake this leaves the recipient a full copy nobody can revoke'
			);
	}//end configure()

	/**
	 * Run the action.
	 *
	 * @param InputInterface $input The input
	 * @param OutputInterface $output The output
	 *
	 * @return int
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$action = (string)$input->getArgument('action');
		if ($action === 'list') {
			$this->renderList(output: $output);
			return 0;
		}

		$id = (string)$input->getArgument('id');
		if (in_array($action, ['release', 'drop'], true) === false || $id === '') {
			$output->writeln('<error>Use: keepiq:share-quarantine list | release <id> [--force] | drop <id> [--clear-restrictions]</error>');
			return 1;
		}

		try {
			if ($action === 'release') {
				$this->quarantine->release(id: $id, force: (bool)$input->getOption('force'));
				$output->writeln('Released share row '.$id.': it is a live share again.');
				return 0;
			}

			$clearRestrictions = (bool)$input->getOption('clear-restrictions');
			$cleared = $this->quarantine->drop(id: $id, clearRestrictions: $clearRestrictions);
			$output->writeln('Dropped share row '.$id.'; its copy was kept.');
			if ($cleared === true) {
				$output->writeln('The copy\'s use-only flag and access end date were cleared.');
			} elseif ($clearRestrictions === true) {
				$output->writeln('The copy\'s restrictions were left as they are: the row\'s recipient does not hold it.');
			}
		} catch (InvalidArgumentException $exception) {
			$output->writeln('<error>'.$exception->getMessage().'</error>');
			return 1;
		}

		return 0;
	}//end execute()

	/**
	 * Print the quarantined rows.
	 *
	 * @param OutputInterface $output The output
	 *
	 * @return void
	 */
	private function renderList(OutputInterface $output): void {
		$rows = [];
		foreach ($this->quarantine->list() as $entry) {
			$row    = $entry['row'];
			$rows[] = [
				$row->getId(),
				$row->getSourceSecretId(),
				$row->getTargetUserId(),
				$row->getSecretId(),
				$row->getCreatedBy(),
				$row->getCreatedAt()?->format('Y-m-d H:i:s') ?? '',
				$row->getQuarantinedAt()?->format('Y-m-d H:i:s') ?? '',
				(string)$row->getQuarantineReason(),
				$this->holder(row: $row, copy: $entry['copy']),
				$this->restrictions(row: $row, copy: $entry['copy']),
			];
		}

		(new Table($output))
			->setHeaders(['Id', 'Source', 'Recipient', 'Copy', 'Created by', 'Created', 'Quarantined', 'Reason', 'Copy held by', 'Restrictions'])
			->setRows($rows)
			->render();
	}//end renderList()

	/**
	 * Who holds a row's copy, for the list: a copy the recipient holds may be
	 * their own secret, so its row is released only with --force.
	 *
	 * @param ShareTarget $row  The quarantined row
	 * @param Secret|null $copy Its copy, or null when it is gone
	 *
	 * @return string
	 */
	private function holder(ShareTarget $row, ?Secret $copy): string {
		if ($copy === null) {
			return '';
		}

		if ($row->isHeldByTarget(copy: $copy) === true) {
			return 'recipient';
		}

		return $copy->getOwnerType().' '.$copy->getOwnerId();
	}//end holder()

	/**
	 * The restrictions a row and its copy carry, for the list: a planted row's
	 * restriction may have been written onto the recipient's own secret.
	 *
	 * @param ShareTarget $row  The quarantined row
	 * @param Secret|null $copy Its copy, or null when it is gone
	 *
	 * @return string
	 */
	private function restrictions(ShareTarget $row, ?Secret $copy): string {
		if ($copy === null) {
			return 'copy gone';
		}

		$notes = [];
		if ($copy->getUseOnly() === true) {
			$notes[] = 'copy use-only';
		}

		if ($copy->getAccessExpiresAt() !== null) {
			$notes[] = 'copy access ends '.$copy->getAccessExpiresAt()->format('Y-m-d H:i:s');
		}

		if ($row->getExpiresAt() !== null) {
			$notes[] = 'share ends '.$row->getExpiresAt()->format('Y-m-d H:i:s');
		}

		return implode(', ', $notes);
	}//end restrictions()
}//end class
