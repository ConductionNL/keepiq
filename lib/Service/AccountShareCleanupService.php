<?php

/**
 * Keepiq Account Share Cleanup Service
 *
 * The share-shaped steps of the GDPR Art. 17 erasure cascade (secret-export-gdpr
 * D4), split out of AccountDeletionService: delegated secrets are transferred,
 * recipient copies of secrets the user granted are detached and tombstoned, and
 * the link rows naming the user as recipient are severed.
 *
 * Every step is idempotent and keyed by userId, so an interrupted run can be
 * safely re-executed. No personal data of the deleted user is ever written to a
 * recipient's copy.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Service;

use OCA\Keepiq\Db\GroupShareMapper;
use OCA\Keepiq\Db\SecretDelegationMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareSaveStageMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;

/**
 * Runs the share-related steps of the account-deletion cascade.
 */
class AccountShareCleanupService {
	/**
	 * The non-personal tombstone reason written to detached recipient copies.
	 *
	 * @var string
	 */
	public const TOMBSTONE_REASON = 'owner-account-deleted';

	/**
	 * Constructor for AccountShareCleanupService.
	 *
	 * @param SecretMapper $secretMapper The secret mapper
	 * @param ShareTargetMapper $shareMapper The share-target mapper
	 * @param GroupShareMapper $groupShareMapper The group-share mapper
	 * @param SecretDelegationMapper $delegationMapper The delegation mapper
	 * @param ShareSaveStageMapper $stageMapper Staged share-save chunks
	 * @param SecretOwnerTransferService $ownerTransfer Hands a secret and its partner and link shares over
	 * @param ShareQuarantineScanner $quarantineScan Holds back the rows the pending scan catches or cannot judge
	 * @param ShareRowJudge $rowJudge Judges the delegate's share row of a secret handed over
	 * @param LoggerInterface|null $logger The logger (skipped cross-user tombstones)
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private SecretMapper $secretMapper,
		private ShareTargetMapper $shareMapper,
		private GroupShareMapper $groupShareMapper,
		private SecretDelegationMapper $delegationMapper,
		private ShareSaveStageMapper $stageMapper,
		private SecretOwnerTransferService $ownerTransfer,
		private ShareQuarantineScanner $quarantineScan,
		private ShareRowJudge $rowJudge,
		private ?LoggerInterface $logger = null,
	) {
	}//end __construct()

	/**
	 * Step 1 — transfer secrets under an active delegation to the delegate.
	 *
	 * Each delegation is made permanent (per the user-sharing spec's
	 * is_permanent semantics for a deleted owner) and the secret's owner is
	 * reassigned to the delegate, partner shares included, so the delegate's
	 * access continues uninterrupted. The departing user's link shares are
	 * deleted before this step, so none of them reach the delegate. Transferred
	 * secrets are excluded from the later own-secret hard delete because the
	 * owner_id no longer matches the departing user.
	 *
	 * @param string $userId The departing user
	 * @param array<int,\OCA\Keepiq\Db\Secret> $ownedSecrets The user's secrets
	 * @param DeletionReport $report The running report
	 *
	 * @return array<string,string> The new owner of each secret handed over, by secret id
	 *
	 * @spec openspec/specs/gdpr-compliance/spec.md
	 */
	public function transferDelegatedSecrets(string $userId, array $ownedSecrets, DeletionReport $report): array {
		$ownedIds = [];
		foreach ($ownedSecrets as $secret) {
			$ownedIds[$secret->getId()] = true;
		}

		$handedTo = [];
		foreach ($this->delegationMapper->findByOriginalOwner(originalOwnerId: $userId) as $delegation) {
			$secretId = $delegation->getSecretId();
			// Only transfer secrets the departing user actually owns now.
			if (isset($ownedIds[$secretId]) === false) {
				continue;
			}

			$this->ownerTransfer->transfer(
				secretId: $secretId,
				fromOwnerId: $userId,
				newOwnerId: $delegation->getDelegatedTo(),
			);
			// A second delegation of the secret hands it on again, so the last one names its owner.
			$handedTo[$secretId] = $delegation->getDelegatedTo();
			$report->secretsTransferred++;
		}

		// Promote every temporary delegation by this owner to permanent. The
		// mapper call is idempotent (already-permanent rows are not matched).
		$this->delegationMapper->makePermanentByOriginalOwner(originalOwnerId: $userId);

		return $handedTo;
	}//end transferDelegatedSecrets()

	/**
	 * Step 2 — detach recipient copies of secrets the user shared.
	 *
	 * For each ShareTarget where one of the user's secrets is the source, the
	 * recipient already holds a full Secret row encrypted under their own suite.
	 * The link row is deleted (sync severed) and the recipient copy is
	 * tombstoned with a non-personal reason — NO identity of the deleted user is
	 * written to the recipient copy. GroupShares the user created are deleted
	 * the same way (their per-member ShareTarget rows carry the groupShareId).
	 *
	 * A secret handed to a delegate keeps the delegate's own share row and
	 * copy as they are, when the row judge trusts that row: the delegate now
	 * owns the secret and saves it through that copy.
	 *
	 * @param array<int,\OCA\Keepiq\Db\Secret> $ownedSecrets The user's secrets
	 * @param DeletionReport $report The running report
	 * @param array<string,string> $handedTo The new owner of each secret handed over, by secret id
	 *
	 * @return void
	 *
	 * @spec openspec/specs/gdpr-compliance/spec.md
	 * @spec openspec/changes/sharing-share-save-missed-partner-shares/specs/gdpr-compliance/spec.md#requirement-shared-secret-semantics-on-account-deletion
	 */
	public function detachGrantedShares(array $ownedSecrets, DeletionReport $report, array $handedTo): void {
		foreach ($ownedSecrets as $secret) {
			$kept = $this->keptDelegateRow(secretId: $secret->getId(), newOwnerId: ($handedTo[$secret->getId()] ?? null));
			foreach ($this->shareMapper->findBySourceSecret(sourceSecretId: $secret->getId()) as $share) {
				if ($share->getId() !== $kept?->getId()) {
					$this->tombstoneCopy(share: $share, report: $report);
				}
			}

			// Sever every other link from this source secret (sync stops).
			$this->severLinks(sourceSecretId: $secret->getId(), kept: $kept);

			// Remove the group-share definitions for this secret.
			foreach ($this->groupShareMapper->findBySecret(secretId: $secret->getId()) as $groupShare) {
				$this->groupShareMapper->deleteBySecret(secretId: $groupShare->getSecretId());
			}
		}
	}//end detachGrantedShares()

	/**
	 * Tombstone a recipient's copy (display metadata only). Defence in depth:
	 * a copy that exists but is not genuinely held by the share's target (a
	 * poisoned or drifted `secret_id`) is left untouched, so erasing one owner
	 * never tombstones another user's secret, and so is the copy of a row the
	 * pending quarantine scan holds back. The link row is severed either way.
	 *
	 * @param ShareTarget $share The share row
	 * @param DeletionReport $report The running report
	 *
	 * @return void
	 */
	private function tombstoneCopy(ShareTarget $share, DeletionReport $report): void {
		try {
			$copy = $this->secretMapper->findById($share->getSecretId());
		} catch (DoesNotExistException) {
			$copy = null;
		}

		if ($copy !== null
			&& ($share->isHeldByTarget(copy: $copy) === false || $this->quarantineScan->heldBack(row: $share) !== null)
		) {
			$this->logger?->warning(
				'Kept a secret its detached share target does not hold, or the pending quarantine scan holds back',
				['app' => 'keepiq', 'shareId' => $share->getId(), 'secretId' => $share->getSecretId()]
			);
			return;
		}

		$this->secretMapper->tombstone(
			secretId: $share->getSecretId(),
			reason: self::TOMBSTONE_REASON,
		);
		$report->sharesDetached++;
	}//end tombstoneCopy()

	/**
	 * Delete every share row of a source secret, quarantined ones included,
	 * but the delegate's row that is kept.
	 *
	 * @param string $sourceSecretId The source secret
	 * @param ShareTarget|null $kept The delegate's row to keep, if any
	 *
	 * @return void
	 */
	private function severLinks(string $sourceSecretId, ?ShareTarget $kept): void {
		if ($kept === null) {
			$this->shareMapper->deleteBySourceSecret(sourceSecretId: $sourceSecretId);
			return;
		}

		$this->shareMapper->deleteBySourceSecretExcept(sourceSecretId: $sourceSecretId, keepId: $kept->getId());
	}//end severLinks()

	/**
	 * The share row of the delegate a secret was handed to, when the row judge
	 * trusts it; null when the secret was not handed over or the row is not
	 * trusted, and its copy is then detached like any other. A row from a group
	 * share no longer names it, as the group share is removed with the rest.
	 *
	 * @param string $secretId The secret
	 * @param string|null $newOwnerId The delegate it was handed to, or null
	 *
	 * @return ShareTarget|null
	 *
	 * @spec openspec/changes/sharing-share-save-missed-partner-shares/specs/gdpr-compliance/spec.md#requirement-shared-secret-semantics-on-account-deletion
	 */
	private function keptDelegateRow(string $secretId, ?string $newOwnerId): ?ShareTarget {
		if ($newOwnerId === null) {
			return null;
		}

		$row = $this->rowJudge->forPair(sourceId: $secretId, targetUserId: $newOwnerId)->trustedRow();
		if ($row !== null && $row->getGroupShareId() !== null) {
			$row->setGroupShareId(null);
			$this->shareMapper->update($row);
		}

		return $row;
	}//end keptDelegateRow()

	/**
	 * Step 3 — remove shares the user received.
	 *
	 * The recipient copies live in the deleted user's own vault and are removed
	 * by the later own-secret hard delete; here only the link rows pointing at
	 * this user as recipient are severed. The original owners' source secrets
	 * are never touched.
	 *
	 * @param string $userId The departing user
	 * @param DeletionReport $report The running report
	 *
	 * @return void
	 *
	 * @spec openspec/specs/gdpr-compliance/spec.md
	 */
	public function removeReceivedShares(string $userId, DeletionReport $report): void {
		$report->sharesRemoved = $this->shareMapper->deleteByTargetUser(targetUserId: $userId);
	}//end removeReceivedShares()

	/**
	 * Remove the user's staged share-save chunks: ciphertext they sent for a
	 * save they never finished.
	 *
	 * @param string $userId The departing user
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-large-share-save-is-staged-in-chunks
	 */
	public function removeShareSaveStages(string $userId): void {
		$this->stageMapper->deleteByUser(userId: $userId);
	}//end removeShareSaveStages()
}//end class
