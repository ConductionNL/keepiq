<?php

/**
 * Keepiq Stale Share Source Exception
 *
 * A share was encrypted from a version of its source that is no longer the current one (keepiq#1255).
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

use DateTime;
use RuntimeException;

/**
 * Thrown inside the share transaction when the source moved on since the browser encrypted the copy, so the
 * transaction rolls back with nothing written.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-a-share-is-made-from-the-current-version
 */
class StaleShareSourceException extends RuntimeException {
	/**
	 * Constructor for StaleShareSourceException.
	 *
	 * @param DateTime|null $current The source's current version, null when the source is gone
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-a-share-is-made-from-the-current-version
	 */
	public function __construct(public readonly ?DateTime $current) {
		parent::__construct(message: 'stale_source');
	}//end __construct()

	/**
	 * The current version as the API writes it, null when the source is gone.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-a-share-is-made-from-the-current-version
	 */
	public function currentVersion(): ?string {
		return $this->current?->format(DATE_ATOM);
	}//end currentVersion()
}//end class
