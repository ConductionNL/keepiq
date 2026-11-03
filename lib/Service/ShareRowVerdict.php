<?php

/**
 * Keepiq Share Row Verdict
 *
 * What ShareRowJudge found for the share row naming one copy, or for one
 * (source, target) pair: no row, two rows, a row whose target does not hold
 * the copy, a row the pending quarantine scan quarantines or cannot judge, or
 * a trusted row. Only a trusted row may be followed from the copy to its
 * source; every other answer means the caller treats the secret as its own,
 * or refuses.
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
use OCA\Keepiq\Db\ShareTarget;

/**
 * The judged state of a share row lookup.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-runtime-paths-follow-only-judged-share-rows
 */
final class ShareRowVerdict {

	/**
	 * No live share row names the copy or the pair.
	 *
	 * @var string
	 */
	public const NONE = 'none';

	/**
	 * More than one live share row names the copy or the pair.
	 *
	 * @var string
	 */
	public const AMBIGUOUS = 'ambiguous';

	/**
	 * The row's target does not hold the copy it names, or the copy is gone.
	 *
	 * @var string
	 */
	public const FOREIGN = 'foreign';

	/**
	 * The pending quarantine scan quarantines the row.
	 *
	 * @var string
	 */
	public const QUARANTINED = 'quarantined';

	/**
	 * The pending quarantine scan cannot judge the row.
	 *
	 * @var string
	 */
	public const UNJUDGED = 'unjudged';

	/**
	 * The row's target holds the copy, and the scan does not hold the row back.
	 *
	 * @var string
	 */
	public const TRUSTED = 'trusted';

	/**
	 * Constructor.
	 *
	 * @param string           $state           One of the constants
	 * @param ShareTarget|null $row             The row, when exactly one was found
	 * @param bool             $scanCannotJudge Whether the pending scan cannot judge a foreign row
	 *
	 * @return void
	 *
	 * @spec exclude Value object wiring only.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $scanCannotJudge is what the pending scan answered for the row, not a
	 *   mode of the verdict.
	 */
	public function __construct(
		public readonly string $state,
		public readonly ?ShareTarget $row = null,
		public readonly bool $scanCannotJudge = false,
	) {
	}//end __construct()

	/**
	 * The row, only when it is trusted.
	 *
	 * @return ShareTarget|null
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-runtime-paths-follow-only-judged-share-rows
	 */
	public function trustedRow(): ?ShareTarget {
		if ($this->state === self::TRUSTED) {
			return $this->row;
		}

		return null;
	}//end trustedRow()

	/**
	 * Whether the secret counts as a copy for a check that refuses copies:
	 * a trusted row, two rows, a row its target does not hold, and a row the
	 * pending scan cannot judge all do, so the check fails closed until the
	 * scan has judged it. No row, or one the scan quarantines, does not.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-runtime-paths-follow-only-judged-share-rows
	 */
	public function countsAsCopy(): bool {
		return in_array($this->state, [self::TRUSTED, self::AMBIGUOUS, self::FOREIGN, self::UNJUDGED], true);
	}//end countsAsCopy()

	/**
	 * Whether the pending scan cannot judge the row naming a secret the user
	 * holds: that holder is told the request waits for the scan, where anyone
	 * else gets the refusal of a copy.
	 *
	 * @param Secret $secret The secret the row names
	 * @param string $userId The caller
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-runtime-paths-follow-only-judged-share-rows
	 */
	public function awaitsTheScanFor(Secret $secret, string $userId): bool {
		return $this->awaitsTheScan() === true && $secret->getOwnerType() === 'user' && $secret->getOwnerId() === $userId;
	}//end awaitsTheScanFor()

	/**
	 * Whether a check that refuses copies waits for the pending scan: the scan
	 * cannot judge the row, whether or not its target holds the copy. A foreign
	 * row stays foreign for every other path, so a save through it still saves
	 * the holder's own row.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-runtime-paths-follow-only-judged-share-rows
	 */
	public function awaitsTheScan(): bool {
		return $this->state === self::UNJUDGED || ($this->state === self::FOREIGN && $this->scanCannotJudge === true);
	}//end awaitsTheScan()
}//end class
