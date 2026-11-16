<?php

/**
 * Keepiq AccessNarrowing
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

/**
 * How an owner narrows a requested window on approval: a later start, an
 * earlier end and address ranges, each optional.
 *
 * A plain carrier: AccessWindow::approve() refuses one that widens the request
 * or names a range that does not parse.
 *
 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
 */
final class AccessNarrowing {

	/**
	 * The narrowing; all defaults approve the request as asked, from any address.
	 *
	 * @param DateTimeImmutable|null $startsAt A later start, or null to keep the requested one
	 * @param DateTimeImmutable|null $endsAt   An earlier end, or null to keep the requested one
	 * @param list<string>            $ranges   CIDR ranges to limit use to; empty for any address
	 *
	 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
	 */
	public function __construct(
		public readonly ?DateTimeImmutable $startsAt = null,
		public readonly ?DateTimeImmutable $endsAt = null,
		public readonly array $ranges = [],
	) {
	}//end __construct()
}//end class
