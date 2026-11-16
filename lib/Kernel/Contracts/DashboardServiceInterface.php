<?php

/**
 * Contract of DashboardService
 *
 * The public surface of DashboardService that other components call, so they depend
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
use OCA\Keepiq\Db\DashboardSetting;

/**
 * Contract of DashboardService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DashboardServiceInterface {

	/**
	 * Cascade-delete all settings for a user (account deletion hook).
	 *
	 * @param string $userId The Nextcloud user ID
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-settings/spec.md#requirement-default-view-preference-v1
	 */
	public function deleteAllForUser(string $userId): void;

	/**
	 * Get a single setting for a user (returns null if unset).
	 *
	 * @param string $userId The Nextcloud user ID
	 * @param string $settingKey The setting key
	 *
	 * @return mixed The decoded value, or null when no row exists.
	 *
	 * @throws InvalidArgumentException When the key is not whitelisted.
	 *
	 * @spec openspec/specs/user-settings/spec.md#requirement-default-view-preference-v1
	 */
	public function get(string $userId, string $settingKey): mixed;

	/**
	 * List all settings for a user as a flat key=>value array.
	 *
	 * @param string $userId The Nextcloud user ID
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/user-settings/spec.md#requirement-default-view-preference-v1
	 */
	public function listForUser(string $userId): array;

	/**
	 * Set (insert-or-update) a single setting for a user.
	 *
	 * @param string $userId The Nextcloud user ID
	 * @param string $settingKey The setting key
	 * @param mixed $value The value to persist (will be JSON-encoded)
	 *
	 * @return DashboardSetting The persisted row.
	 *
	 * @throws InvalidArgumentException When validation fails.
	 *
	 * @spec openspec/specs/user-settings/spec.md#requirement-default-view-preference-v1
	 * @spec openspec/specs/user-settings/spec.md#requirement-default-secret-type-v1
	 */
	public function set(string $userId, string $settingKey, mixed $value): DashboardSetting;

	/**
	 * Bulk-set a map of settings for a user. Unknown keys raise.
	 *
	 * @param string $userId The Nextcloud user ID
	 * @param array<string,mixed> $settings The settings to persist
	 *
	 * @return array<string,mixed> The full settings map for the user post-update.
	 *
	 * @throws InvalidArgumentException
	 *
	 * @spec openspec/specs/user-settings/spec.md#requirement-default-view-preference-v1
	 */
	public function setMany(string $userId, array $settings): array;
}//end interface
