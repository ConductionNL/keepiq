<?php

/**
 * Doriath Secret Type Controller
 *
 * Authenticated API controller for SecretType CRUD: listing available
 * types, creating user/global custom types, relabelling, and deleting with
 * fallback-to-login reassignment.
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
use OCA\Doriath\Exception\ConflictException;
use OCA\Doriath\Exception\ForbiddenException;
use OCA\Doriath\Service\SecretTypeService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Authenticated API controller for SecretType CRUD.
 */
class SecretTypeController extends OCSController {
	/**
	 * Constructor for SecretTypeController.
	 *
	 * @param IRequest $request The request object
	 * @param SecretTypeService $typeService The secret type service
	 * @param IUserSession $userSession The user session
	 * @param IGroupManager $groupManager The group manager (admin check)
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private SecretTypeService $typeService,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * List the secret types available to the current user.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-secrets/tasks.md#task-4.2
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$types = $this->typeService->getAvailableTypes($user->getUID());

		return new JSONResponse(
			data: array_map(static fn ($type) => $type->jsonSerialize(), $types)
		);
	}//end index()

	/**
	 * Create a custom secret type (user scope by default; global for admins).
	 *
	 * @param string $name The unique type name
	 * @param string $label The human-readable label
	 * @param string $scope The scope (user or global)
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-secrets/tasks.md#task-4.2
	 */
	#[NoAdminRequired]
	public function create(string $name, string $label, string $scope = 'user'): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$userId = $user->getUID();
		$isAdmin = $this->groupManager->isAdmin($userId);

		try {
			$type = $this->typeService->createType($name, $label, $scope, $userId, $isAdmin);
		} catch (ForbiddenException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		} catch (ConflictException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_CONFLICT);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: $type->jsonSerialize(), statusCode: Http::STATUS_CREATED);
	}//end create()

	/**
	 * Relabel a custom secret type the requester may manage.
	 *
	 * @param string $id The type ID
	 * @param string $label The new label
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-secrets/tasks.md#task-4.2
	 */
	#[NoAdminRequired]
	public function update(string $id, string $label): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$userId = $user->getUID();
		$isAdmin = $this->groupManager->isAdmin($userId);

		try {
			$type = $this->typeService->updateType($id, $label, $userId, $isAdmin);
		} catch (ForbiddenException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: $type->jsonSerialize());
	}//end update()

	/**
	 * Delete a custom secret type, reassigning its secrets to login.
	 *
	 * @param string $id The type ID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-secrets/tasks.md#task-4.2
	 */
	#[NoAdminRequired]
	public function destroy(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$userId = $user->getUID();
		$isAdmin = $this->groupManager->isAdmin($userId);

		try {
			$this->typeService->deleteType($id, $userId, $isAdmin);
		} catch (ForbiddenException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(data: ['status' => 'deleted']);
	}//end destroy()
}//end class
