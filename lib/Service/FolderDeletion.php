<?php

/**
 * Keepiq Folder Deletion
 *
 * One request to delete a folder.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Service;

use OCA\Keepiq\Db\Folder;

/**
 * The owned folder to delete, the cascade shorthand for a leaf folder, the
 * per-subfolder resolution body and the requesting user; built by
 * FolderService once ownership is verified.
 *
 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
 */
final class FolderDeletion {
	/**
	 * Constructor for FolderDeletion.
	 *
	 * @param Folder                   $folder     The folder to delete (ownership verified)
	 * @param string|null              $cascade    The shorthand cascade mode (delete/move) for leaf folders
	 * @param array<string,mixed>|null $resolution The per-subfolder resolution body
	 * @param string                   $userId     The requesting Nextcloud user ID
	 *
	 * @return void
	 */
	public function __construct(
		public readonly Folder $folder,
		public readonly ?string $cascade,
		public readonly ?array $resolution,
		public readonly string $userId,
	) {
	}//end __construct()
}//end class
