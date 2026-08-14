<?php

/**
 * Doriath Secret Request Fill Controller
 *
 * Public (unauthenticated) API controller backing the secret-request
 * fill-in flow. The recipient receives a one-time URL that contains an
 * opaque token; this controller resolves the token to the requested
 * field list + the application's RSA public certificate (Phase 1) and
 * accepts the encrypted field map back from the browser (Phase 2).
 *
 * Plaintext NEVER reaches the server: the browser imports the public
 * key, encrypts each field with RSA-OAEP-SHA256, and POSTs the resulting
 * base64 blobs. The service stores them as the canonical encrypted
 * blobs on the underlying Secret.
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
use OCA\Doriath\Service\EncryptionSuiteService;
use OCA\Doriath\Service\SecretRequestService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use Throwable;

/**
 * Public controller for the SecretRequest fill-in flow.
 *
 * @spec openspec/changes/implement-secret-requests/tasks.md#task-4.2
 */
class SecretRequestFillController extends OCSController {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object
	 * @param SecretRequestService $secretRequestService The secret-request service
	 * @param EncryptionSuiteService $suiteService The suite lookup service
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private SecretRequestService $secretRequestService,
		private EncryptionSuiteService $suiteService,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Phase 1: resolve a token to its public metadata.
	 *
	 * Returns the list of requested fields, the public certificate the
	 * browser must import to encrypt the responses, and the current
	 * status so the page can render "expired" / "fulfilled" /
	 * "temporarily unavailable" messages without leaking ownership.
	 *
	 * @param string $token The opaque request token from the URL
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-secret-requests/tasks.md#task-4.2
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function show(string $token): JSONResponse {
		try {
			$request = $this->secretRequestService->getByToken(token: $token);
		} catch (InvalidArgumentException $e) {
			$status = $e->getCode();
			if ($status <= 0) {
				$status = Http::STATUS_NOT_FOUND;
			}

			return new JSONResponse(
				data: ['message' => $e->getMessage()],
				statusCode: $status
			);
		} catch (Throwable) {
			return new JSONResponse(
				data: ['message' => 'Request not found'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		try {
			$suite = $this->suiteService->getSuite(id: $request->getEncryptionSuiteId());
		} catch (Throwable) {
			return new JSONResponse(
				data: ['message' => 'Recipient encryption suite is unavailable'],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		$requestedFields = json_decode(json: $request->getRequestedFields(), associative: true);
		if (is_array($requestedFields) === false) {
			$requestedFields = [];
		}

		return new JSONResponse(
			data: [
				'token' => $request->getToken(),
				'status' => $request->getStatus(),
				'requested_fields' => $requestedFields,
				'is_re_request' => $request->getIsReRequest(),
				'expires_at' => $request->getExpiresAt()?->format(\DateTime::ATOM),
				'public_certificate' => $suite->getCertificate(),
			]
		);
	}//end show()

	/**
	 * Phase 2: accept the encrypted field map back from the browser.
	 *
	 * The body is expected to be `{ encrypted_fields: { fieldName: base64,
	 * ... } }`. Each value is treated as opaque ciphertext and persisted
	 * directly via `SecretRequestService::fill`. Plaintext NEVER reaches
	 * this controller.
	 *
	 * @param string $token The opaque request token
	 * @param array<string, string> $encryptedFields The encrypted field map
	 * @param array|null $plainFields Plaintext metadata (url); unencrypted by design
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-secret-requests/tasks.md#task-4.2
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function fill(
		string $token,
		?array $encryptedFields = null,
		?array $plainFields = null,
	): JSONResponse {
		// A missing field body is a client validation error, not a 500. Without
		// a nullable default, NC's dispatcher passes null for an omitted
		// `encryptedFields` and PHP raises a TypeError before the body runs.
		if ($encryptedFields === null || $encryptedFields === []) {
			return new JSONResponse(
				data: ['message' => 'encryptedFields is required'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$entity = $this->secretRequestService->fill(
				token: $token,
				encryptedFields: $encryptedFields,
				// Plaintext metadata (url) by design: encrypting it would put
				// ciphertext in a searchable column.
				plainFields: ($plainFields ?? [])
			);
		} catch (InvalidArgumentException $e) {
			$status = $e->getCode();
			if ($status < 400) {
				$status = Http::STATUS_BAD_REQUEST;
			}

			return new JSONResponse(
				data: ['message' => $e->getMessage()],
				statusCode: $status
			);
		} catch (Throwable) {
			return new JSONResponse(
				data: ['message' => 'Unable to fulfil request'],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return new JSONResponse(
			data: [
				'status' => $entity->getStatus(),
				'fulfilled_at' => $entity->getFulfilledAt()?->format(\DateTime::ATOM),
			]
		);
	}//end fill()
}//end class
