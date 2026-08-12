<?php

/**
 * Doriath Write Locked Exception
 *
 * Thrown when a write is attempted while a compromise-recovery migration is
 * in progress for the owner. Controllers map this to an HTTP 423 response.
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
 * Thrown when a write is attempted during a compromise-recovery migration.
 */
class WriteLockedException extends RuntimeException {
}//end class
