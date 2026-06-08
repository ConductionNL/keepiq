<?php

/**
 * Doriath Duplicate Folder Name Exception
 *
 * Thrown when a folder name would collide with an existing sibling in the same parent.
 *
 * @category Exception
 * @package  OCA\Doriath\Exception
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

namespace OCA\Doriath\Exception;

use RuntimeException;

/**
 * Thrown when a folder name would collide with an existing sibling in the same parent.
 */
class DuplicateFolderNameException extends RuntimeException
{
}//end class
