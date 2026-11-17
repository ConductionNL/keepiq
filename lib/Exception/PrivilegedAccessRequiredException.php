<?php

/**
 * Keepiq PrivilegedAccessRequiredException
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
 * Thrown when a recipient asks for their copy of a privileged login outside
 * an approved window or address range.
 */
class PrivilegedAccessRequiredException extends ForbiddenException {
	/**
	 * The code the client reads to offer an access request.
	 *
	 * @var string
	 */
	public const CODE = 'privileged_access_required';

	/**
	 * The machine-readable refusal code.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
	 */
	public function policyCode(): ?string {
		return self::CODE;
	}//end policyCode()
}//end class
