<?php

/**
 * Contract of DashboardSummaryService
 *
 * The public surface of DashboardSummaryService that other components call, so they depend
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
 * Contract of DashboardSummaryService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DashboardSummaryServiceInterface {

	/**
	 * Aggregate the dashboard summary for the given user.
	 *
	 * The shape matches the OpenAPI contract sketched in
	 * implement-dashboard-settings (§D6 fetchSummary):
	 *
	 * - total_secrets: count of secrets owned by the user
	 * - shared_with_me_count: count of secret shares targeting the user
	 * - folders_count: count of folders owned by the user
	 * - pending_apps_count: admin-only, omitted for non-admins (null)
	 * - last_updated: ISO-8601 timestamp of the aggregation
	 *
	 * Mapper failures are logged and degraded to zero so a partial DB
	 * outage cannot wipe the dashboard render.
	 *
	 * @param string $userId The Nextcloud user ID
	 * @param bool $isAdmin Whether the caller is an admin
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/dashboard/spec.md#requirement-vault-summary-cards-mvp
	 * @spec openspec/specs/dashboard/spec.md#requirement-pending-applications-counter-admin-mvp
	 */
	public function fetchSummary(string $userId, bool $isAdmin): array;
}//end interface
