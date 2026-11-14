<?php

/**
 * Contract of RecentlyUsedService
 *
 * The public surface of RecentlyUsedService that other components call, so they depend
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
 * Contract of RecentlyUsedService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RecentlyUsedServiceInterface {

	/**
	 * The secrets a user read most recently, newest first, one row per secret.
	 *
	 * A secret that no longer exists, that another user now owns or that was
	 * tombstoned is left out, so a row always opens something.
	 *
	 * @param string $userId The user
	 *
	 * @return list<array{id: string, name: string, typeId: string, lastUsedAt: string}>
	 *
	 * @spec openspec/specs/vault-recently-used/spec.md#requirement-recently-used-on-the-dashboard
	 */
	public function forUser(string $userId): array;
}//end interface
