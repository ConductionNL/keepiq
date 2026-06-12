<?php

/**
 * Doriath Delegation Controller
 *
 * Authenticated API controller for the SecretDelegation lifecycle:
 *  - index    list delegations for a secret
 *  - create   create a delegation
 *  - reclaim  reclaim all temporary delegations
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
use OCA\Doriath\Service\DelegationService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Authenticated API controller for delegations.
 */
class DelegationController extends OCSController
{
    /**
     * Constructor for DelegationController.
     *
     * @param IRequest          $request           The request object
     * @param DelegationService $delegationService The delegation service
     * @param IUserSession      $userSession       The user session
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private DelegationService $delegationService,
        private IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List delegations for a secret.
     *
     * @param string $secretId The secret ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-user-sharing/tasks.md#9.4
     */
    #[NoAdminRequired]
    public function index(string $secretId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        try {
            $rows = $this->delegationService->getDelegationsForSecret(
                secretId: $secretId,
                ownerId: $user->getUID()
            );
        } catch (InvalidArgumentException $exception) {
            return new JSONResponse(
                data: ['message' => $exception->getMessage()],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }

        return new JSONResponse(
            data: array_map(static fn ($row) => $row->jsonSerialize(), $rows)
        );
    }//end index()

    /**
     * Create a temporary delegation.
     *
     * @param string $secretId    The secret ID
     * @param string $delegatedTo The delegate's UID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-user-sharing/tasks.md#9.4
     */
    #[NoAdminRequired]
    public function create(string $secretId, string $delegatedTo): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        try {
            $entity = $this->delegationService->createDelegation(
                secretId: $secretId,
                delegatedTo: $delegatedTo,
                initiatedBy: $user->getUID()
            );
        } catch (InvalidArgumentException $exception) {
            return new JSONResponse(
                data: ['message' => $exception->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        return new JSONResponse(data: $entity->jsonSerialize(), statusCode: Http::STATUS_CREATED);
    }//end create()

    /**
     * Reclaim all temporary delegations for a secret.
     *
     * @param string $secretId The secret ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-user-sharing/tasks.md#9.4
     */
    #[NoAdminRequired]
    public function reclaim(string $secretId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        try {
            $removed = $this->delegationService->reclaimDelegation(
                secretId: $secretId,
                ownerId: $user->getUID()
            );
        } catch (InvalidArgumentException $exception) {
            return new JSONResponse(
                data: ['message' => $exception->getMessage()],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }

        return new JSONResponse(data: ['removed' => $removed]);
    }//end reclaim()
}//end class
