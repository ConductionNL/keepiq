<?php

/**
 * Keepiq Share Controller
 *
 * Authenticated API controller for user-to-user secret-share CRUD
 * (scaffold). The full sharing flow (group expansion, delegation,
 * sync-on-update) is deferred to the implement-user-sharing build cycle.
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
use OCA\Keepiq\Service\ShareService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Authenticated API controller for ShareTarget CRUD.
 */
class ShareController extends OCSController {
	/**
	 * Constructor for ShareController.
	 *
	 * @param IRequest $request The request object
	 * @param ShareService $shareService The share service
	 * @param IUserSession $userSession The user session
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private ShareService $shareService,
		private IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * List share targets for a source secret.
	 *
	 * @param string $secretId The source secret ID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#task-9.1
	 */
	#[NoAdminRequired]
	public function index(string $secretId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$shares = $this->shareService->listSharesForSecret(
			sourceSecretId: $secretId,
			userId: $user->getUID()
		);

		return new JSONResponse(
			data: array_map(
				static fn ($share) => $share->jsonSerialize(),
				$shares
			)
		);
	}//end index()

	/**
	 * Create a share target.
	 *
	 * The browser performs the recipient-side RSA encryption and persists
	 * the recipient's Secret copy through the SecretController; this
	 * endpoint records the share-target row that links the two.
	 *
	 * @param string $secretId The source secret ID
	 * @param string $targetUserId The recipient Nextcloud user ID
	 * @param string $recipientSecretId The recipient's encrypted Secret copy ID
	 * @param string|null $groupShareId Optional group-share linkage
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#task-9.1
	 */
	#[NoAdminRequired]
	public function create(
		string $secretId,
		string $targetUserId,
		string $recipientSecretId,
		?string $groupShareId = null,
	): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$share = $this->shareService->createShare(
				sourceSecretId: $secretId,
				targetUserId: $targetUserId,
				recipientSecretId: $recipientSecretId,
				groupShareId: $groupShareId,
				userId: $user->getUID()
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(
				data: ['message' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(data: $share->jsonSerialize(), statusCode: Http::STATUS_CREATED);
	}//end create()

	/**
	 * Revoke a share target.
	 *
	 * @param string $id The share-target row ID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#task-9.1
	 */
	#[NoAdminRequired]
	public function destroy(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->shareService->revokeShare(shareId: $id, userId: $user->getUID());
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(
				data: ['message' => $e->getMessage()],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		return new JSONResponse(data: ['status' => 'deleted']);
	}//end destroy()

	/**
	 * Create a batch of share targets — the group-share expansion path.
	 *
	 * @param string $secretId The source secret ID
	 * @param array $shares The per-recipient batch (each {targetUserId, recipientSecretId})
	 * @param string $groupShareId The GroupShare ID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#9.1
	 */
	#[NoAdminRequired]
	public function createBatch(
		string $secretId,
		array $shares,
		string $groupShareId,
	): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$created = $this->shareService->createBatchShares(
				sourceSecretId: $secretId,
				shares: $shares,
				groupShareId: $groupShareId,
				userId: $user->getUID()
			);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(
				data: ['message' => $exception->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(
			data: array_map(static fn ($row) => $row->jsonSerialize(), $created),
			statusCode: Http::STATUS_CREATED
		);
	}//end createBatch()

	/**
	 * Push an updated encrypted blob to every recipient.
	 *
	 * @param string $secretId The source secret ID
	 * @param array $updates The per-recipient blobs
	 * @param string $expectedUpdatedAt The owner-side expected ISO timestamp
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#9.1
	 */
	#[NoAdminRequired]
	public function sync(
		string $secretId,
		array $updates,
		string $expectedUpdatedAt = '',
	): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$written = $this->shareService->syncUpdate(
				secretId: $secretId,
				updates: $updates,
				expectedUpdatedAt: $expectedUpdatedAt,
				userId: $user->getUID()
			);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(
				data: ['message' => $exception->getMessage()],
				statusCode: Http::STATUS_CONFLICT
			);
		}

		return new JSONResponse(data: ['updated' => $written]);
	}//end sync()

	/**
	 * Register a batch of DIRECT user shares from client-encrypted blobs
	 * (bulk-actions §6.1/§7.1). Idempotent per (secret × recipient); the
	 * per-item report never aborts on a skipped row.
	 *
	 * @param array $shares Rows {sourceSecretId, targetUserId, encryptedKey, encryptedLogin?, encryptedAdditionalFields?}
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/bulk-actions/specs/bulk-actions/spec.md#requirement-bulk-share
	 */
	#[NoAdminRequired]
	public function registerBatch(array $shares = []): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$report = $this->shareService->registerDirectShares(userId: $user->getUID(), shares: $shares);

		return new JSONResponse(data: ['items' => $report]);
	}//end registerBatch()

	/**
	 * The active-suite certificate of a prospective recipient — public
	 * key material only, needed client-side to encrypt the copy.
	 *
	 * @param string $userId The prospective recipient
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @no-admin-idor-exempt public-key distribution. The only thing returned is
	 * EncryptionSuite::getCertificate() — the PUBLIC half of the recipient's
	 * suite, and never any private material. Any authenticated user must be
	 * able to fetch any recipient's certificate, because that certificate is
	 * precisely what the browser needs in order to encrypt a secret TO them;
	 * withholding it would not protect anything and would break sharing.
	 */
	#[NoAdminRequired]
	public function recipientCertificate(string $userId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$certificate = $this->shareService->recipientCertificate(targetUserId: $userId);
		if ($certificate === null) {
			return new JSONResponse(
				data: ['message' => 'Recipient has no active encryption suite'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		return new JSONResponse(data: ['userId' => $userId, 'certificate' => $certificate]);
	}//end recipientCertificate()

	/**
	 * The write context of a secret for the current user
	 * (folder-permission-grades §4): source resolution + effective grade
	 * + the owner-row material a write-grade fan-out needs.
	 *
	 * @param string $id The secret (source or recipient copy) UUID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-a-write-grade-member-may-update-a-folder-secret-for-all-recipients
	 */
	#[NoAdminRequired]
	public function writeContext(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse(
				data: $this->shareService->writeContext(secretId: $id, userId: $user->getUID())
			);
		} catch (InvalidArgumentException) {
			return new JSONResponse(data: ['message' => 'Not found'], statusCode: Http::STATUS_NOT_FOUND);
		}
	}//end writeContext()
}//end class
