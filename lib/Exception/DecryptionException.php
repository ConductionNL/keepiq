<?php

/**
 * Doriath Decryption Exception
 *
 * Thrown when decryption fails due to GCM auth failure, invalid format, or wrong key.
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
 * Thrown when decryption fails due to GCM auth failure, invalid format, or wrong key.
 */
class DecryptionException extends RuntimeException {
}//end class
