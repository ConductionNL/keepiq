<?php

/**
 * Keepiq LiveCopiesRun
 *
 * The copies of one source sorted for a save for every copy, one share row at
 * a time: the copies it writes, the ones it may be named for but does not
 * write, and of those the ones the pending quarantine scan cannot judge.
 * Built and read by ShareSyncGuards::liveCopies() only.
 *
 * @category Service
 * @package  OCA\Keepiq\Service\ShareSync
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

namespace OCA\Keepiq\Service\ShareSync;

use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Service\JudgedShareRows;
use OCA\Keepiq\Service\ShareQuarantineScanner;

/**
 * One liveCopies() sort of a source's share rows.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-runtime-paths-follow-only-judged-share-rows
 */
final class LiveCopiesRun {
	/**
	 * The copies written, keyed by id.
	 *
	 * @var array<string,array{secretId:string,holder:string,targetUserId:?string}>
	 */
	private array $copies = [];

	/**
	 * The copies that may be named but are not written.
	 *
	 * @var array<string,bool>
	 */
	private array $ignored = [];

	/**
	 * The ignored copies whose row the pending scan cannot judge.
	 *
	 * @var list<string>
	 */
	private array $unjudged = [];

	/**
	 * When the sort runs, for the copies whose access ended.
	 *
	 * @var int
	 */
	private int $now;

	/**
	 * Constructor.
	 *
	 * @param JudgedShareRows     $judged The source's rows, judged for this request
	 * @param array<string,Secret> $rows   The copies' rows, keyed by id
	 *
	 * @return void
	 *
	 * @spec exclude Run object wiring only.
	 */
	public function __construct(
		private JudgedShareRows $judged,
		private array $rows,
	) {
		$this->now = time();
	}//end __construct()

	/**
	 * Sort one share row's copy into its list.
	 *
	 * @param ShareTarget $target The share row
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-runtime-paths-follow-only-judged-share-rows
	 */
	public function sort(ShareTarget $target): void {
		$copyId = $target->getSecretId();
		if ($this->isWritable(target: $target) === false) {
			$this->ignored[$copyId] = true;
			return;
		}

		// The pending quarantine scan judges the row first, as a sync does.
		$held = $this->judged->verdict(row: $target);
		if ($held !== null) {
			$this->hold(copyId: $copyId, verdict: $held);
			return;
		}

		$this->copies[$copyId] = ['secretId' => $copyId, 'holder' => 'recipient', 'targetUserId' => $target->getTargetUserId()];
	}//end sort()

	/**
	 * Whether the row's copy may be written: it is still there, its access has
	 * not ended, and it genuinely belongs to the row's target. Defence in
	 * depth: a copy whose row does not belong to its target (a poisoned or
	 * drifted `secret_id`) is dropped from the write set, so a save can never
	 * overwrite another user's secret.
	 *
	 * @param ShareTarget $target The share row
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-share-save-names-every-copy-or-is-refused
	 */
	private function isWritable(ShareTarget $target): bool {
		$row = ($this->rows[$target->getSecretId()] ?? null);
		if ($row === null || $this->accessEnded(row: $row) === true) {
			return false;
		}

		return $target->isHeldByTarget(copy: $row);
	}//end isWritable()

	/**
	 * Whether a copy's access has ended.
	 *
	 * @param Secret $row The copy's row
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/expiring-shares/spec.md#requirement-an-expiring-copy-cannot-be-shared-onward
	 */
	private function accessEnded(Secret $row): bool {
		$ends = $row->getAccessExpiresAt();
		return $ends !== null && $ends->getTimestamp() <= $this->now;
	}//end accessEnded()

	/**
	 * Ignore a copy the pending scan holds back, and list it as unjudged when
	 * the scan cannot judge its row.
	 *
	 * @param string $copyId  The copy
	 * @param string $verdict The scan's verdict on its row
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-runtime-paths-follow-only-judged-share-rows
	 */
	private function hold(string $copyId, string $verdict): void {
		$this->ignored[$copyId] = true;
		if ($verdict === ShareQuarantineScanner::HELD_UNJUDGED) {
			$this->unjudged[] = $copyId;
		}
	}//end hold()

	/**
	 * The three lists, as liveCopies() returns them.
	 *
	 * @return array{copies:array<string,array{secretId:string,holder:string,targetUserId:?string}>,ignored:array<string,bool>,unjudged:list<string>}
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-runtime-paths-follow-only-judged-share-rows
	 */
	public function result(): array {
		return ['copies' => $this->copies, 'ignored' => $this->ignored, 'unjudged' => $this->unjudged];
	}//end result()
}//end class
