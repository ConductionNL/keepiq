<?php

/**
 * Keepiq Forbidden Exception
 *
 * Thrown when a requester is not authorised to perform an operation
 * (ownership violation, system-type modification, missing admin rights).
 * Controllers map this to an HTTP 403 response.
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
 * Thrown when a requester is not authorised to perform an operation.
 */
class ForbiddenException extends RuntimeException {
	/**
	 * The machine-readable policy code a refusal carries, if any. A plain
	 * ownership refusal has none; a vault policy refusal names its policy
	 * (admin-vault-policies), so a controller can return it without knowing
	 * the subclass.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/vault-policies/spec.md#requirement-work-logins-are-kept-in-team-folders
	 */
	public function policyCode(): ?string {
		return null;
	}//end policyCode()
}//end class
