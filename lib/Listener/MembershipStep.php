<?php

/**
 * Keepiq Membership Step
 *
 * What one group-membership step logs.
 *
 * @category Listener
 * @package  OCA\Keepiq\Listener
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

namespace OCA\Keepiq\Listener;

/**
 * The info line a group-membership step logs when it acted, with `{count}`,
 * `{user}` and `{group}` placeholders, and the prefix of the warning it logs
 * when it fails.
 *
 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-membership-propagation-with-group-membership
 */
final class MembershipStep {
	/**
	 * Constructor for MembershipStep.
	 *
	 * @param string $done    The info line, with `{count}`, `{user}` and `{group}`
	 * @param string $failure The warning prefix, followed by the exception message
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $done,
		public readonly string $failure,
	) {
	}//end __construct()
}//end class
