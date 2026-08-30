<?php

/**
 * Keepiq Secret Version Controller
 *
 * Authenticated API controller for secret version history
 * (secret-version-history §6.1): metadata list, single-version blob read
 * (client-side decrypt), and restore. All methods #[NoAdminRequired];
 * per-object authorization in SecretVersionService bodies with no
 * existence oracle for inaccessible secrets.
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
use OCA\Keepiq\Service\SecretVersionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Authenticated API controller for version history.
 */
class SecretVersionController extends OCSController {
	/**
	 * Constructor for SecretVersionController.
	 *
	 * @param IRequest $request The request object
	 * @param SecretVersionService $versionService The version service
	 * @param IUserSession $userSession The user session
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private SecretVersionService $versionService,
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
	 * List a secret's versions (metadata only, newest first).
	 *
	 * @param string $secretId The secret UUID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/secret-version-history/spec.md#requirement-list-view-and-restore-versions
	 */
	#[NoAdminRequired]
	public function index(string $secretId): JSONResponse {
		$userId = $this->sessionUserId();
		if ($userId === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(
			data: array_map(
				static fn ($version) => $version->jsonSerialize(),
				$this->versionService->list(secretId: $secretId, userId: $userId)
			)
		);
	}//end index()

	/**
	 * One version WITH ciphertext blobs for client-side decrypt.
	 *
	 * @param string $id The version UUID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/secret-version-history/spec.md#requirement-list-view-and-restore-versions
	 */
	#[NoAdminRequired]
	public function show(string $id): JSONResponse {
		$userId = $this->sessionUserId();
		if ($userId === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$version = $this->versionService->getVersion(versionId: $id, userId: $userId);
		} catch (InvalidArgumentException $exception) {
			$status = Http::STATUS_NOT_FOUND;
			if (str_contains($exception->getMessage(), 'locked') === true) {
				$status = Http::STATUS_FORBIDDEN;
			}

			return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: $status);
		}

		return new JSONResponse(data: $version->jsonSerializeWithBlobs());
	}//end show()

	/**
	 * Restore a version onto the head. The client then drives the
	 * existing sync-on-update fan-out for shared recipients.
	 *
	 * @param string $id The version UUID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/secret-version-history/spec.md#requirement-list-view-and-restore-versions
	 */
	#[NoAdminRequired]
	public function restore(string $id): JSONResponse {
		$userId = $this->sessionUserId();
		if ($userId === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$secret = $this->versionService->restore(versionId: $id, userId: $userId);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(
				data: ['message' => $exception->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(data: $secret->jsonSerialize());
	}//end restore()
}//end class
