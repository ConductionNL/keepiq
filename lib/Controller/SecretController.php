<?php

/**
 * Doriath Secret Controller
 *
 * API controller for Secret CRUD operations.
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
use OCA\Doriath\Service\SecretQueryService;
use OCA\Doriath\Service\SecretService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;

/**
 * API controller for Secret CRUD operations.
 */
class SecretController extends OCSController
{
    /**
     * Constructor for SecretController.
     *
     * @param IRequest           $request       The request object
     * @param SecretService      $secretService The secret write service
     * @param SecretQueryService $queryService  The secret read/query service
     * @param IUserSession       $userSession   The user session
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private SecretService $secretService,
        private SecretQueryService $queryService,
        private IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List or search the current user's secrets.
     *
     * @param string|null $folderId  Optional folder filter
     * @param string|null $search    Optional fuzzy search term
     * @param string|null $sort      Sort field
     * @param string|null $direction Sort direction
     * @param int         $page      1-based page
     * @param int         $limit     Page size
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-secrets/specs/secrets/spec.md#requirement-list-and-pagination
     */
    #[NoAdminRequired]
    public function index(
        ?string $folderId=null,
        ?string $search=null,
        ?string $sort=null,
        ?string $direction=null,
        int $page=1,
        int $limit=50,
    ): JSONResponse {
        $userId = $this->requireUserId();
        if ($userId === null) {
            return $this->unauthorized();
        }

        // The unified-search query parameter is snake_case (folder_id); accept
        // either spelling from the request params for API ergonomics.
        $folderFilter = $folderId;
        $snakeFolder  = $this->request->getParam('folder_id');
        if ($snakeFolder !== null) {
            $folderFilter = $snakeFolder;
        }

        if ($search !== null && trim($search) !== '') {
            return new JSONResponse(data: $this->queryService->search($userId, $search, $page, $limit));
        }

        $result = $this->queryService->list(
            userId: $userId,
            filters: ['folder_id' => $folderFilter],
            sort: $sort,
            dir: $direction,
            page: $page,
            limit: $limit
        );

        return new JSONResponse(data: $result);
    }//end index()

    /**
     * Get a single secret.
     *
     * @param string $id The secret ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-secrets/specs/secrets/spec.md#requirement-read-secret
     */
    #[NoAdminRequired]
    public function show(string $id): JSONResponse
    {
        $userId = $this->requireUserId();
        if ($userId === null) {
            return $this->unauthorized();
        }

        try {
            $secret = $this->queryService->get($id, $userId);
            return new JSONResponse(data: $secret->jsonSerialize());
        } catch (ForbiddenException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
        } catch (DoesNotExistException) {
            return new JSONResponse(data: ['message' => 'Secret not found'], statusCode: Http::STATUS_NOT_FOUND);
        }
    }//end show()

    /**
     * Create a secret.
     *
     * @param string      $name             The secret name
     * @param string      $key              The encrypted key blob
     * @param string|null $url              The URL
     * @param string|null $login            The encrypted login blob
     * @param string|null $additionalFields The encrypted additional fields blob
     * @param string|null $typeId           The type ID
     * @param string|null $folderId         The folder ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-secrets/specs/secrets/spec.md#requirement-create-secret
     */
    #[NoAdminRequired]
    public function create(
        string $name,
        string $key,
        ?string $url=null,
        ?string $login=null,
        ?string $additionalFields=null,
        ?string $typeId=null,
        ?string $folderId=null,
    ): JSONResponse {
        $userId = $this->requireUserId();
        if ($userId === null) {
            return $this->unauthorized();
        }

        try {
            $secret = $this->secretService->create(
                data: [
                    'name'             => $name,
                    'key'              => $key,
                    'url'              => $url,
                    'login'            => $login,
                    'additionalFields' => $additionalFields,
                    'typeId'           => $typeId,
                    'folderId'         => $folderId,
                ],
                userId: $userId
            );

            return new JSONResponse(data: $secret->jsonSerialize(), statusCode: Http::STATUS_CREATED);
        } catch (ForbiddenException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
        } catch (ConflictException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_LOCKED);
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
        } catch (RuntimeException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
        }//end try
    }//end create()

    /**
     * Update a secret.
     *
     * @param string      $id               The secret ID
     * @param string|null $name             The secret name
     * @param string|null $url              The URL
     * @param string|null $key              The encrypted key blob
     * @param string|null $login            The encrypted login blob
     * @param string|null $additionalFields The encrypted additional fields blob
     * @param string|null $typeId           The type ID
     * @param string|null $folderId         The folder ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-secrets/specs/secrets/spec.md#requirement-update-secret
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) The typed params document
     *   the accepted body fields; only those actually present in the request
     *   are forwarded, read via getParams() to distinguish unset from null.
     */
    #[NoAdminRequired]
    public function update(
        string $id,
        ?string $name=null,
        ?string $url=null,
        ?string $key=null,
        ?string $login=null,
        ?string $additionalFields=null,
        ?string $typeId=null,
        ?string $folderId=null,
    ): JSONResponse {
        $userId = $this->requireUserId();
        if ($userId === null) {
            return $this->unauthorized();
        }

        // Only pass through keys actually present in the request body so the
        // service can distinguish "unset" from "set to null".
        $params = $this->request->getParams();
        $data   = [];
        foreach (['name', 'url', 'key', 'login', 'additionalFields', 'typeId', 'folderId'] as $field) {
            if (array_key_exists($field, $params) === true) {
                $data[$field] = $params[$field];
            }
        }

        try {
            $secret = $this->secretService->update($id, $data, $userId);
            return new JSONResponse(data: $secret->jsonSerialize());
        } catch (ForbiddenException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
        } catch (ConflictException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_LOCKED);
        } catch (DoesNotExistException) {
            return new JSONResponse(data: ['message' => 'Secret not found'], statusCode: Http::STATUS_NOT_FOUND);
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
        }//end try
    }//end update()

    /**
     * Delete a secret.
     *
     * @param string $id The secret ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-secrets/specs/secrets/spec.md#requirement-delete-secret
     */
    #[NoAdminRequired]
    public function destroy(string $id): JSONResponse
    {
        $userId = $this->requireUserId();
        if ($userId === null) {
            return $this->unauthorized();
        }

        try {
            $this->secretService->delete($id, $userId);
            return new JSONResponse(data: ['status' => 'deleted']);
        } catch (ForbiddenException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
        } catch (DoesNotExistException) {
            return new JSONResponse(data: ['message' => 'Secret not found'], statusCode: Http::STATUS_NOT_FOUND);
        }
    }//end destroy()

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
