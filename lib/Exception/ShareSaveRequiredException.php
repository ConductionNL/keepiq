<?php

/**
 * Keepiq Share-Save Required Exception
 *
 * A value change through the plain update of a row that has copies or
 * partner shares, or of a copy its holder writes for everyone: the change
 * would reach that one row only (keepiq#1300). The client saves it through
 * `PUT /api/v1/secrets/{id}/share-save` instead. Controllers map this to
 * HTTP 409 with the code `share_save_required`.
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
 * Thrown when a value change must go through the share-save.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-a-value-change-of-a-shared-secret-goes-through-the-share-save
 */
class ShareSaveRequiredException extends ConflictException {
	/**
	 * The machine-readable code the refusal carries.
	 */
	public const CODE = 'share_save_required';

	/**
	 * Constructor.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-a-value-change-of-a-shared-secret-goes-through-the-share-save
	 */
	public function __construct() {
		parent::__construct(message: 'This item is shared, and every copy must change with it. Update the app, or make the change in the web app.');
	}//end __construct()
}//end class
