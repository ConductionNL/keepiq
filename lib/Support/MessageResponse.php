<?php

/**
 * Keepiq MessageResponse
 *
 * @category Support
 * @package  OCA\Keepiq\Support
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

namespace OCA\Keepiq\Support;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use Throwable;

/**
 * The JSON answer a controller gives for a caught error: its message under
 * `message`, with the status the caller picks.
 *
 * @extends JSONResponse<Http::STATUS_*, array{message: string}, array<string, mixed>>
 *
 * @spec exclude Response formatting shared by the controllers
 */
final class MessageResponse extends JSONResponse {

	/**
	 * A JSON response carrying the error's message.
	 *
	 * @param Throwable      $error  The caught error
	 * @param Http::STATUS_* $status The HTTP status
	 *
	 * @return void
	 *
	 * @spec exclude Response formatting shared by the controllers
	 */
	public function __construct(Throwable $error, int $status) {
		parent::__construct(
			data: ['message' => $error->getMessage()],
			statusCode: $status
		);
	}//end __construct()
}//end class
