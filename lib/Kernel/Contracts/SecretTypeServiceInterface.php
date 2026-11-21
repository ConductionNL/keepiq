<?php

/**
 * Contract of SecretTypeService
 *
 * The public surface of SecretTypeService that other components call, so they depend
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
use OCA\Keepiq\Db\SecretType;
use OCA\Keepiq\Exception\ConflictException;
use OCA\Keepiq\Exception\ForbiddenException;

/**
 * Contract of SecretTypeService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SecretTypeServiceInterface {

	/**
	 * Create a custom SecretType.
	 *
	 * @param string $name The unique type name
	 * @param string $label The human-readable label
	 * @param string $scope The scope (user or global)
	 * @param string $userId The requesting Nextcloud user ID
	 * @param bool $isAdmin Whether the requester is an administrator
	 * @param array<mixed>|null $fields The fields an item of this type carries (admin-18)
	 *
	 * @return SecretType
	 *
	 * @throws InvalidArgumentException When validation fails
	 * @throws ForbiddenException When a non-admin requests a global type
	 * @throws ConflictException When the name already exists
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-secret-types
	 * @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
	 */
	public function createType(
		string $name,
		string $label,
		string $scope,
		string $userId,
		bool $isAdmin,
		?array $fields=null,
	): SecretType;

	/**
	 * Delete a custom SecretType, reassigning its secrets to the login type.
	 *
	 * @param string $id The type ID
	 * @param string $userId The requesting Nextcloud user ID
	 * @param bool $isAdmin Whether the requester is an administrator
	 *
	 * @return void
	 *
	 * @throws ForbiddenException When the type is a system type or not owned
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-secret-types
	 */
	public function deleteType(string $id, string $userId, bool $isAdmin): void;

	/**
	 * Get every SecretType available to a user (system + global + own user types).
	 *
	 * @param string $userId The Nextcloud user ID
	 *
	 * @return SecretType[]
	 *
	 * @spec openspec/specs/app-shell/spec.md
	 */
	public function getAvailableTypes(string $userId): array;

	/**
	 * Rename (relabel) a custom SecretType the requester is allowed to manage.
	 *
	 * @param string $id The type ID
	 * @param string $label The new label
	 * @param string $userId The requesting Nextcloud user ID
	 * @param bool $isAdmin Whether the requester is an administrator
	 * @param array<mixed>|null $fields The new field list, or null to keep it (admin-18)
	 *
	 * @return SecretType
	 *
	 * @throws ForbiddenException When the type is a system type or not owned
	 * @throws InvalidArgumentException When the label or the field list is invalid
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-secret-types
	 * @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
	 */
	public function updateType(
		string $id,
		string $label,
		string $userId,
		bool $isAdmin,
		?array $fields=null,
	): SecretType;
}//end interface
