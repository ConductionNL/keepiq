<?php

/**
 * Keepiq View-Only Copy Exception
 *
 * A value change of a received copy whose holder may not write its source
 * (keepiq#1287). It is a ForbiddenException, so every caller that maps that
 * to 403 keeps doing so, and it carries the code `view_only`.
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

use OCA\Keepiq\Db\Secret;

/**
 * Thrown when the holder of a view-only copy changes its value.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-view-only-copies
 */
class ViewOnlyCopyException extends ForbiddenException {
	/**
	 * The machine-readable code the refusal carries.
	 */
	public const CODE = 'view_only';

	/**
	 * Constructor.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-view-only-copies
	 */
	public function __construct() {
		parent::__construct(message: Secret::VIEW_ONLY_REFUSAL);
	}//end __construct()

	/**
	 * The code `view_only`.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-view-only-copies
	 */
	public function policyCode(): ?string {
		return self::CODE;
	}//end policyCode()
}//end class
