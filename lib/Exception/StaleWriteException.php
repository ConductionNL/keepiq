<?php

/**
 * Keepiq StaleWriteException
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
use RuntimeException;

/**
 * Thrown when a write names the version it was based on (`baseUpdatedAt`) and
 * the secret changed since. Nothing was written; the caller answers 409 with
 * the current row so the client can let the user choose (offline-edit-queue).
 *
 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-concurrent-server-changes-are-never-overwritten-silently
 */
class StaleWriteException extends RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param Secret $current The secret as it is now
	 *
	 * @return void
	 */
	public function __construct(private Secret $current) {
		parent::__construct(message: 'This secret changed since your copy was made');
	}//end __construct()

	/**
	 * The secret as it is now.
	 *
	 * @return Secret
	 */
	public function getCurrent(): Secret {
		return $this->current;
	}//end getCurrent()
}//end class
