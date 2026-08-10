<?php

/**
 * Doriath Folder Name Guard
 *
 * Sibling-uniqueness of folder names. Siblings share the same owner and the
 * same parent, so the guard is the single place that knows what "already
 * exists in this location" means. Both the folder tree operations (create,
 * rename, move) and the delete-resolution `keep` action re-parent folders and
 * therefore have to run the same check.
 *
 * @category Service
 * @package  OCA\Doriath\Service
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

namespace OCA\Doriath\Service;

use OCA\Doriath\Db\FolderMapper;
use OCA\Doriath\Exception\DuplicateFolderNameException;

/**
 * Enforces sibling-uniqueness of folder names.
 */
class FolderNameGuard
{
    /**
     * Constructor for FolderNameGuard.
     *
     * @param FolderMapper $mapper The folder mapper
     *
     * @return void
     *
     * @spec exclude Constructor wiring only.
     */
    public function __construct(private FolderMapper $mapper)
    {
    }//end __construct()

    /**
     * Assert that a folder name is unique among its siblings.
     *
     * Siblings share the same owner and the same parent. Pass $excludeId to
     * ignore the folder being renamed, moved, or re-parented so it does not
     * conflict with itself.
     *
     * @param string      $ownerType The owner type
     * @param string      $ownerId   The owner ID
     * @param string|null $parentId  The parent folder ID, or null for root level
     * @param string      $name      The folder name to check
     * @param string|null $excludeId A folder ID to exclude from the check, or null
     *
     * @return void
     *
     * @throws DuplicateFolderNameException When a sibling with the same name exists
     *
     * @spec exclude Rescued bugfix (PR #22) — folder-name uniqueness has no dedicated spec requirement yet.
     */
    public function assertNameUnique(
        string $ownerType,
        string $ownerId,
        ?string $parentId,
        string $name,
        ?string $excludeId=null,
    ): void {
        $exists = $this->mapper->existsInParent(
            ownerType: $ownerType,
            ownerId: $ownerId,
            parentId: $parentId,
            name: $name,
            excludeId: $excludeId
        );

        if ($exists === true) {
            throw new DuplicateFolderNameException(
                message: "A folder named '{$name}' already exists in this location"
            );
        }
    }//end assertNameUnique()
}//end class
