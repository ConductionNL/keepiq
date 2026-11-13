<?php

/**
 * Contract of FolderService
 *
 * The public surface of FolderService that other components call, so they depend
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
use OCA\Keepiq\Db\Folder;
use OCA\Keepiq\Exception\ConflictException;
use OCA\Keepiq\Exception\DuplicateFolderNameException;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;

/**
 * Contract of FolderService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FolderServiceInterface {

	/**
	 * Create a folder.
	 *
	 * @param string $name The folder name (no slashes)
	 * @param string|null $parentId The parent folder ID (null = root)
	 * @param string $userId The owning Nextcloud user ID
	 * @param string|null $customIcon Optional custom icon key
	 * @param string|null $customColor Optional custom color key
	 *
	 * @return Folder
	 *
	 * @throws InvalidArgumentException When the name or an attribute key is invalid
	 * @throws NotFoundException When the parent does not exist
	 * @throws ForbiddenException When the parent is not owned
	 * @throws DuplicateFolderNameException When a sibling folder already uses the name
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
	 */
	public function create(
		string $name,
		?string $parentId,
		string $userId,
		?string $customIcon = null,
		?string $customColor = null,
	): Folder;

	/**
	 * Delete a folder, applying the appropriate protocol.
	 *
	 * Ownership is verified here; the protocol itself lives in
	 * FolderDeletionService.
	 *
	 * @param string $id The folder ID
	 * @param string|null $cascade The shorthand cascade mode (delete/move) for leaf folders
	 * @param array<string,mixed>|null $resolution The per-subfolder resolution body
	 * @param string $userId The requesting Nextcloud user ID
	 *
	 * @return void
	 *
	 * @throws NotFoundException When the folder does not exist
	 * @throws ForbiddenException When not owned
	 * @throws ConflictException When a non-empty folder is deleted without a plan
	 * @throws InvalidArgumentException When the resolution body is incomplete
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.2
	 */
	public function delete(string $id, ?string $cascade, ?array $resolution, string $userId): void;

	/**
	 * Build the children payload for the resolution dialog: the folder's
	 * direct secret count and its direct subfolders with recursive counts.
	 * Ownership is verified here; the payload is built by
	 * FolderDeletionService.
	 *
	 * @param string $id The folder ID
	 * @param string $userId The requesting Nextcloud user ID
	 *
	 * @return array<string,mixed>
	 *
	 * @throws NotFoundException When the folder does not exist
	 * @throws ForbiddenException When the folder is not owned
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.2
	 */
	public function getChildren(string $id, string $userId): array;

	/**
	 * Load a folder and verify the requester owns it.
	 *
	 * @param string $id The folder ID
	 * @param string $userId The requesting Nextcloud user ID
	 *
	 * @return Folder
	 *
	 * @throws NotFoundException When the folder does not exist
	 * @throws ForbiddenException When the folder belongs to another user
	 *
	 * @spec exclude Ownership guard delegated to FolderOwnershipGuard; no spec behaviour of its own.
	 */
	public function getOwned(string $id, string $userId): Folder;

	/**
	 * List the folders owned by a user.
	 *
	 * @param string $userId The Nextcloud user ID
	 *
	 * @return Folder[]
	 *
	 * @spec exclude Plain owner-scoped listing; no dedicated spec requirement.
	 */
	public function listForUser(string $userId): array;

	/**
	 * Move a folder to a different parent (or to root).
	 *
	 * @param string $id The folder ID
	 * @param string|null $newParentId The target parent ID (null = root)
	 * @param string $userId The requesting Nextcloud user ID
	 *
	 * @return Folder
	 *
	 * @throws InvalidArgumentException When the move would create a cycle
	 * @throws NotFoundException When the folder or target does not exist
	 * @throws ForbiddenException When the folder or target is not owned
	 * @throws DuplicateFolderNameException When the target parent already contains the name
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
	 */
	public function move(string $id, ?string $newParentId, string $userId): Folder;

	/**
	 * Rename a folder.
	 *
	 * @param string $id The folder ID
	 * @param string $name The new name
	 * @param string $userId The requesting Nextcloud user ID
	 *
	 * @return Folder
	 *
	 * @throws InvalidArgumentException When the name is invalid
	 * @throws NotFoundException When the folder does not exist
	 * @throws ForbiddenException When not owned
	 * @throws DuplicateFolderNameException When a sibling folder already uses the name
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
	 */
	public function rename(string $id, string $name, string $userId): Folder;

	/**
	 * Update presentation attributes (customIcon, customColor) on a folder.
	 *
	 * Only keys PRESENT in $changes are applied; an explicit null CLEARS the
	 * value, an absent key leaves it untouched — so a rename/move request
	 * that carries no attribute keys can never accidentally wipe a color.
	 *
	 * Values are validated on FORMAT only (lowercase kebab key, max 64
	 * chars): the frontend owns the catalogs, and an unknown key renders as
	 * the fallback glyph there — which keeps old servers forward-compatible
	 * with newer frontends.
	 *
	 * @param string $id The folder ID
	 * @param array<string,string|null> $changes Attribute name to new value (null clears)
	 * @param string $userId The requesting Nextcloud user ID
	 *
	 * @return Folder
	 *
	 * @throws InvalidArgumentException When a value fails the format check
	 * @throws NotFoundException When the folder does not exist
	 * @throws ForbiddenException When the folder is not owned
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
	 */
	public function updateAttributes(string $id, array $changes, string $userId): Folder;
}//end interface
