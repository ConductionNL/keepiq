<?php

/**
 * Keepiq Secret Placement
 *
 * Where a new secret row lives: its owner, the encryption suite its
 * ciphertext is sealed for, its resolved type and its folder. Each create
 * path in SecretService works these out under its own rules (a user's
 * folder check and team folder policy, an application's own vault) and
 * hands them to the one row builder.
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

/**
 * The owner, suite, type and folder of a secret about to be created.
 *
 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.1
 */
final class SecretPlacement {

	/**
	 * Capture where the new row lives.
	 *
	 * @param string      $ownerType 'user' or 'application'
	 * @param string      $ownerId   The owning user or application ID
	 * @param string      $suiteId   The owner's active encryption suite
	 * @param string      $typeId    The resolved secret type
	 * @param string|null $folderId  The folder, null for the vault root
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $ownerType,
		public readonly string $ownerId,
		public readonly string $suiteId,
		public readonly string $typeId,
		public readonly ?string $folderId,
	) {
	}//end __construct()
}//end class
