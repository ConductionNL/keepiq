<?php

/**
 * Doriath Audit Forbidden Metadata Exception
 *
 * Thrown by AuditService::record when a caller attempts to record an audit
 * entry whose metadata contains a forbidden key (key, login, password, value,
 * additionalFields, ciphertext, payload) in any position. This is the
 * structural enforcement of the no-secret-material guarantee
 * (add-secret-audit-trail design D3): a future dispatch site cannot
 * accidentally leak secret material into the trail.
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
 * Thrown when audit metadata contains a forbidden secret-material key.
 */
class AuditForbiddenMetadataException extends RuntimeException {
}//end class
