<?php

/**
 * Keepiq - Why and by whom a rotation flag is raised
 *
 * @category Service
 * @package  OCA\Keepiq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Service;

/**
 * The reason a rotation flag is raised for, and the user who raised it.
 *
 * @spec openspec/changes/rotation-expiry-policies/specs/rotation-expiry-policies/spec.md
 */
final class RotationRaise {
	/**
	 * Constructor for RotationRaise.
	 *
	 * @param string      $reason    The reason (`user_flagged`|`policy_expiry`|`suite_compromise`)
	 * @param string|null $flaggedBy The raising user (null = system)
	 *
	 * @return void
	 *
	 * @spec exclude Value object wiring only — no domain logic.
	 */
	public function __construct(
		public readonly string $reason,
		public readonly ?string $flaggedBy,
	) {
	}//end __construct()

	/**
	 * The actor the audit names: the raising user, or the system.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/rotation-expiry-policies/specs/rotation-expiry-policies/spec.md
	 */
	public function actorId(): string {
		return $this->flaggedBy ?? 'system';
	}//end actorId()
}//end class
