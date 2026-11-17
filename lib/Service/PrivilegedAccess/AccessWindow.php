<?php

/**
 * Keepiq AccessWindow
 *
 * @category Service
 * @package  OCA\Keepiq\Service\PrivilegedAccess
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

namespace OCA\Keepiq\Service\PrivilegedAccess;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * The time window, and optionally the address ranges, an owner approved for
 * one recipient's use of a privileged login.
 *
 * The start is inclusive and the end exclusive, so two windows that meet do
 * not overlap. No ranges means any address.
 *
 * @spec openspec/changes/capability-requirements-from-the-matrix/tasks.md#task-3.1
 */
final class AccessWindow {

	/**
	 * Constructor.
	 *
	 * @param DateTimeImmutable  $startsAt When use may begin.
	 * @param DateTimeImmutable  $endsAt   When use ends.
	 * @param list<AddressRange> $ranges   The ranges use is limited to; empty for any address.
	 *
	 * @throws InvalidArgumentException When the window does not end after it starts.
	 */
	public function __construct(
		public readonly DateTimeImmutable $startsAt,
		public readonly DateTimeImmutable $endsAt,
		public readonly array $ranges = [],
	) {
		if ($endsAt <= $startsAt) {
			throw new InvalidArgumentException('The window must end after it starts');
		}
	}//end __construct()

	/**
	 * An approval of a requested window: the owner may narrow it, never widen it.
	 *
	 * @param AccessWindow           $requested The window the recipient asked for.
	 * @param DateTimeImmutable|null $startsAt  A later start, or null to keep the requested one.
	 * @param DateTimeImmutable|null $endsAt    An earlier end, or null to keep the requested one.
	 * @param list<string>           $ranges    CIDR ranges to limit it to; empty for any address.
	 *
	 * @return self
	 *
	 * @throws InvalidArgumentException When the approval reaches outside the request or a range does not parse.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) AddressRange::parse() is the value
	 *   object's validating named constructor.
	 *
	 * @spec openspec/changes/capability-requirements-from-the-matrix/tasks.md#task-3.1
	 */
	public static function approve(
		AccessWindow $requested,
		?DateTimeImmutable $startsAt = null,
		?DateTimeImmutable $endsAt = null,
		array $ranges = [],
	): self {
		$start = ($startsAt ?? $requested->startsAt);
		$end   = ($endsAt ?? $requested->endsAt);
		if ($start < $requested->startsAt || $end > $requested->endsAt) {
			throw new InvalidArgumentException('An approval may only narrow the requested window');
		}

		return new self(
			startsAt: $start,
			endsAt: $end,
			ranges: array_values(array_map(static fn (string $cidr): AddressRange => AddressRange::parse(cidr: $cidr), $ranges))
		);
	}//end approve()

	/**
	 * Whether a use at `$now` from `$address` falls in this window.
	 *
	 * @param DateTimeInterface $now     The moment of use.
	 * @param string            $address The client address.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/capability-requirements-from-the-matrix/tasks.md#task-3.1
	 */
	public function allows(DateTimeInterface $now, string $address): bool {
		if ($now < $this->startsAt || $now >= $this->endsAt) {
			return false;
		}

		if ($this->ranges === []) {
			return true;
		}

		foreach ($this->ranges as $range) {
			if ($range->contains(address: $address) === true) {
				return true;
			}
		}

		return false;
	}//end allows()

	/**
	 * The ranges in canonical CIDR form, for storage and the audit trail.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/capability-requirements-from-the-matrix/tasks.md#task-3.1
	 */
	public function rangeStrings(): array {
		return array_map(static fn (AddressRange $range): string => $range->toString(), $this->ranges);
	}//end rangeStrings()
}//end class
