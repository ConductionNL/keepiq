<?php

/**
 * Keepiq Owner Only Exception
 *
 * Thrown when a team-folder manager tries something only the folder owner may
 * do: make a member a manager, or change or remove a manager. It extends
 * InvalidArgumentException so every caller that already refuses an invalid
 * membership change still refuses this one; the controllers answer it as a
 * forbidden refusal with the code `owner_only`.
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
 * A membership change only the team folder's owner may make.
 *
 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-only-the-owner-governs-managers-and-the-folder-itself
 */
class OwnerOnlyException extends InvalidArgumentException {
	/**
	 * The machine-readable code the refusal carries.
	 */
	public const CODE = 'owner_only';
}//end class
