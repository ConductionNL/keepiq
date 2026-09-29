<?php

/**
 * Keepiq Suite Migration In Progress Exception
 *
 * Thrown when a suite is revoked while it is either end of a key migration that
 * is still in progress. Controllers map this to an HTTP 409 response.
 *
 * @category Exception
 * @package  OCA\Keepiq\Exception
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

namespace OCA\Keepiq\Exception;

/**
 * Thrown when a suite that is part of an in-progress migration is revoked.
 */
class SuiteMigrationInProgressException extends ConflictException {
}//end class
