<?php

/**
 * Doriath Folder Controller
 *
 * API controller for Folder CRUD operations.
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
use OCA\Doriath\Service\FolderService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * API controller for Folder CRUD operations.
 */
class FolderController extends OCSController
{
    /**
     * Constructor for FolderController.
     *
     * @param IRequest      $request       The request object
     * @param FolderService $folderService The folder service
     * @param IUserSession  $userSession   The user session
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
     * List the current user's folders.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-secrets/specs/folders/spec.md#requirement-folder-ownership-isolation
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        $userId = $this->requireUserId();
        if ($userId === null) {
            return $this->unauthorized();
        }

        $folders = $this->folderService->list($userId);
        return new JSONResponse(
            data: array_map(static fn ($f) => $f->jsonSerialize(), $folders)
        );
    }//end index()

    /**
     * Create a folder.
     *
     * @param string      $name     The folder name
     * @param string|null $parentId The parent folder ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-secrets/specs/folders/spec.md#requirement-create-folder
     */
    #[NoAdminRequired]
    public function create(string $name, ?string $parentId=null): JSONResponse
    {
        $userId = $this->requireUserId();
        if ($userId === null) {
            return $this->unauthorized();
        }

        try {
            $folder = $this->folderService->create($name, $parentId, $userId);
            return new JSONResponse(data: $folder->jsonSerialize(), statusCode: Http::STATUS_CREATED);
        } catch (ForbiddenException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
        } catch (DoesNotExistException) {
            return new JSONResponse(data: ['message' => 'Parent folder not found'], statusCode: Http::STATUS_NOT_FOUND);
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
        }
    }//end create()

    /**
     * Rename or move a folder.
     *
     * @param string      $id       The folder ID
     * @param string|null $name     The new name (rename)
     * @param string|null $parentId The new parent (move; use empty string for root)
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-secrets/specs/folders/spec.md#requirement-rename-folder
     */
    #[NoAdminRequired]
    public function update(string $id, ?string $name=null, ?string $parentId=null): JSONResponse
    {
        $userId = $this->requireUserId();
        if ($userId === null) {
            return $this->unauthorized();
        }

        $params = $this->request->getParams();

        try {
            if ($name !== null) {
                $this->folderService->rename($id, $name, $userId);
            }

            if (array_key_exists('parentId', $params) === true) {
                $destination = $parentId;
                if ($destination === '') {
                    $destination = null;
                }

                $this->folderService->move($id, $destination, $userId);
            }

            $folder = $this->folderService->getOwned($id, $userId);
            return new JSONResponse(data: $folder->jsonSerialize());
        } catch (ForbiddenException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
        } catch (DoesNotExistException) {
            return new JSONResponse(data: ['message' => 'Folder not found'], statusCode: Http::STATUS_NOT_FOUND);
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
        }//end try
    }//end update()

    /**
     * Delete a folder with cascade/resolution handling.
     *
     * @param string      $id      The folder ID
     * @param string|null $cascade The cascade mode (delete|move)
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-secrets/specs/folders/spec.md#requirement-delete-folder-with-subfolders----resolution-required
     */
    #[NoAdminRequired]
    public function destroy(string $id, ?string $cascade=null): JSONResponse
    {
        $userId = $this->requireUserId();
        if ($userId === null) {
            return $this->unauthorized();
        }

        $params     = $this->request->getParams();
        $resolution = null;
        if (isset($params['subfolders']) === true || isset($params['directSecrets']) === true) {
            $resolution = ($params['subfolders'] ?? []);
            if (isset($params['directSecrets']) === true) {
                $resolution['directSecrets'] = $params['directSecrets'];
            }
        }

        try {
            $this->folderService->delete($id, $cascade, $resolution, $userId);
            return new JSONResponse(data: ['status' => 'deleted']);
        } catch (ForbiddenException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
        } catch (ConflictException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_CONFLICT);
        } catch (DoesNotExistException) {
            return new JSONResponse(data: ['message' => 'Folder not found'], statusCode: Http::STATUS_NOT_FOUND);
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
        }//end try
    }//end destroy()

    /**
     * Get a folder's children summary for the resolution dialog.
     *
     * @param string $id The folder ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-secrets/specs/folders/spec.md#requirement-list-folder-children
     */
    #[NoAdminRequired]
    public function children(string $id): JSONResponse
    {
        $userId = $this->requireUserId();
        if ($userId === null) {
            return $this->unauthorized();
        }

        try {
            return new JSONResponse(data: $this->folderService->getChildren($id, $userId));
        } catch (ForbiddenException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
        } catch (DoesNotExistException) {
            return new JSONResponse(data: ['message' => 'Folder not found'], statusCode: Http::STATUS_NOT_FOUND);
        }
    }//end children()

    /**
     * Resolve the current user's UID or null when unauthenticated.
     *
     * @return string|null
     */
    private function requireUserId(): ?string
    {
        $user = $this->userSession->getUser();
        return $user?->getUID();
    }//end requireUserId()

    /**
     * Build a 401 response.
     *
     * @return JSONResponse
     */
    private function unauthorized(): JSONResponse
    {
        return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
    }//end unauthorized()
}//end class
