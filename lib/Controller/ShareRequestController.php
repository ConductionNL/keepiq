<?php

/**
 * Doriath Share Request Controller
 *
 * Authenticated API controller for the share-request lifecycle:
 *  - create   submit a request to the owner
 *  - approve  owner approves (returns the parameters for the share flow)
 *  - deny     owner denies (notifies the requester)
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
use OCA\Doriath\Service\ShareRequestService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Authenticated API controller for share requests.
 */
class ShareRequestController extends OCSController {
	/**
	 * Constructor for ShareRequestController.
	 *
	 * @param IRequest $request The request object
	 * @param ShareRequestService $shareRequestService The share-request service
	 * @param IUserSession $userSession The user session
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private ShareRequestService $shareRequestService,
		private IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Submit a share request.
	 *
	 * @param string $sourceSecretId The source secret ID
	 * @param string $targetUserId The user the request is for
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#9.3
	 */
	#[NoAdminRequired]
	public function create(string $sourceSecretId, string $targetUserId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->shareRequestService->submitShareRequest(
				sourceSecretId: $sourceSecretId,
				targetUserId: $targetUserId,
				requesterId: $user->getUID()
			);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(
				data: ['message' => $exception->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(data: ['status' => 'submitted'], statusCode: Http::STATUS_CREATED);
	}//end create()

	/**
	 * Approve a share request — returns the parameters the browser uses
	 * to fan back into ShareController::create.
	 *
	 * @param string $sourceSecretId The source secret ID (from notification)
	 * @param string $requesterId The requester's UID
	 * @param string $targetUserId The target user's UID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#9.3
	 */
	#[NoAdminRequired]
	public function approve(
		string $sourceSecretId,
		string $requesterId,
		string $targetUserId,
	): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$params = $this->shareRequestService->approveShareRequest(
				params: [
					'sourceSecretId' => $sourceSecretId,
					'requesterId' => $requesterId,
					'targetUserId' => $targetUserId,
				],
				ownerId: $user->getUID()
			);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(
				data: ['message' => $exception->getMessage()],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		return new JSONResponse(data: $params);
	}//end approve()

	/**
	 * Deny a share request — fires a result notification at the requester.
	 *
	 * @param string $sourceSecretId The source secret ID (from notification)
	 * @param string $requesterId The requester's UID
	 * @param string $targetUserId The target user's UID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#9.3
	 */
	#[NoAdminRequired]
	public function deny(
		string $sourceSecretId,
		string $requesterId,
		string $targetUserId,
	): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->shareRequestService->denyShareRequest(
				params: [
					'sourceSecretId' => $sourceSecretId,
					'requesterId' => $requesterId,
					'targetUserId' => $targetUserId,
				],
				ownerId: $user->getUID()
			);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(
				data: ['message' => $exception->getMessage()],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		return new JSONResponse(data: ['status' => 'denied']);
	}//end deny()
}//end class
