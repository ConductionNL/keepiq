<?php

/**
 * Doriath CA Unavailable Exception
 *
 * Thrown when the Certificate Authority cannot issue certificates — the CA is
 * degraded, not yet bootstrapped, or its stored private key can no longer be
 * decrypted. Controllers map this to an HTTP 503: the caller's request was
 * well-formed, so it is a server-side availability fault rather than a client
 * error, and retrying the same request once the CA is repaired will succeed.
 *
 * The message carried by this exception is authored by Doriath and is safe to
 * return to a client. It exists precisely so that controllers can distinguish
 * a deliberate, describable CA fault from an arbitrary internal RuntimeException
 * (Nextcloud's own crypto layer throws \RuntimeException, e.g. "HMAC does not
 * match.", which must never reach a client verbatim).
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
 * Thrown when the CA cannot issue certificates.
 */
class CaUnavailableException extends RuntimeException
{
}//end class
