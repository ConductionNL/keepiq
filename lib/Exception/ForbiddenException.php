<?php

/**
 * Doriath Forbidden Exception
 *
 * Thrown when a requester is not authorised to perform an operation
 * (ownership violation, system-type modification, missing admin rights).
 * Controllers map this to an HTTP 403 response.
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
 * Thrown when a requester is not authorised to perform an operation.
 */
class ForbiddenException extends RuntimeException
{
}//end class
