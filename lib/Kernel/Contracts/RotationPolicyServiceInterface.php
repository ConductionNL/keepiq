<?php

/**
 * Contract of RotationPolicyService
 *
 * The public surface of RotationPolicyService that other components call, so they depend
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

use DateTime;
use InvalidArgumentException;
use OCA\Keepiq\Db\ExpiryPolicy;
use OCA\Keepiq\Db\RotationFlag;
use OCA\Keepiq\Db\Secret;

/**
 * Contract of RotationPolicyService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RotationPolicyServiceInterface {

	/**
	 * Delete one of the caller's policies.
	 *
	 * @param string $policyId The policy UUID
	 * @param string $userId The caller
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException On not found / foreign owner
	 *
	 * @spec openspec/specs/rotation-expiry-policies/spec.md#requirement-expiry-policies-with-admin-default-and-user-override
	 */
	public function deletePolicy(string $policyId, string $userId): void;

	/**
	 * Dismiss a flag without rotation (owner judgment call; audited).
	 *
	 * @param string $flagId The flag UUID
	 * @param string $userId The caller (must own the secret)
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rotation-expiry-policies/specs/rotation-expiry-policies/spec.md
	 */
	public function dismiss(string $flagId, string $userId): void;

	/**
	 * Raise (or return the existing open) rotation flag for a secret —
	 * idempotent one-open-flag-per-secret.
	 *
	 * @param string $secretId The secret UUID
	 * @param string $reason The reason (`user_flagged`|`policy_expiry`|`suite_compromise`)
	 * @param string|null $flaggedBy The raising user (null = system)
	 *
	 * @return RotationFlag
	 *
	 * @spec openspec/changes/rotation-expiry-policies/specs/rotation-expiry-policies/spec.md
	 */
	public function flag(string $secretId, string $reason, ?string $flaggedBy = null): RotationFlag;

	/**
	 * Batch-flag secrets the caller owns (client breach findings send IDs
	 * ONLY — no verdicts, scores, or digests are persisted).
	 *
	 * @param string $userId The caller
	 * @param string[] $secretIds The secret UUIDs
	 *
	 * @return int Flags now open
	 *
	 * @spec openspec/changes/rotation-expiry-policies/specs/rotation-expiry-policies/spec.md
	 */
	public function flagBatch(string $userId, array $secretIds): int;

	/**
	 * List the caller's policies plus the instance-wide rows.
	 *
	 * @param string $userId The caller
	 *
	 * @return ExpiryPolicy[]
	 *
	 * @spec openspec/specs/rotation-expiry-policies/spec.md#requirement-expiry-policies-with-admin-default-and-user-override
	 */
	public function listPolicies(string $userId): array;

	/**
	 * Mark a flag rotated — ONLY when the head's credential change time has
	 * advanced past the value frozen at flag time (a proven rotation).
	 * Otherwise the caller is told to rotate first (re-request path).
	 *
	 * @param string $flagId The flag UUID
	 * @param string $userId The caller (must own the secret)
	 *
	 * @return array{resolved:bool,requiresRotation:bool}
	 *
	 * @spec openspec/changes/rotation-expiry-policies/specs/rotation-expiry-policies/spec.md
	 */
	public function markRotated(string $flagId, string $userId): array;

	/**
	 * Open flags for the caller's secrets.
	 *
	 * @param string $userId The caller
	 *
	 * @return RotationFlag[]
	 *
	 * @spec openspec/specs/rotation-expiry-policies/spec.md
	 */
	public function openFlags(string $userId): array;

	/**
	 * The reminder days the policies that scope this secret ask for: the
	 * union of every applicable policy's own `reminder_days`, or null when
	 * none sets any, so the caller falls back to the instance thresholds
	 * (keepiq#746).
	 *
	 * @param Secret $secret The secret row
	 *
	 * @return array<int,int>|null
	 *
	 * @spec openspec/changes/rotation-expiry-policies/specs/rotation-expiry-policies/spec.md
	 */
	public function reminderDaysFor(Secret $secret): ?array;

	/**
	 * Resolve a secret's effective expiry: the EARLIEST of the per-secret
	 * `expires_at` and each applicable policy's `key_updated_at +
	 * max_age_days` (falling back to `created_at` when key age is null),
	 * including the admin default max age. Server-visible fields only.
	 *
	 * @param Secret $secret The secret row
	 *
	 * @return DateTime|null The effective expiry (null = never expires)
	 *
	 * @spec openspec/changes/rotation-expiry-policies/specs/rotation-expiry-policies/spec.md
	 */
	public function resolveEffectiveExpiry(Secret $secret): ?DateTime;

	/**
	 * Create or update a type/folder policy for the caller.
	 *
	 * @param string $userId The caller
	 * @param string $scope The scope (`type`|`folder`)
	 * @param string $scopeId The scoped type/folder id
	 * @param int|null $maxAgeDays Max credential age in days (null = reminder-only)
	 * @param int[] $reminderDays Reminder thresholds in days
	 *
	 * @return ExpiryPolicy
	 *
	 * @throws InvalidArgumentException On invalid input
	 *
	 * @spec openspec/changes/rotation-expiry-policies/specs/rotation-expiry-policies/spec.md#requirement-expiry-policies
	 */
	public function upsertPolicy(
		string $userId,
		string $scope,
		string $scopeId,
		?int $maxAgeDays,
		array $reminderDays = [],
	): ExpiryPolicy;
}//end interface
