<?php

/**
 * Keepiq Judged Share Rows
 *
 * The live share rows of one source or one target, as ShareRowJudge hands
 * them out: each row is judged by the pending quarantine scan the first time a
 * caller asks about it, and only once, so a caller that leaves a row out for
 * reasons of its own (a gone copy, an ended access) does not have it judged.
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

use Closure;
use OCA\Keepiq\Db\ShareTarget;

/**
 * A list of share rows with the pending scan's verdict on each.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-runtime-paths-follow-only-judged-share-rows
 */
final class JudgedShareRows {

	/**
	 * The verdicts asked for so far, keyed by row id.
	 *
	 * @var array<string,string|null>
	 */
	private array $verdicts = [];

	/**
	 * Constructor.
	 *
	 * @param list<ShareTarget>                $rows  The live rows
	 * @param Closure(ShareTarget):?string     $judge The pending scan's verdict on one row
	 *
	 * @return void
	 *
	 * @spec exclude Value object wiring only.
	 */
	public function __construct(
		private array $rows,
		private Closure $judge,
	) {
	}//end __construct()

	/**
	 * The same source's rows read again, with every verdict already made for
	 * a row that is still there carried over: only a row that appeared since
	 * is judged anew, so a re-read under a lock never answers differently for
	 * a row this request already judged.
	 *
	 * @param list<ShareTarget> $rows The rows as read now
	 *
	 * @return self
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-runtime-paths-follow-only-judged-share-rows
	 */
	public function reread(array $rows): self {
		$again = new self(rows: array_values($rows), judge: $this->judge);
		foreach ($again->rows as $row) {
			$id = (string)$row->getId();
			if (array_key_exists($id, $this->verdicts) === true) {
				$again->verdicts[$id] = $this->verdicts[$id];
			}
		}

		return $again;
	}//end reread()

	/**
	 * Judge every row now, so a later re-read under a lock only judges a row
	 * that appeared since.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-runtime-paths-follow-only-judged-share-rows
	 */
	public function judgeAll(): self {
		foreach ($this->rows as $row) {
			$this->verdict(row: $row);
		}

		return $this;
	}//end judgeAll()

	/**
	 * Whether a row has no verdict yet: after a re-read of rows that were all
	 * judged, one that appeared since.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-runtime-paths-follow-only-judged-share-rows
	 */
	public function appearedSince(): bool {
		foreach ($this->rows as $row) {
			if (array_key_exists((string)$row->getId(), $this->verdicts) === false) {
				return true;
			}
		}

		return false;
	}//end appearedSince()

	/**
	 * Every live row, judged or not. A caller that follows a row to its copy
	 * or its source asks verdict() first.
	 *
	 * @return list<ShareTarget>
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-runtime-paths-follow-only-judged-share-rows
	 */
	public function all(): array {
		return $this->rows;
	}//end all()

	/**
	 * The pending scan's verdict on one row: ShareQuarantineScanner::HELD_QUARANTINED,
	 * HELD_UNJUDGED, or null when it does not hold the row back.
	 *
	 * @param ShareTarget $row One of the rows
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-runtime-paths-follow-only-judged-share-rows
	 */
	public function verdict(ShareTarget $row): ?string {
		$id = (string)$row->getId();
		if (array_key_exists($id, $this->verdicts) === false) {
			$this->verdicts[$id] = ($this->judge)($row);
		}

		return $this->verdicts[$id];
	}//end verdict()

	/**
	 * The rows the pending scan does not hold back. This does not check that
	 * a row's target holds the copy it names: unlike a TRUSTED
	 * ShareRowVerdict, a row here may name someone else's secret, so a
	 * caller that touches or names the copy checks ShareTarget::isHeldByTarget()
	 * against it first.
	 *
	 * @return list<ShareTarget>
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-runtime-paths-follow-only-judged-share-rows
	 */
	public function notHeldBack(): array {
		return array_values(array_filter($this->rows, fn (ShareTarget $row): bool => $this->verdict(row: $row) === null));
	}//end notHeldBack()

	/**
	 * The rows the pending scan quarantines or cannot judge.
	 *
	 * @return list<ShareTarget>
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-runtime-paths-follow-only-judged-share-rows
	 */
	public function heldBack(): array {
		return array_values(array_filter($this->rows, fn (ShareTarget $row): bool => $this->verdict(row: $row) !== null));
	}//end heldBack()
}//end class
