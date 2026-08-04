<?php

/**
 * Doriath Secret Controller
 *
 * Authenticated API controller for Secret CRUD, listing (paginated,
 * filtered, sorted) and fuzzy search. Encrypted fields are passed through
 * as ciphertext — the server never decrypts them (ADR-003).
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
use OCA\Doriath\Exception\ForbiddenException;
use OCA\Doriath\Exception\NotFoundException;
use OCA\Doriath\Exception\SuiteBlockedException;
use OCA\Doriath\Exception\WriteLockedException;
use OCA\Doriath\Service\SecretService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Authenticated API controller for Secret CRUD.
 */
class SecretController extends OCSController
{
    /**
     * HTTP 423 Locked status code.
     *
     * @var int
     */
    private const STATUS_LOCKED = 423;

    /**
     * Constructor for SecretController.
     *
     * @param IRequest      $request       The request object
     * @param SecretService $secretService The secret service
     * @param IUserSession  $userSession   The user session
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private SecretService $secretService,
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
     * List or search the current user's secrets.
     *
     * @param string|null $folderId  Filter by folder (omit = all)
     * @param string|null $search    Fuzzy search term
     * @param string|null $sort      Sort column
     * @param string      $direction Sort direction (asc/desc)
     * @param int         $page      Page number (1-based)
     * @param int         $limit     Items per page
     * @param string|null $typeId    Filter by secret-type ID (omit = all types)
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-secrets/tasks.md#task-4.1
     * @spec openspec/changes/passkey-item-type/specs/passkey-item-type/spec.md#requirement-passkey-listing-filtering-and-site-associated-presentation
     */
    #[NoAdminRequired]
    public function index(
        ?string $folderId=null,
        ?string $search=null,
        ?string $sort=null,
        string $direction='asc',
        int $page=1,
        int $limit=SecretService::DEFAULT_LIMIT,
        ?string $typeId=null,
    ): JSONResponse {
        $userId = $this->uid();
        if ($userId === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $result = $this->secretService->list($userId, $folderId, $sort, $direction, $page, $limit, $typeId);
        if ($search !== null && trim($search) !== '') {
            $result = $this->secretService->search($userId, $search, $page, $limit);
        }

        return new JSONResponse(data: $result);
    }//end index()

    /**
     * Get a single secret (returns encrypted blobs; never decrypts).
     *
     * @param string $id The secret ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-secrets/tasks.md#task-4.1
     */
    #[NoAdminRequired]
    public function show(string $id): JSONResponse
    {
        $userId = $this->uid();
        if ($userId === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        try {
            $secret = $this->secretService->get($id, $userId);
        } catch (NotFoundException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_NOT_FOUND);
        } catch (ForbiddenException | SuiteBlockedException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
        }

        return new JSONResponse(data: $secret->jsonSerialize());
    }//end show()

    /**
     * Create a secret. The key/login/additionalFields arrive as ciphertext.
     *
     * @param string      $name             The plaintext name
     * @param string      $key              The RSA-encrypted key blob (base64)
     * @param string|null $url              The plaintext URL
     * @param string|null $typeId           The type ID (null = login default)
     * @param string|null $folderId         The folder ID (null = root)
     * @param string|null $login            The RSA-encrypted login blob
     * @param string|null $additionalFields The RSA-encrypted additional fields blob
     * @param string|null $ownerType        The owner type (null = user; 'application' for app-owned)
     * @param string|null $ownerId          The owner ID (required when ownerType=application)
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-secrets/tasks.md#task-4.1
     *
     * @SuppressWarnings(PHPMD.LongVariable) Parameter names mirror the API payload fields.
     */
    #[NoAdminRequired]
    public function create(
        ?string $name=null,
        ?string $key=null,
        ?string $url=null,
        ?string $typeId=null,
        ?string $folderId=null,
        ?string $login=null,
        ?string $additionalFields=null,
        ?string $ownerType=null,
        ?string $ownerId=null,
    ): JSONResponse {
        $userId = $this->uid();
        if ($userId === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        // Validate required params HERE so a missing body returns 400, not a 500
        // from the framework dispatcher failing to bind non-nullable arguments.
        if ($name === null || $name === '' || $key === null || $key === '') {
            return new JSONResponse(
                data: ['message' => 'Missing required parameters: name and key are required'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        $data = [
            'name'             => $name,
            'key'              => $key,
            'url'              => $url,
            'typeId'           => $typeId,
            'folderId'         => $folderId,
            'login'            => $login,
            'additionalFields' => $additionalFields,
        ];

        if ($ownerType === 'application' && ($ownerId === null || $ownerId === '')) {
            return new JSONResponse(
                data: ['message' => 'ownerId is required when ownerType=application'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $secret = $this->createOwnedSecret(
                ownerType: $ownerType,
                ownerId: (string) $ownerId,
                data: $data,
                userId: $userId
            );
        } catch (SuiteBlockedException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
        } catch (WriteLockedException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: self::STATUS_LOCKED);
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
        }//end try

        return new JSONResponse(data: $secret->jsonSerialize(), statusCode: Http::STATUS_CREATED);
    }//end create()

    /**
     * Route a create to the application-owned or user-owned service path.
     * The caller has already rejected an application create without an
     * ownerId, so $ownerId is non-empty whenever it is read here.
     *
     * @param string|null         $ownerType The owner type ('application' or null/user)
     * @param string              $ownerId   The owning application's ID
     * @param array<string,mixed> $data      The encrypted payload
     * @param string              $userId    The writing user
     *
     * @return \OCA\Doriath\Db\Secret
     */
    private function createOwnedSecret(
        ?string $ownerType,
        string $ownerId,
        array $data,
        string $userId
    ): \OCA\Doriath\Db\Secret {
        if ($ownerType === 'application') {
            return $this->secretService->createForApplication(
                data: $data,
                applicationId: $ownerId,
                writingUserId: $userId,
            );
        }

        return $this->secretService->create($data, $userId);
    }//end createOwnedSecret()

    /**
     * Update a secret. Only the supplied fields are changed.
     *
     * @param string      $id               The secret ID
     * @param string|null $name             The new name
     * @param string|null $url              The new URL
     * @param string|null $typeId           The new type ID
     * @param string|null $folderId         The new folder ID
     * @param string|null $key              The new RSA-encrypted key blob
     * @param string|null $login            The new RSA-encrypted login blob
     * @param string|null $additionalFields The new RSA-encrypted additional fields blob
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-secrets/tasks.md#task-4.1
     *
     * @SuppressWarnings(PHPMD.LongVariable)          Parameter names mirror the API payload fields.
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Each optional field is an independent branch.
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) Each parameter is read indirectly via the
     *   variable-variable ${$field} loop that forwards only fields present in the request.
     */
    #[NoAdminRequired]
    public function update(
        string $id,
        ?string $name=null,
        ?string $url=null,
        ?string $typeId=null,
        ?string $folderId=null,
        ?string $key=null,
        ?string $login=null,
        ?string $additionalFields=null,
    ): JSONResponse {
        $userId = $this->uid();
        if ($userId === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        // Only forward fields that were explicitly provided in the request.
        $data = [];
        foreach (['name', 'url', 'typeId', 'folderId', 'key', 'login', 'additionalFields'] as $field) {
            if ($this->request->getParam($field, '__unset__') !== '__unset__') {
                $data[$field] = ${$field};
            }
        }

        try {
            $secret = $this->secretService->update($id, $data, $userId);
        } catch (NotFoundException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_NOT_FOUND);
        } catch (ForbiddenException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
        } catch (WriteLockedException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: self::STATUS_LOCKED);
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
        }

        return new JSONResponse(data: $secret->jsonSerialize());
    }//end update()

    /**
     * Delete a secret (cascades to its link shares).
     *
     * @param string $id The secret ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-secrets/tasks.md#task-4.1
     */
    #[NoAdminRequired]
    public function destroy(string $id): JSONResponse
    {
        $userId = $this->uid();
        if ($userId === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        try {
            $this->secretService->delete($id, $userId);
        } catch (NotFoundException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_NOT_FOUND);
        } catch (ForbiddenException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
        }

        return new JSONResponse(data: ['status' => 'deleted']);
    }//end destroy()
}//end class
