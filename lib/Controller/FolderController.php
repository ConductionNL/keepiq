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

use Exception;
use InvalidArgumentException;
use OCA\Doriath\AppInfo\Application;
use OCA\Doriath\Db\FolderMapper;
use OCA\Doriath\Service\FolderService;
use OCP\AppFramework\Http;
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
     * @param FolderMapper  $folderMapper  The folder mapper
     * @param IUserSession  $userSession   The user session
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private FolderService $folderService,
        private FolderMapper $folderMapper,
        private IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List all folders for the current user.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    public function index(): JSONResponse
    {
        $userId  = $this->userSession->getUser()->getUID();
        $folders = $this->folderMapper->findByOwner(ownerType: 'user', ownerId: $userId);

        return new JSONResponse(
            data: array_map(
                static fn ($folder) => $folder->jsonSerialize(),
                $folders
            )
        );
    }//end index()

    /**
     * Create a new folder for the current user.
     *
     * @param string      $name     The folder name
     * @param string|null $parentId The parent folder ID, or null for a root folder
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    public function create(string $name, ?string $parentId=null): JSONResponse
    {
        $userId = $this->userSession->getUser()->getUID();

        try {
            $folder = $this->folderService->create(
                name: $name,
                parentId: $parentId,
                ownerType: 'user',
                ownerId: $userId
            );
            return new JSONResponse(data: $folder->jsonSerialize(), statusCode: Http::STATUS_CREATED);
        } catch (Exception $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }
    }//end create()

    /**
     * Update a folder (rename and/or move).
     *
     * If $name is provided, renames the folder.
     * If $parentId is provided (including explicit null to move to root), moves the folder.
     *
     * @param string      $id       The folder ID
     * @param string|null $name     The new folder name
     * @param string|null $parentId The new parent folder ID, or null to move to root
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    public function update(string $id, ?string $name=null, ?string $parentId=null): JSONResponse
    {
        $userId = $this->userSession->getUser()->getUID();

        try {
            $folder = null;

            if ($name !== null) {
                $folder = $this->folderService->rename(id: $id, name: $name, userId: $userId);
            }

            $requestParams = $this->request->getParams();
            if (array_key_exists(key: 'parentId', array: $requestParams) === true) {
                $folder = $this->folderService->move(id: $id, newParentId: $parentId, userId: $userId);
            }

            if ($folder === null) {
                $folder = $this->folderService->validateOwnership(id: $id, userId: $userId);
            }

            return new JSONResponse(data: $folder->jsonSerialize());
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_FORBIDDEN
            );
        } catch (Exception $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }//end try
    }//end update()

    /**
     * Delete a folder.
     *
     * Reads an optional resolution JSON from the request body for non-empty folders.
     * Pass cascade='delete' or cascade='move' for folders containing secrets only.
     * Pass a resolution map for folders containing subfolders.
     *
     * @param string      $id      The folder ID
     * @param string|null $cascade How to handle direct secrets ('delete' or 'move')
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    public function destroy(string $id, ?string $cascade=null): JSONResponse
    {
        $userId = $this->userSession->getUser()->getUID();

        $body = $this->request->getParams();
        if (isset($body['resolution']) === true) {
            $resolution = (array) $body['resolution'];
        } else {
            $resolution = null;
        }

        try {
            $this->folderService->delete(
                id: $id,
                cascade: $cascade,
                resolution: $resolution,
                userId: $userId
            );
            return new JSONResponse(data: ['message' => 'Folder deleted successfully']);
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_CONFLICT
            );
        } catch (Exception $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }
    }//end destroy()

    /**
     * Get the direct children summary for a folder.
     *
     * Returns the direct secret count and an array of subfolder summaries.
     *
     * @param string $id The folder ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    public function children(string $id): JSONResponse
    {
        $userId = $this->userSession->getUser()->getUID();

        try {
            $result = $this->folderService->getChildren(id: $id, userId: $userId);
            return new JSONResponse(data: $result);
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_FORBIDDEN
            );
        } catch (Exception $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }
    }//end children()
}//end class
