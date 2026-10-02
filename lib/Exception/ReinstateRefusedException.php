<?php

/**
 * Keepiq Reinstate Refused Exception
 *
 * Thrown when a revoked EncryptionSuite may not be reinstated: it was revoked
 * as compromised, the revocation cannot be confirmed as a non-compromise one,
 * or its owner already has another active suite (keepiq#865). Controllers map
 * this to an HTTP 409 response carrying getError().
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
 * A reinstate refused for a reason the administrator has to see.
 *
 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-suite-revoked-as-compromised-cannot-be-reinstated
 */
class ReinstateRefusedException extends ConflictException {
	/**
	 * Constructor.
	 *
	 * @param string $error   A stable machine-readable reason for the response
	 * @param string $message The human-readable explanation
	 *
	 * @return void
	 */
	public function __construct(private string $error, string $message) {
		parent::__construct(message: $message);
	}//end __construct()

	/**
	 * The machine-readable reason.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-suite-revoked-as-compromised-cannot-be-reinstated
	 */
	public function getError(): string {
		return $this->error;
	}//end getError()
}//end class
