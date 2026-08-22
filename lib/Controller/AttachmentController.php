<?php

/**
 * Keepiq Attachment Controller
 *
 * Authenticated API controller for encrypted attachments
 * (encrypted-attachments §4.1): upload/list under a secret, grant-gated
 * blob download, owner-only delete, and the share-flow grant re-wrap.
 * All methods are #[NoAdminRequired]; per-object authorization happens
 * inside AttachmentService method bodies (hydra-gate-no-admin-idor).
 * Blobs travel base64-encoded in JSON — ciphertext both ways.
 *
 * @category Controller
 * @package  OCA\Keepiq\Controller
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

namespace OCA\Keepiq\Controller;

use InvalidArgumentException;
use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Service\AttachmentService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Authenticated API controller for the attachment lifecycle.
 */
class AttachmentController extends OCSController {
	/**
	 * Constructor for AttachmentController.
	 *
	 * @param IRequest $request The request object
	 * @param AttachmentService $attachmentService The attachment service
	 * @param IUserSession $userSession The user session
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private AttachmentService $attachmentService,
		private IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Resolve the session user ID or null when unauthenticated.
	 *
	 * @return string|null
	 */
	private function sessionUserId(): ?string {
		return $this->userSession->getUser()?->getUID();
	}//end sessionUserId()

	/**
	 * Upload a client-encrypted attachment against a secret.
	 *
	 * @param string $secretId The owning secret UUID
	 * @param string $blob The base64-encoded ciphertext bytes
	 * @param string $encryptedMetadata The AES-GCM metadata ciphertext
	 * @param string $wrappedFileKey The owner's RSA-wrapped file key
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-client-side-encrypted-attachment-upload
	 */
	#[NoAdminRequired]
	public function create(
		string $secretId,
		string $blob = '',
		string $encryptedMetadata = '',
		string $wrappedFileKey = '',
	): JSONResponse {
		$userId = $this->sessionUserId();
		if ($userId === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$raw = base64_decode($blob, true);
		if ($raw === false) {
			return new JSONResponse(
				data: ['message' => 'blob must be base64-encoded ciphertext'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$result = $this->attachmentService->upload(
				secretId: $secretId,
				userId: $userId,
				blob: $raw,
				encryptedMetadata: $encryptedMetadata,
				wrappedFileKey: $wrappedFileKey,
			);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(
				data: ['message' => $exception->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		$row = $result['attachment']->jsonSerialize();
		$row['wrappedFileKey'] = $result['grant']->getWrappedFileKey();

		return new JSONResponse(data: $row, statusCode: Http::STATUS_CREATED);
	}//end create()

	/**
	 * List a secret's attachments with the caller's own wrapped keys.
	 *
	 * @param string $secretId The secret UUID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-single-blob-envelope-with-per-recipient-key-wrapping
	 */
	#[NoAdminRequired]
	public function index(string $secretId): JSONResponse {
		$userId = $this->sessionUserId();
		if ($userId === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(
			data: $this->attachmentService->listForSecret(secretId: $secretId, userId: $userId)
		);
	}//end index()

	/**
	 * Download an attachment's ciphertext blob (base64-encoded).
	 *
	 * @param string $id The attachment UUID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-single-blob-envelope-with-per-recipient-key-wrapping
	 */
	#[NoAdminRequired]
	public function download(string $id): JSONResponse {
		$userId = $this->sessionUserId();
		if ($userId === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$bytes = $this->attachmentService->downloadBlob(attachmentId: $id, userId: $userId);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(
				data: ['message' => $exception->getMessage()],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		return new JSONResponse(data: ['blob' => base64_encode($bytes)]);
	}//end download()

	/**
	 * Add a recipient grant (share-flow file-key re-wrap).
	 *
	 * @param string $id The attachment UUID
	 * @param string $copySecretId The recipient's Secret copy UUID
	 * @param string $recipientId The recipient user/application ID
	 * @param string $wrappedFileKey The re-wrapped file key
	 * @param string $recipientType The recipient type
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-single-blob-envelope-with-per-recipient-key-wrapping
	 */
	#[NoAdminRequired]
	public function addGrant(
		string $id,
		string $copySecretId = '',
		string $recipientId = '',
		string $wrappedFileKey = '',
		string $recipientType = 'user',
	): JSONResponse {
		$userId = $this->sessionUserId();
		if ($userId === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$grant = $this->attachmentService->addGrant(
				attachmentId: $id,
				userId: $userId,
				copySecretId: $copySecretId,
				recipientId: $recipientId,
				wrappedFileKey: $wrappedFileKey,
				recipientType: $recipientType,
			);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(
				data: ['message' => $exception->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(data: $grant->jsonSerialize(), statusCode: Http::STATUS_CREATED);
	}//end addGrant()

	/**
	 * Delete an attachment (owner-only; removes grants + blob).
	 *
	 * @param string $id The attachment UUID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-attachment-deletion-cascade
	 */
	#[NoAdminRequired]
	public function destroy(string $id): JSONResponse {
		$userId = $this->sessionUserId();
		if ($userId === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->attachmentService->delete(attachmentId: $id, userId: $userId);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(
				data: ['message' => $exception->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(data: ['deleted' => true]);
	}//end destroy()
}//end class
