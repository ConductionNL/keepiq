<?php

/**
 * Keepiq Re-own Transferred Partner Shares Repair Step
 *
 * A partner share whose owner is not its secret's owner is left over from an
 * ownership transfer (account deletion with a delegate, or team offboarding)
 * that did not move it. Only the previous owner could still revoke or suspend
 * it, and the partner is not served. This step hands every such share to its
 * secret's owner, as SecretOwnerTransferService does.
 *
 * @category Repair
 * @package  OCA\Keepiq\Repair
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

namespace OCA\Keepiq\Repair;

use OCA\Keepiq\Db\FederatedShareMapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hands partner shares left behind by an ownership transfer to their
 * secret's owner.
 *
 * @spec openspec/changes/sharing-atomic-share-save/specs/federated-sharing/spec.md#scenario-bobs-share-follows-the-secret-to-a-new-owner
 */
class ReownTransferredPartnerShares implements IRepairStep {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection        $db     Database connection
	 * @param FederatedShareMapper $shares Outbound partner shares
	 * @param LoggerInterface      $logger Logger
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly FederatedShareMapper $shares,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Step name shown by `occ maintenance:repair`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/federated-sharing/spec.md#scenario-bobs-share-follows-the-secret-to-a-new-owner
	 */
	public function getName(): string {
		return 'Hand partner shares to the current owner of their secret';
	}//end getName()

	/**
	 * Re-own the left-behind shares. Never throws: a failure is logged, and
	 * the next run picks the rows up again.
	 *
	 * @param IOutput $output Repair output
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/federated-sharing/spec.md#scenario-bobs-share-follows-the-secret-to-a-new-owner
	 */
	public function run(IOutput $output): void {
		try {
			$moved = 0;
			foreach ($this->leftBehind() as $sourceSecretId => $ownerId) {
				// PHP turns a numeric string key into an int.
				$moved += $this->shares->reassignOwner(sourceSecretId: (string)$sourceSecretId, ownerId: $ownerId);
			}
		} catch (Throwable $e) {
			$this->logger->warning('ReownTransferredPartnerShares failed: '.$e->getMessage(), ['exception' => $e]);
			$output->warning('ReownTransferredPartnerShares failed; see the log. It runs again with the next repair.');
			return;
		}

		$output->info('ReownTransferredPartnerShares: '.$moved.' partner share(s) handed to their secret\'s owner.');
	}//end run()

	/**
	 * The current owner of every user-owned secret that has a partner share
	 * owned by someone else, keyed by secret id.
	 *
	 * @return array<string,string>
	 */
	private function leftBehind(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct(['f.source_secret_id', 's.owner_id'])
			->from('keepiq_federated_shares', 'f')
			->innerJoin('f', 'keepiq_secrets', 's', $qb->expr()->eq('s.id', 'f.source_secret_id'))
			->where($qb->expr()->neq('f.owner_id', 's.owner_id'))
			->andWhere($qb->expr()->eq('s.owner_type', $qb->createNamedParameter('user')));

		$owners = [];
		$result = $qb->executeQuery();
		while (($row = $result->fetch()) !== false) {
			$owners[(string)$row['source_secret_id']] = (string)$row['owner_id'];
		}

		$result->closeCursor();

		return $owners;
	}//end leftBehind()
}//end class
