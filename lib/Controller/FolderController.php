<?php

/**
 * Doriath Folder Controller
 *
 * Authenticated API controller for Folder CRUD: listing, creating, renaming
 * and moving (update), the children endpoint used by the resolution dialog,
 * and the three-mode deletion protocol.
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
use OCA\Doriath\Exception\NotFoundException;
use OCA\Doriath\Service\FolderService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Authenticated API controller for Folder CRUD.
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
     * Resolve the current user's UID or return null.
     *
     * @return string|null
     */
    private function uid(): ?string
    {
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
    public function index(): JSONResponse
    {
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
     * @param string      $name     The folder name (no slashes)
     * @param string|null $parentId The parent folder ID (null = root)
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-secrets/tasks.md#task-4.3
     */
    #[NoAdminRequired]
    public function create(string $name, ?string $parentId=null): JSONResponse
    {
        $userId = $this->uid();
        if ($userId === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        try {
            $folder = $this->folderService->create($name, $parentId, $userId);
        } catch (ForbiddenException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
        }

        return new JSONResponse(data: $folder->jsonSerialize(), statusCode: Http::STATUS_CREATED);
    }//end create()

    /**
     * Update a folder — rename and/or move.
     *
     * @param string      $id       The folder ID
     * @param string|null $name     The new name (null = no rename)
     * @param string|null $parentId The new parent (when moving)
     * @param bool        $move     Whether parentId is a move instruction
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
    public function update(string $id, ?string $name=null, ?string $parentId=null, bool $move=false): JSONResponse
    {
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

            if ($folder === null) {
                $folder = $this->folderService->getOwned($id, $userId);
            }
        } catch (NotFoundException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_NOT_FOUND);
        } catch (ForbiddenException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
        }

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
    public function children(string $id): JSONResponse
    {
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
     * @param string      $id      The folder ID
     * @param string|null $cascade The shorthand cascade mode (delete/move)
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-secrets/tasks.md#task-4.3
     */
    #[NoAdminRequired]
    public function destroy(string $id, ?string $cascade=null): JSONResponse
    {
        $userId = $this->uid();
        if ($userId === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        // The resolution plan, when present, arrives in the JSON body.
        $resolution = null;
        $subfolders = $this->request->getParam('subfolders');
        if ($subfolders !== null) {
            $resolution = [
                'subfolders'    => $subfolders,
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
