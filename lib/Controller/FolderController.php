<?php

/**
 * Keepiq Folder Controller
 *
 * Authenticated API controller for Folder CRUD: listing, creating, renaming
 * and moving (update), the children endpoint used by the resolution dialog,
 * and the three-mode deletion protocol.
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
use OCA\Keepiq\Exception\ConflictException;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Service\FolderService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Authenticated API controller for Folder CRUD.
 */
class FolderController extends OCSController {
	/**
	 * Constructor for FolderController.
	 *
	 * @param IRequest $request The request object
	 * @param FolderService $folderService The folder service
	 * @param IUserSession $userSession The user session
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private FolderService $folderService,
		private IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Resolve the current user's UID or return null.
	 *
	 * @return string|null
	 */
	private function uid(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		return $user->getUID();
	}//end uid()

	/**
	 * List the folders owned by the current user.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-secrets/tasks.md#task-4.3
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$userId = $this->uid();
		if ($userId === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$folders = $this->folderService->listForUser($userId);

		return new JSONResponse(
			data: array_map(static fn ($folder) => $folder->jsonSerialize(), $folders)
		);
	}//end index()

	/**
	 * Create a folder.
	 *
	 * @param string $name The folder name (no slashes)
	 * @param string|null $parentId The parent folder ID (null = root)
	 * @param string|null $customIcon Optional custom icon key (restyle Stage 9)
	 * @param string|null $customColor Optional custom color key (restyle Stage 9)
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-secrets/tasks.md#task-4.3
	 */
	#[NoAdminRequired]
	public function create(
		string $name,
		?string $parentId = null,
		?string $customIcon = null,
		?string $customColor = null,
	): JSONResponse {
		$userId = $this->uid();
		if ($userId === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$folder = $this->folderService->create($name, $parentId, $userId, $customIcon, $customColor);
		} catch (ForbiddenException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		} catch (ConflictException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_CONFLICT);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: $folder->jsonSerialize(), statusCode: Http::STATUS_CREATED);
	}//end create()

	/**
	 * Update a folder — rename, move, and/or update presentation attributes.
	 *
	 * customIcon/customColor apply only when their KEY is present in the
	 * request body (an explicit null CLEARS the value), so a rename/move
	 * call that does not mention them can never accidentally wipe a
	 * customization (restyle Stage 9).
	 *
	 * @param string $id The folder ID
	 * @param string|null $name The new name (null = no rename)
	 * @param string|null $parentId The new parent (when moving)
	 * @param bool $move Whether parentId is a move instruction
	 * @param string|null $customIcon The new custom icon key (null clears; only applied when the key is present)
	 * @param string|null $customColor The new custom color key (null clears; only applied when the key is present)
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-secrets/tasks.md#task-4.3
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The move flag distinguishes a
	 *   re-parent-to-root (parentId null) request from a plain rename where
	 *   parentId is simply absent.
	 */
	#[NoAdminRequired]
	public function update(
		string $id,
		?string $name = null,
		?string $parentId = null,
		bool $move = false,
		?string $customIcon = null,
		?string $customColor = null,
	): JSONResponse {
		$userId = $this->uid();
		if ($userId === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$folder = null;
			if ($name !== null) {
				$folder = $this->folderService->rename($id, $name, $userId);
			}

			if ($move === true) {
				$folder = $this->folderService->move($id, $parentId, $userId);
			}

			// Key-present-with-null CLEARS, absent key stays untouched — the
			// distinction only the raw request params can make (the method
			// arguments read null for both).
			$requestParams = $this->request->getParams();
			$attributeChanges = [];
			if (array_key_exists('customIcon', $requestParams) === true) {
				$attributeChanges['customIcon'] = $customIcon;
			}

			if (array_key_exists('customColor', $requestParams) === true) {
				$attributeChanges['customColor'] = $customColor;
			}

			if ($attributeChanges !== []) {
				$folder = $this->folderService->updateAttributes($id, $attributeChanges, $userId);
			}

			if ($folder === null) {
				$folder = $this->folderService->getOwned($id, $userId);
			}
		} catch (NotFoundException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		} catch (ConflictException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_CONFLICT);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}//end try

		return new JSONResponse(data: $folder->jsonSerialize());
	}//end update()

	/**
	 * List a folder's children for the resolution dialog.
	 *
	 * @param string $id The folder ID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-secrets/tasks.md#task-4.3
	 */
	#[NoAdminRequired]
	public function children(string $id): JSONResponse {
		$userId = $this->uid();
		if ($userId === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$children = $this->folderService->getChildren($id, $userId);
		} catch (NotFoundException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(data: $children);
	}//end children()

	/**
	 * Delete a folder with the appropriate cascade/resolution protocol.
	 *
	 * @param string $id The folder ID
	 * @param string|null $cascade The shorthand cascade mode (delete/move)
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-secrets/tasks.md#task-4.3
	 */
	#[NoAdminRequired]
	public function destroy(string $id, ?string $cascade = null): JSONResponse {
		$userId = $this->uid();
		if ($userId === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		// The resolution plan, when present, arrives in the JSON body.
		$resolution = null;
		$subfolders = $this->request->getParam('subfolders');
		if ($subfolders !== null) {
			$resolution = [
				'subfolders' => $subfolders,
				'directSecrets' => $this->request->getParam('directSecrets', 'move'),
			];
		}

		try {
			$this->folderService->delete($id, $cascade, $resolution, $userId);
		} catch (NotFoundException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		} catch (ConflictException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_CONFLICT);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: ['status' => 'deleted']);
	}//end destroy()
}//end class
