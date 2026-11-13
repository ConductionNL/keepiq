<?php

/**
 * Contract of RecoveryPolicyService
 *
 * The public surface of RecoveryPolicyService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Keepiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/keepiq
 *
 * @spec openspec/specs/app-shell/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Kernel\Contracts;

use InvalidArgumentException;

/**
 * Contract of RecoveryPolicyService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RecoveryPolicyServiceInterface {

	/**
	 * Whether a user is a named officer.
	 *
	 * @param string $userId The user
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-recovery-needs-a-threshold-of-proven-officer-approvals
	 */
	public function isOfficer(string $userId): bool;

	/**
	 * The named officers.
	 *
	 * @return string[]
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-administrators-name-recovery-officers-a-threshold-and-a-policy
	 */
	public function officers(): array;

	/**
	 * The settings as the admin section shows them.
	 *
	 * @return array{policy:string,officers:string[],threshold:int}
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-administrators-name-recovery-officers-a-threshold-and-a-policy
	 */
	public function settings(): array;

	/**
	 * The approval threshold (at least 1).
	 *
	 * @return int
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-recovery-needs-a-threshold-of-proven-officer-approvals
	 */
	public function threshold(): int;

	/**
	 * Store the policy, officers and threshold. Refuses an unknown policy, an
	 * officer without an active suite, and a threshold outside 1..officers.
	 * Officers who are no longer named lose their copies of the recovery key.
	 *
	 * @param string   $policy    The policy
	 * @param string[] $officers  The officer user ids
	 * @param int      $threshold The approval threshold
	 *
	 * @return array{policy:string,officers:string[],threshold:int,removed:string[]}
	 *
	 * @throws InvalidArgumentException When a value is refused
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-administrators-name-recovery-officers-a-threshold-and-a-policy
	 */
	public function update(string $policy, array $officers, int $threshold): array;
}//end interface
