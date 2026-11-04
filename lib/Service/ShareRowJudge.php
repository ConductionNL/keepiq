<?php

/**
 * Keepiq Share Row Judge
 *
 * The one way a runtime path reads share rows by copy, by source, by target or
 * by (source, target) pair. The share-target mapper hides only rows already
 * quarantined; until the quarantine scan has completed, a row it has not yet
 * reached may still be one it would quarantine. Every lookup here asks
 * ShareQuarantineScanner::heldBack() about the row, which judges it on the
 * spot, and checks that the row's target holds the copy it names. A path
 * follows a row from a copy to its source, or from a source to a copy, only
 * when the answer is a trusted row (ShareRowVerdict::trustedRow()); it treats any other answer as no copy,
 * leaves the row out, or refuses with ShareScanPendingException where leaving
 * an unjudged copy behind would let it drift.
 *
 * tests/Unit/Architecture/ShareRowLookupsAreJudgedTest keeps the raw finders
 * out of every other runtime path.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
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

namespace OCA\Keepiq\Service;

use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Exception\ShareScanPendingException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;

/**
 * Judged lookups of share rows.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-runtime-paths-follow-only-judged-share-rows
 */
class ShareRowJudge {
	/**
	 * Constructor.
	 *
	 * @param ShareTargetMapper      $targets The live share rows
	 * @param SecretMapper           $secrets Loads the copy a pair or a copy id names
	 * @param ShareQuarantineScanner $scanner The pending scan
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private ShareTargetMapper $targets,
		private SecretMapper $secrets,
		private ShareQuarantineScanner $scanner,
	) {
	}//end __construct()

	/**
	 * The pending scan's verdict on a row the caller already holds:
	 * ShareQuarantineScanner::HELD_QUARANTINED, HELD_UNJUDGED, or null when
	 * it does not hold the row back. Fails closed: a row that carries a
	 * quarantine stamp is quarantined whatever the scan says, and an answer
	 * the scan is not known to give counts as unjudged.
	 *
	 * @param ShareTarget $row The share row
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-runtime-paths-follow-only-judged-share-rows
	 */
	public function verdict(ShareTarget $row): ?string {
		if ($row->getQuarantinedAt() !== null) {
			return ShareQuarantineScanner::HELD_QUARANTINED;
		}

		$held = $this->scanner->heldBack(row: $row);
		if ($held === null || $held === ShareQuarantineScanner::HELD_QUARANTINED) {
			return $held;
		}

		return ShareQuarantineScanner::HELD_UNJUDGED;
	}//end verdict()

	/**
	 * The verdict on the share row naming a secret as its copy.
	 *
	 * @param Secret $copy The secret that may be a received copy
	 *
	 * @return ShareRowVerdict
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-runtime-paths-follow-only-judged-share-rows
	 */
	public function forCopy(Secret $copy): ShareRowVerdict {
		try {
			$row = $this->targets->findByRecipientSecret(recipientSecretId: $copy->getId());
		} catch (DoesNotExistException) {
			return new ShareRowVerdict(state: ShareRowVerdict::NONE);
		} catch (MultipleObjectsReturnedException) {
			return new ShareRowVerdict(state: ShareRowVerdict::AMBIGUOUS);
		}

		return $this->judgeRow(row: $row, copy: $copy);
	}//end forCopy()

	/**
	 * The verdict on the share row naming a secret id as its copy. A secret
	 * that does not exist is no copy.
	 *
	 * @param string $copyId The secret that may be a received copy
	 *
	 * @return ShareRowVerdict
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-runtime-paths-follow-only-judged-share-rows
	 */
	public function forCopyId(string $copyId): ShareRowVerdict {
		$copy = $this->loadCopy(copyId: $copyId);
		if ($copy === null) {
			return new ShareRowVerdict(state: ShareRowVerdict::NONE);
		}

		return $this->forCopy(copy: $copy);
	}//end forCopyId()

	/**
	 * The verdict on the share row of a (source, target) pair. A row whose
	 * copy is gone is not trusted.
	 *
	 * @param string $sourceId     The source secret
	 * @param string $targetUserId The row's target
	 *
	 * @return ShareRowVerdict
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-runtime-paths-follow-only-judged-share-rows
	 */
	public function forPair(string $sourceId, string $targetUserId): ShareRowVerdict {
		try {
			$row = $this->targets->findBySourceSecretAndTargetUser(sourceSecretId: $sourceId, targetUserId: $targetUserId);
		} catch (DoesNotExistException) {
			return new ShareRowVerdict(state: ShareRowVerdict::NONE);
		} catch (MultipleObjectsReturnedException) {
			return new ShareRowVerdict(state: ShareRowVerdict::AMBIGUOUS);
		}

		$copy = $this->loadCopy(copyId: (string)$row->getSecretId());
		if ($copy === null) {
			return new ShareRowVerdict(state: ShareRowVerdict::FOREIGN, row: $row);
		}

		return $this->judgeRow(row: $row, copy: $copy);
	}//end forPair()



	/**
	 * The live share rows of a source, each judged when the caller asks. Given
	 * the rows this request read before, the verdicts already made carry over.
	 *
	 * @param string               $sourceId The source secret
	 * @param JudgedShareRows|null $before   The rows this request judged before, if any
	 *
	 * @return JudgedShareRows
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-runtime-paths-follow-only-judged-share-rows
	 */
	public function rowsOfSource(string $sourceId, ?JudgedShareRows $before=null): JudgedShareRows {
		$rows = $this->targets->findBySourceSecret($sourceId);
		if ($before !== null) {
			return $before->reread(rows: $rows);
		}

		return $this->judgeRows(rows: $rows);
	}//end rowsOfSource()

	/**
	 * The live share rows held by a target, each judged when the caller asks.
	 *
	 * @param string $targetUserId The target
	 *
	 * @return JudgedShareRows
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-runtime-paths-follow-only-judged-share-rows
	 */
	public function rowsOfTarget(string $targetUserId): JudgedShareRows {
		return $this->judgeRows(rows: $this->targets->findByTargetUser($targetUserId));
	}//end rowsOfTarget()

	/**
	 * Share rows read another way (by team folder, by group share), each
	 * judged when the caller asks.
	 *
	 * @param array<int,ShareTarget> $rows The live rows
	 *
	 * @return JudgedShareRows
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-runtime-paths-follow-only-judged-share-rows
	 */
	public function judgeRows(array $rows): JudgedShareRows {
		return new JudgedShareRows(rows: array_values($rows), judge: fn (ShareTarget $row): ?string => $this->verdict(row: $row));
	}//end judgeRows()

	/**
	 * The refusal of a request that reaches a row the pending scan cannot
	 * judge, worded as ShareQuarantineScanner::refuseUnjudged() words it.
	 *
	 * @param string $refusal What waits for the scan, without the advice to the user
	 *
	 * @return ShareScanPendingException
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-runtime-paths-follow-only-judged-share-rows
	 */
	public function scanPending(string $refusal): ShareScanPendingException {
		return new ShareScanPendingException(message: $refusal.'; ask an administrator to check that background jobs run');
	}//end scanPending()

	/**
	 * Judge one row found for a copy. A row is trusted only when its target
	 * holds the copy and the pending scan answers that it does not hold the
	 * row back; every other answer fails closed. A row whose target does not
	 * hold the copy is still judged, so the scan can quarantine it on the
	 * spot: it is then no copy at all, and otherwise stays foreign, noting
	 * when the scan cannot judge it so a check that refuses copies waits.
	 *
	 * @param ShareTarget $row  The row
	 * @param Secret      $copy The copy it names
	 *
	 * @return ShareRowVerdict
	 */
	private function judgeRow(ShareTarget $row, Secret $copy): ShareRowVerdict {
		$held = $this->verdict(row: $row);
		if ($held === ShareQuarantineScanner::HELD_QUARANTINED) {
			return new ShareRowVerdict(state: ShareRowVerdict::QUARANTINED, row: $row);
		}

		if ($row->isHeldByTarget(copy: $copy) === false) {
			return new ShareRowVerdict(
				state: ShareRowVerdict::FOREIGN,
				row: $row,
				scanCannotJudge: ($held === ShareQuarantineScanner::HELD_UNJUDGED)
			);
		}

		if ($held === null) {
			return new ShareRowVerdict(state: ShareRowVerdict::TRUSTED, row: $row);
		}

		return new ShareRowVerdict(state: ShareRowVerdict::UNJUDGED, row: $row);
	}//end judgeRow()

	/**
	 * A secret by id, or null when it does not exist.
	 *
	 * @param string $copyId The secret
	 *
	 * @return Secret|null
	 */
	private function loadCopy(string $copyId): ?Secret {
		try {
			return $this->secrets->findById($copyId);
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			return null;
		}
	}//end loadCopy()
}//end class
