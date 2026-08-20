<?php

/**
 * Doriath Conflict Exception
 *
 * Thrown when an operation conflicts with existing state (duplicate type
 * name, non-empty folder deletion without a cascade plan). Controllers map
 * this to an HTTP 409 response.
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
 * Thrown when an operation conflicts with existing state.
 */
class ConflictException extends RuntimeException {
}//end class
