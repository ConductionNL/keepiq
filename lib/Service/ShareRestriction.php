<?php

/**
 * Keepiq ShareRestriction
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

use DateTime;
use DateTimeInterface;

/**
 * The two restrictions a grant can carry: use-only and an end date
 * (sharing-use-only-and-expiring-shares D1). Immutable.
 */
final class ShareRestriction {

	/**
	 * Constructor for ShareRestriction.
	 *
	 * @param bool          $useOnly   Whether the recipient may only use the value
	 * @param DateTime|null $expiresAt When the access ends (null = no end)
	 *
	 * @return void
	 *
	 * @spec exclude Value object constructor; the combination rule carries the spec anchor.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $useOnly is one of the two
	 *   values this object carries, not a mode switch.
	 */
	public function __construct(
		public readonly bool $useOnly = false,
		public readonly ?DateTime $expiresAt = null,
	) {
	}//end __construct()

	/**
	 * Whether this restriction blocks onward sharing (D4): a use-only copy
	 * or a copy with an end date.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/sharing-use-only-and-expiring-shares/specs/expiring-shares/spec.md#requirement-an-expiring-copy-cannot-be-shared-onward
	 */
	public function isRestricted(): bool {
		return ($this->useOnly === true || $this->expiresAt !== null);
	}//end isRestricted()

	/**
	 * Whether two restrictions are the same (compared to the second).
	 *
	 * @param self $other The other restriction
	 *
	 * @return bool
	 *
	 * @spec exclude Comparison helper so the resolver writes only when something changed.
	 */
	public function equals(self $other): bool {
		return $this->useOnly === $other->useOnly
			&& $this->expiresAt?->format(DateTimeInterface::ATOM) === $other->expiresAt?->format(DateTimeInterface::ATOM);
	}//end equals()
}//end class
