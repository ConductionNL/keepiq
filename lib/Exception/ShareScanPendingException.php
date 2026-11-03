<?php

/**
 * Keepiq Share Scan Pending Exception
 *
 * Thrown when an owner's revoke, restriction change or delete reaches a share
 * row the pending share quarantine scan could not judge (GHSA-m5p9-6pw7-pf3g),
 * so its copy is held back until the scan runs. Controllers map this to an
 * HTTP 409 response.
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
 * Thrown when the pending share quarantine scan holds a share row back.
 */
class ShareScanPendingException extends ConflictException {
}//end class
