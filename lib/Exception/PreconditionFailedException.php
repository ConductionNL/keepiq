<?php

/**
 * Keepiq PreconditionFailedException
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
 * Thrown when a machine write-back names a condition (an `If-Match` header)
 * that the row it would write does not meet. Nothing was written; the
 * controller answers 412 with the current row's ETag.
 *
 * @spec openspec/specs/secret-store-api/spec.md#requirement-conditional-machine-write-back
 */
class PreconditionFailedException extends RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param Secret $current The secret as it is now, read from the application's own vault
	 *
	 * @return void
	 */
	public function __construct(private Secret $current) {
		parent::__construct(message: 'The secret changed since it was read; read it again before writing');
	}//end __construct()

	/**
	 * The secret as it is now.
	 *
	 * @return Secret
	 *
	 * @spec openspec/specs/secret-store-api/spec.md#requirement-conditional-machine-write-back
	 */
	public function getCurrent(): Secret {
		return $this->current;
	}//end getCurrent()
}//end class
