<?php

/**
 * Keepiq Manager Only Exception
 *
 * Thrown when a member who is neither the team folder's owner nor a manager
 * tries to manage it, for example a viewer or an editor changing another
 * member's grade. It extends InvalidArgumentException so every caller that
 * already refuses an invalid membership change still refuses this one; the
 * member controller answers it as a forbidden refusal with the code
 * `manager_only`.
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
 * A membership change only the owner or a manager may make.
 *
 * @spec openspec/specs/folder-permission-grades/spec.md#scenario-non-owner-cannot-change-a-grade
 */
class ManagerOnlyException extends InvalidArgumentException {
	/**
	 * The machine-readable code the refusal carries.
	 */
	public const CODE = 'manager_only';
}//end class
