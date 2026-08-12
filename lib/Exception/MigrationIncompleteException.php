<?php

/**
 * Doriath Migration Incomplete Exception
 *
 * Thrown when a compromise-recovery migration is asked to terminate while rows
 * in one or more suite-bound stores are still encrypted under the old suite.
 * Terminating would mark the old suite compromised and make exactly those rows
 * permanently unreadable, so the migration stays `in_progress` and resumable
 * instead. Controllers map this to an HTTP 409 response.
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
 * Thrown when a migration cannot yet be terminated.
 */
class MigrationIncompleteException extends RuntimeException {
}//end class
