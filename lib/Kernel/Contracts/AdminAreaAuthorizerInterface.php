<?php

/**
 * Contract of AdminAreaAuthorizer
 *
 * The public surface of AdminAreaAuthorizer that other components call, so they depend
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

use OCA\Keepiq\Settings\ApplicationAdminSettings;

/**
 * Contract of AdminAreaAuthorizer.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AdminAreaAuthorizerInterface {

	public const APPLICATIONS = ApplicationAdminSettings::class;

	/**
	 * The area keys $userId holds, in area order.
	 *
	 * @param string $userId The user to check
	 *
	 * @return string[]
	 *
	 * @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#1.3
	 */
	public function areasOf(string $userId): array;

	/**
	 * Whether $userId holds the area of $areaClass: an instance admin holds
	 * every area, and a delegated user the areas delegated to one of their
	 * groups. No group name grants an area by itself (#1043 removed the
	 * `vault_admin` alias for People).
	 *
	 * Fails closed: an unknown area, an unknown user or a failing delegation
	 * lookup answers false.
	 *
	 * @param string $userId The user to check
	 * @param string $areaClass One of the five area classes
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#1.3
	 */
	public function holds(string $userId, string $areaClass): bool;
}//end interface
