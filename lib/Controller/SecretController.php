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

use Exception;
use InvalidArgumentException;
use OCA\Doriath\AppInfo\Application;
use OCA\Doriath\Service\SecretService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * API controller for Secret CRUD operations.
 */
class SecretController extends OCSController
{
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
     * List secrets for the current user.
     *
     * @param string|null $folderId  Optional folder ID to filter by
     * @param string      $sort      The field to sort by
     * @param string      $direction The sort direction ('ASC' or 'DESC')
     * @param int         $page      The 1-based page number
     * @param int         $limit     The number of results per page
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    public function index(
        ?string $folderId=null,
        string $sort='name',
        string $direction='ASC',
        int $page=1,
        int $limit=50,
    ): JSONResponse {
        $userId = $this->userSession->getUser()->getUID();
        $result = $this->secretService->list(
            userId: $userId,
            folderId: $folderId,
            sort: $sort,
            direction: $direction,
            page: $page,
            limit: $limit
        );

        return new JSONResponse(data: $result);
    }//end index()

    /**
     * Get a specific secret.
     *
     * @param string $id The secret ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    public function show(string $id): JSONResponse
    {
        $userId = $this->userSession->getUser()->getUID();

        try {
            $secret = $this->secretService->get(id: $id, userId: $userId);
            return new JSONResponse(data: $secret->jsonSerialize());
        } catch (OCSForbiddenException $e) {
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
    }//end show()

    /**
     * Create a new secret for the current user.
     *
     * @param string      $name             The secret name
     * @param string|null $key              The encrypted key/password
     * @param string|null $login            The login/username
     * @param string|null $url              The associated URL
     * @param string|null $additionalFields Additional encrypted fields (JSON)
     * @param string|null $typeId           The secret type ID
     * @param string|null $folderId         The folder ID to store the secret in
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    public function create(
        string $name,
        ?string $key=null,
        ?string $login=null,
        ?string $url=null,
        ?string $additionalFields=null,
        ?string $typeId=null,
        ?string $folderId=null,
    ): JSONResponse {
        $userId = $this->userSession->getUser()->getUID();

        try {
            $secret = $this->secretService->create(
                data: [
                    'name'             => $name,
                    'key'              => $key,
                    'login'            => $login,
                    'url'              => $url,
                    'additionalFields' => $additionalFields,
                    'typeId'           => $typeId,
                    'folderId'         => $folderId,
                ],
                userId: $userId
            );
            return new JSONResponse(data: $secret->jsonSerialize(), statusCode: Http::STATUS_CREATED);
        } catch (OCSForbiddenException $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_LOCKED
            );
        } catch (Exception $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }//end try
    }//end create()

    /**
     * Update an existing secret.
     *
     * Only non-null fields are updated; absent parameters leave the secret unchanged.
     *
     * @param string      $id               The secret ID
     * @param string|null $name             The new secret name
     * @param string|null $key              The new encrypted key/password
     * @param string|null $login            The new login/username
     * @param string|null $url              The new associated URL
     * @param string|null $additionalFields The new additional encrypted fields (JSON)
     * @param string|null $typeId           The new secret type ID
     * @param string|null $folderId         The new folder ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    public function update(
        string $id,
        ?string $name=null,
        ?string $key=null,
        ?string $login=null,
        ?string $url=null,
        ?string $additionalFields=null,
        ?string $typeId=null,
        ?string $folderId=null,
    ): JSONResponse {
        $userId = $this->userSession->getUser()->getUID();

        $data = [];
        if ($name !== null) {
            $data['name'] = $name;
        }

        if ($key !== null) {
            $data['key'] = $key;
        }

        if ($login !== null) {
            $data['login'] = $login;
        }

        if ($url !== null) {
            $data['url'] = $url;
        }

        if ($additionalFields !== null) {
            $data['additionalFields'] = $additionalFields;
        }

        if ($typeId !== null) {
            $data['typeId'] = $typeId;
        }

        if ($folderId !== null) {
            $data['folderId'] = $folderId;
        }

        try {
            $secret = $this->secretService->update(id: $id, data: $data, userId: $userId);
            return new JSONResponse(data: $secret->jsonSerialize());
        } catch (OCSForbiddenException $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_LOCKED
            );
        } catch (Exception $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }
    }//end update()

    /**
     * Delete a secret.
     *
     * @param string $id The secret ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    public function destroy(string $id): JSONResponse
    {
        $userId = $this->userSession->getUser()->getUID();

        try {
            $this->secretService->delete(id: $id, userId: $userId);
            return new JSONResponse(data: ['message' => 'Secret deleted successfully']);
        } catch (Exception $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }
    }//end destroy()

    /**
     * Search secrets for the current user.
     *
     * @param string $term  The search term
     * @param int    $page  The 1-based page number
     * @param int    $limit The number of results per page
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    public function search(string $term, int $page=1, int $limit=50): JSONResponse
    {
        $userId = $this->userSession->getUser()->getUID();
        $result = $this->secretService->search(
            userId: $userId,
            term: $term,
            page: $page,
            limit: $limit
        );

        return new JSONResponse(data: $result);
    }//end search()
}//end class
