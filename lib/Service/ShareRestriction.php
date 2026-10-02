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
use DateTimeZone;
use Exception;
use InvalidArgumentException;

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
	 */
	public function __construct(
		public readonly bool $useOnly = false,
		public readonly ?DateTime $expiresAt = null,
	) {
	}//end __construct()

	/**
	 * Read the two options from an untrusted request body, refusing an end
	 * date that is not in the future.
	 *
	 * @param mixed    $useOnly   The raw `useOnly` value (bool, "true", 1, null)
	 * @param mixed    $expiresAt The raw `expiresAt` value (ISO 8601 string or null)
	 * @param DateTime $now       The current time
	 *
	 * @return self
	 *
	 * @throws InvalidArgumentException When the date cannot be read or is not in the future
	 *
	 * @spec openspec/changes/sharing-use-only-and-expiring-shares/specs/expiring-shares/spec.md#requirement-shares-and-memberships-can-carry-an-end-date
	 */
	public static function fromRequest(mixed $useOnly, mixed $expiresAt, DateTime $now): self {
		$flag = ($useOnly === true || $useOnly === 1 || $useOnly === '1' || $useOnly === 'true');

		if ($expiresAt === null || $expiresAt === '') {
			return new self(useOnly: $flag, expiresAt: null);
		}

		if (is_string($expiresAt) === false) {
			throw new InvalidArgumentException(message: 'expiresAt must be a date');
		}

		try {
			$end = new DateTime($expiresAt);
		} catch (Exception) {
			throw new InvalidArgumentException(message: 'expiresAt must be a date');
		}

		$end->setTimezone(new DateTimeZone('UTC'));
		if ($end <= $now) {
			throw new InvalidArgumentException(message: 'The end date must be in the future');
		}

		return new self(useOnly: $flag, expiresAt: $end);
	}//end fromRequest()

	/**
	 * Combine every grant that reaches one copy: the copy is use-only only
	 * when every grant is, and access ends at the latest end date, with a
	 * grant without an end date winning (the most generous grant wins).
	 * No grants at all gives an unrestricted result.
	 *
	 * @param array<int,self> $grants The grants reaching the copy
	 *
	 * @return self
	 *
	 * @spec openspec/changes/sharing-use-only-and-expiring-shares/specs/use-only-shares/spec.md#requirement-owners-can-share-a-secret-as-use-only
	 * @spec openspec/changes/sharing-use-only-and-expiring-shares/specs/expiring-shares/spec.md#requirement-shares-and-memberships-can-carry-an-end-date
	 */
	public static function combine(array $grants): self {
		if ($grants === []) {
			return new self();
		}

		$useOnly = true;
		$latest  = null;
		$noEnd   = false;
		foreach ($grants as $grant) {
			$useOnly = ($useOnly && $grant->useOnly);
			if ($grant->expiresAt === null) {
				$noEnd = true;
				continue;
			}

			if ($latest === null || $grant->expiresAt > $latest) {
				$latest = $grant->expiresAt;
			}
		}

		if ($noEnd === true) {
			$latest = null;
		}

		return new self(useOnly: $useOnly, expiresAt: $latest);
	}//end combine()

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
