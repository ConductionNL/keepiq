<?php

/**
 * Keepiq Partner Unreachable Exception
 *
 * A partner's discovery document could not be fetched. Unlike the other refusals of a partner's document, this one may
 * pass, so a root renewal check that meets it keeps what it has and lets the sender retry.
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

use InvalidArgumentException;

/**
 * Thrown when a partner's discovery document cannot be fetched.
 *
 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-announced-renewal-is-offered-to-administrators-after-its-signature-checks-out
 */
class PartnerUnreachableException extends InvalidArgumentException {
	/**
	 * The message an administrator sees.
	 *
	 * @var string
	 */
	public const MESSAGE = 'The partner could not be reached';
}//end class
