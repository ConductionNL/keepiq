<?php

/**
 * Doriath Delegation Controller
 *
 * API controller for secret ownership delegation: list, create and reclaim.
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
use OCA\Doriath\Service\DelegationService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * API controller for secret ownership delegation.
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
     * List all delegations for a given secret.
     *
     * @param string $secretId The secret ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    public function index(string $secretId): JSONResponse
    {
        try {
            $delegations = $this->delegationService->getDelegationsForSecret(
                secretId: $secretId
            );

            $data = array_map(
                callback: static fn($d) => $d->jsonSerialize(),
                array: $delegations
            );

            return new JSONResponse(data: $data);
        } catch (Exception $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }
    }//end index()

    /**
     * Create a delegation for a secret.
     *
     * @param string $secretId   The secret ID to delegate
     * @param string $delegateTo The Nextcloud user ID to delegate to
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    public function create(string $secretId, string $delegateTo): JSONResponse
    {
        $userId = $this->userSession->getUser()->getUID();

        try {
            $delegation = $this->delegationService->createDelegation(
                secretId: $secretId,
                delegateTo: $delegateTo,
                initiatedBy: $userId
            );

            return new JSONResponse(
                data: $delegation->jsonSerialize(),
                statusCode: Http::STATUS_CREATED
            );
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_FORBIDDEN
            );
        } catch (Exception $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }//end try
    }//end create()

    /**
     * Reclaim all temporary delegations for a secret.
     *
     * @param string $secretId The secret ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    public function reclaim(string $secretId): JSONResponse
    {
        $userId = $this->userSession->getUser()->getUID();

        try {
            $this->delegationService->reclaimDelegation(
                secretId: $secretId,
                ownerId: $userId
            );

            return new JSONResponse(data: ['message' => 'Delegations reclaimed successfully']);
        } catch (Exception $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }
    }//end reclaim()
}//end class
