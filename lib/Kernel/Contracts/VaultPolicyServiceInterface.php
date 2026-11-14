<?php

/**
 * Contract of VaultPolicyService
 *
 * The public surface of VaultPolicyService that other components call, so they depend
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

/**
 * Contract of VaultPolicyService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface VaultPolicyServiceInterface {

	/**
	 * Policy: block personal vault export.
	 */
	public const EXPORT_DISABLED = 'vault_export_disabled';

	/**
	 * Whether a policy is on and its group scope covers the user.
	 *
	 * @param string $policy One of POLICIES
	 * @param string $userId The user
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/vault-policies/spec.md#requirement-administrator-configures-vault-policies-per-group
	 */
	public function appliesTo(string $policy, string $userId): bool;
}//end interface
