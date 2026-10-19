<?php

/**
 * Keepiq Re-own Transferred Link Shares Repair Step
 *
 * A link share a former owner created on a secret that team offboarding
 * transferred away from them stays with that former owner when the transfer
 * did not move it: only they can list and revoke it, and the secret's owner
 * cannot. This step hands every such link to the secret's current owner, as
 * SecretOwnerTransferService does.
 *
 * A permanent delegation is the record of a transfer: a link is handed over
 * when its creator is the original owner of a permanent delegation of the
 * same secret, created it no later than that delegation, and no longer owns
 * the secret. Links a delegate created are not matched, including a former
 * owner's link made after the transfer as a delegate again, and neither are
 * links on a secret whose delegation was made permanent without a change of
 * owner.
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

use OCA\Keepiq\Db\LinkShareMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hands link shares left with a former owner by an ownership transfer to
 * their secret's owner.
 *
 * @spec openspec/specs/link-sharing/spec.md#requirement-manual-revocation
 */
class ReownTransferredLinkShares implements IRepairStep {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection   $db     Database connection
	 * @param LinkShareMapper $links  Public link shares
	 * @param LoggerInterface $logger Logger
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LinkShareMapper $links,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Step name shown by `occ maintenance:repair`.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/link-sharing/spec.md#requirement-manual-revocation
	 */
	public function getName(): string {
		return 'Hand a former owner\'s link shares to the current owner of their secret';
	}//end getName()

	/**
	 * Re-own the left-behind links. Never throws: a failure is logged, and
	 * the next run picks the rows up again.
	 *
	 * @param IOutput $output Repair output
	 *
	 * @return void
	 *
	 * @spec openspec/specs/link-sharing/spec.md#requirement-manual-revocation
	 */
	public function run(IOutput $output): void {
		try {
			$moved = 0;
			foreach ($this->leftBehind() as $linkId => $ownerId) {
				// PHP turns a numeric string key into an int.
				$moved += $this->links->reassignCreatorById(id: (string)$linkId, toUserId: $ownerId);
			}
		} catch (Throwable $e) {
			$this->logger->warning('ReownTransferredLinkShares failed: '.$e->getMessage(), ['exception' => $e]);
			$output->warning('ReownTransferredLinkShares failed; see the log. It runs again with the next repair.');
			return;
		}

		$output->info('ReownTransferredLinkShares: '.$moved.' link share(s) handed to their secret\'s owner.');
	}//end run()

	/**
	 * The current owner of the secret of every link a former owner created
	 * while a permanent delegation records them as owning that user-owned
	 * secret, keyed by link id.
	 *
	 * @return array<string,string>
	 */
	private function leftBehind(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct(['l.id', 's.owner_id'])
			->from('keepiq_link_shares', 'l')
			->innerJoin('l', 'keepiq_secrets', 's', $qb->expr()->eq('s.id', 'l.secret_id'))
			->innerJoin(
				'l',
				'keepiq_secret_delegations',
				'd',
				$qb->expr()->andX(
					$qb->expr()->eq('d.secret_id', 'l.secret_id'),
					$qb->expr()->eq('d.original_owner_id', 'l.created_by')
				)
			)
			->where($qb->expr()->eq('d.is_permanent', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->eq('s.owner_type', $qb->createNamedParameter('user')))
			->andWhere($qb->expr()->neq('s.owner_id', 'l.created_by'))
			->andWhere($qb->expr()->lte('l.created_at', 'd.delegated_at'));

		$owners = [];
		$result = $qb->executeQuery();
		while (($row = $result->fetch()) !== false) {
			$owners[(string)$row['id']] = (string)$row['owner_id'];
		}

		$result->closeCursor();

		return $owners;
	}//end leftBehind()
}//end class
