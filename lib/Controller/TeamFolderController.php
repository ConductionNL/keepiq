<?php

/**
 * Keepiq Team Folder Controller
 *
 * Authenticated API controller for team folder sharing
 * (team-folder-sharing §4.1): list and share/unshare a folder, run the
 * fan-out reconciliation, register browser-encrypted fan-out shares, and run
 * the admin offboarding action. All methods are #[NoAdminRequired];
 * per-object owner/admin authorization happens inside TeamFolderService
 * method bodies (hydra-gate-no-admin-idor).
 *
 * Membership endpoints (list/add/remove members, approve a group join, set a
 * member's grade) live in TeamFolderMemberController — see the rationale in
 * that class's docblock.
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
use OCA\Keepiq\Service\TeamFolderService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Authenticated API controller for the TeamFolder lifecycle.
 */
class TeamFolderController extends OCSController {
	/**
	 * Constructor for TeamFolderController.
	 *
	 * @param IRequest $request The request object
	 * @param TeamFolderService $teamFolderService The team-folder service
	 * @param IUserSession $userSession The user session
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private TeamFolderService $teamFolderService,
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
	 * List team folders: owned (with members) and shared-to-me.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#4.1
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$userId = $this->sessionUserId();
		if ($userId === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(data: $this->teamFolderService->listForUser(userId: $userId));
	}//end index()

	/**
	 * Share an owned folder — creates the TeamFolder.
	 *
	 * @param string $folderId The Folder UUID to share
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#4.1
	 */
	#[NoAdminRequired]
	public function create(string $folderId): JSONResponse {
		$userId = $this->sessionUserId();
		if ($userId === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$teamFolder = $this->teamFolderService->shareFolder(folderId: $folderId, userId: $userId);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(
				data: ['message' => $exception->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(data: $teamFolder->jsonSerialize(), statusCode: Http::STATUS_CREATED);
	}//end create()

	/**
	 * Unshare a folder — cascade-revokes all derived shares; the folder
	 * itself remains as a private folder.
	 *
	 * @param string $id The TeamFolder UUID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#4.1
	 */
	#[NoAdminRequired]
	public function destroy(string $id): JSONResponse {
		$userId = $this->sessionUserId();
		if ($userId === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$revoked = $this->teamFolderService->unshareFolder(teamFolderId: $id, userId: $userId);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(
				data: ['message' => $exception->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(data: ['revoked' => $revoked]);
	}//end destroy()

	/**
	 * Reconciliation: expected fan-out state + missing (secret ×
	 * recipient) pairs for the browser to encrypt (self-healing after a
	 * partial fan-out).
	 *
	 * @param string $id The TeamFolder UUID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.4
	 */
	#[NoAdminRequired]
	public function reconcile(string $id): JSONResponse {
		$userId = $this->sessionUserId();
		if ($userId === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$state = $this->teamFolderService->reconcile(teamFolderId: $id, userId: $userId);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(
				data: ['message' => $exception->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(data: $state);
	}//end reconcile()

	/**
	 * Register a chunk of browser-encrypted fan-out shares (idempotent
	 * upsert — retried chunks never double-share).
	 *
	 * @param string $id The TeamFolder UUID
	 * @param array<int,array{sourceSecretId:string,targetUserId:string,recipientSecretId:string}> $shares The fan-out rows
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.4
	 */
	#[NoAdminRequired]
	public function registerShares(string $id, array $shares): JSONResponse {
		$userId = $this->sessionUserId();
		if ($userId === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$result = $this->teamFolderService->registerFanOutShares(
				teamFolderId: $id,
				shares: $shares,
				userId: $userId
			);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(
				data: ['message' => $exception->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(data: $result, statusCode: Http::STATUS_CREATED);
	}//end registerShares()

	/**
	 * Admin offboarding: revoke a leaver's team-derived access and
	 * transfer their owned team secrets to a successor. Authorization
	 * (instance admin or vault_admin) is asserted in the service body.
	 *
	 * @param string $leavingUserId The user being offboarded
	 * @param string $successorUserId The successor user
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.5
	 */
	#[NoAdminRequired]
	public function offboard(string $leavingUserId, string $successorUserId): JSONResponse {
		$userId = $this->sessionUserId();
		if ($userId === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$summary = $this->teamFolderService->offboard(
				leavingUserId: $leavingUserId,
				successorUserId: $successorUserId,
				adminId: $userId
			);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(
				data: ['message' => $exception->getMessage()],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		return new JSONResponse(data: $summary);
	}//end offboard()
}//end class
