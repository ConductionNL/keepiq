<?php

/**
 * Contract of LeaseService
 *
 * The public surface of LeaseService that other components call, so they depend
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

use OCA\Keepiq\Db\MachineLease;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Contract of LeaseService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LeaseServiceInterface {

	/**
	 * The effective lease policy of an application — resolved by
	 * LeasePolicyService, exposed here because the lease API surface is
	 * the caller of record for policy reads.
	 *
	 * @param string $applicationId The application id
	 *
	 * @return array{defaultTtl:int, maxTtl:int, renewable:bool, blockOnRevoke:bool}
	 *
	 * @spec openspec/changes/machine-secret-leases/specs/machine-secret-leases/spec.md
	 */
	public function effectivePolicy(string $applicationId): array;

	/**
	 * Transition past-expiry active leases to `expired` (hourly job) and
	 * raise a rotation flag per affected secret.
	 *
	 * @return int Leases expired
	 *
	 * @spec openspec/changes/machine-secret-leases/specs/machine-secret-leases/spec.md#requirement-lease-expiry
	 */
	public function expireDue(): int;

	/**
	 * All leases of an application, newest first.
	 *
	 * @param string $applicationId The application id
	 *
	 * @return MachineLease[]
	 *
	 * @spec openspec/specs/machine-secret-leases/spec.md#requirement-lease-revocation-by-admin-owner-or-application
	 */
	public function listForApplication(string $applicationId): array;

	/**
	 * Load a lease owned by an application; a foreign lease throws the
	 * SAME exception as a missing one (no existence oracle).
	 *
	 * @param string $leaseId The lease UUID
	 * @param string $applicationId The claiming application
	 *
	 * @return MachineLease
	 *
	 * @throws DoesNotExistException When missing or foreign
	 *
	 * @spec openspec/specs/machine-secret-leases/spec.md#requirement-lease-revocation-by-admin-owner-or-application
	 */
	public function loadOwned(string $leaseId, string $applicationId): MachineLease;

	/**
	 * The lease policy of one application as an admin form needs it: what is
	 * in force, the stored override (null fields inherit), and the instance
	 * values those nulls fall back to.
	 *
	 * @param string $applicationId The application id
	 *
	 * @return array{effective: array<string,int|bool>, override: array<string,int|bool|null>, instance: array<string,int|bool>}
	 *
	 * @spec openspec/specs/machine-secret-leases/spec.md#requirement-admin-lease-ttl-policy
	 */
	public function policyView(string $applicationId): array;

	/**
	 * Revoke a lease (admin / owner / holding application) and raise a
	 * rotation flag on the leased secret — a revocation implies the
	 * holder should no longer be trusted with the current value.
	 *
	 * @param MachineLease $lease The lease row (caller pre-authorized)
	 * @param string $actor The revoking actor (user id or 'self')
	 *
	 * @return MachineLease
	 *
	 * @spec openspec/changes/machine-secret-leases/specs/machine-secret-leases/spec.md#requirement-lease-revocation
	 */
	public function revoke(MachineLease $lease, string $actor): MachineLease;

	/**
	 * Store a per-application policy override (admin surface) — validated
	 * and persisted by LeasePolicyService.
	 *
	 * @param string $applicationId The application id
	 * @param int|null $defaultTtl Default TTL seconds (null = inherit)
	 * @param int|null $maxTtl Max TTL seconds (null = inherit)
	 * @param bool|null $renewable Renewability (null = inherit)
	 *
	 * @return void
	 *
	 * @spec openspec/changes/machine-secret-leases/specs/machine-secret-leases/spec.md
	 */
	public function setPolicyOverride(string $applicationId, ?int $defaultTtl, ?int $maxTtl, ?bool $renewable): void;
}//end interface
