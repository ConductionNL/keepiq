<?php

/**
 * Keepiq AccessAsk
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
 * What a recipient asks for: a window and a reason, as submitted.
 *
 * A plain carrier: PrivilegedAccessService::request() validates it, after it
 * has checked that the caller holds the copy.
 *
 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
 */
final class AccessAsk {

	/**
	 * The ask.
	 *
	 * @param DateTimeImmutable $startsAt The start asked for
	 * @param DateTimeImmutable $endsAt   The end asked for
	 * @param string            $reason   Why the login is needed
	 *
	 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
	 */
	public function __construct(
		public readonly DateTimeImmutable $startsAt,
		public readonly DateTimeImmutable $endsAt,
		public readonly string $reason,
	) {
	}//end __construct()
}//end class
