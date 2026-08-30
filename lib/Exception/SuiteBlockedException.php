<?php

/**
 * Keepiq Suite Blocked Exception
 *
 * Thrown when a secret's encryption suite is revoked or compromised and the
 * encrypted fields may therefore not be returned. Controllers map this to
 * an HTTP 403 response with a descriptive reason.
 *
 * @category Exception
 * @package  OCA\Keepiq\Exception
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

namespace OCA\Keepiq\Exception;

use RuntimeException;

/**
 * Thrown when a secret's encryption suite is revoked or compromised.
 */
class SuiteBlockedException extends RuntimeException {
}//end class
