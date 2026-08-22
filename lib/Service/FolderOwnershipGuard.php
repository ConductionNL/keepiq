<?php

/**
 * Keepiq Folder Ownership Guard
 *
 * The single place that answers "may this user act on this folder?". Every
 * folder operation starts here: a missing folder and a folder belonging to
 * someone else are distinguished (NotFoundException vs ForbiddenException),
 * and the resolved entity is handed back so the caller never re-reads it.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Service;

use OCA\Keepiq\Db\Folder;
use OCA\Keepiq\Db\FolderMapper;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;

/**
 * Resolves a folder and asserts the requester owns it.
 */
class FolderOwnershipGuard {
	/**
	 * Constructor for FolderOwnershipGuard.
	 *
	 * @param FolderMapper $mapper The folder mapper
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private FolderMapper $mapper,
	) {
	}//end __construct()

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
	 * @spec exclude Ownership guard extracted verbatim from FolderService::getOwned().
	 */
	public function requireOwned(string $id, string $userId): Folder {
		try {
			$folder = $this->mapper->findById($id);
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			throw new NotFoundException(message: 'Folder not found');
		}

		if ($folder->getOwnerType() !== 'user' || $folder->getOwnerId() !== $userId) {
			throw new ForbiddenException(message: 'Folder belongs to another user');
		}

		return $folder;
	}//end requireOwned()
}//end class
