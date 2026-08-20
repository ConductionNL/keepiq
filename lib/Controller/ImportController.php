<?php

/**
 * Doriath Import Controller
 *
 * Authenticated batch-create endpoint for the client-side import pipeline
 * (secret-import D7). The browser parses, maps, deduplicates, and ENCRYPTS every
 * sensitive field locally, then POSTs ciphertext-only payloads here in bounded
 * chunks. The endpoint:
 *
 *   - derives ownership exclusively from the session user (no owner/user param
 *     is accepted — IDOR-safe by construction, ADR-005),
 *   - returns per-index results with HTTP 200 on partial failure so one invalid
 *     item never fails its 49 neighbours,
 *   - rejects oversized chunks (413) and a missing active suite (412).
 *
 * The server never receives plaintext secret values (ADR-003): key/login/
 * additionalFields arrive as RSA ciphertext envelopes and are stored verbatim.
 *
 * @category Controller
 * @package  OCA\Doriath\Controller
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

namespace OCA\Doriath\Controller;

use InvalidArgumentException;
use OCA\Doriath\AppInfo\Application;
use OCA\Doriath\Exception\SuiteBlockedException;
use OCA\Doriath\Service\ImportService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Authenticated batch-create endpoint for client-encrypted import.
 */
class ImportController extends OCSController {
	/**
	 * HTTP 412 Precondition Failed status code (no active suite).
	 *
	 * @var int
	 */
	private const STATUS_PRECONDITION_FAILED = 412;

	/**
	 * HTTP 413 Payload Too Large status code (chunk over the cap).
	 *
	 * @var int
	 */
	private const STATUS_PAYLOAD_TOO_LARGE = 413;

	/**
	 * Constructor for ImportController.
	 *
	 * @param IRequest $request The request
	 * @param ImportService $importService The import service
	 * @param IUserSession $userSession The user session
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private ImportService $importService,
		private IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Commit one chunk of already-encrypted import items.
	 *
	 * Owner is the session user only; the request body carries no owner/user
	 * selector. Validates the chunk size cap, then delegates each item to the
	 * import service and returns per-index results.
	 *
	 * @param array<int,array<string,mixed>> $items The encrypted items
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/secret-import/specs/secret-import/spec.md#requirement-chunked-batch-commit
	 */
	#[NoAdminRequired]
	public function batchCreate(?array $items = null): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		if ($items === null || $items === []) {
			return new JSONResponse(
				data: ['message' => 'No items supplied'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		if (count($items) > ImportService::MAX_ITEMS) {
			return new JSONResponse(
				data: ['message' => 'Chunk exceeds the maximum of ' . ImportService::MAX_ITEMS . ' items'],
				statusCode: self::STATUS_PAYLOAD_TOO_LARGE
			);
		}

		try {
			$result = $this->importService->commitChunk(items: $items, userId: $user->getUID());
		} catch (SuiteBlockedException $e) {
			return new JSONResponse(
				data: ['message' => $e->getMessage()],
				statusCode: self::STATUS_PRECONDITION_FAILED
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(
				data: ['message' => $e->getMessage()],
				statusCode: self::STATUS_PAYLOAD_TOO_LARGE
			);
		}

		// HTTP 200 even on partial failure — per-index results carry the detail.
		return new JSONResponse(data: $result);
	}//end batchCreate()
}//end class
