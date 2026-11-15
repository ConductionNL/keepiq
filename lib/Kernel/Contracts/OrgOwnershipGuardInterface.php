<?php

/**
 * Contract of OrgOwnershipGuard
 *
 * The public surface of OrgOwnershipGuard that other components call, so they depend
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
 * Contract of OrgOwnershipGuard.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface OrgOwnershipGuardInterface {

	/**
	 * The user's own live secrets that break the ownership policy: covered
	 * type, outside an owned team folder, and not a copy received from
	 * someone else. Metadata only, for the health report (design D6).
	 *
	 * @param string $userId The session user
	 *
	 * @return array<int,array{id:string,name:string,typeId:string,folderId:string|null}>
	 *
	 * @spec openspec/specs/vault-policies/spec.md#requirement-users-see-personal-items-that-break-the-ownership-policy
	 */
	public function findings(string $userId): array;
}//end interface
