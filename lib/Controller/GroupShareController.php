<?php

/**
 * Doriath Group Share Controller
 *
 * API controller for group-level secret sharing: list, create and revoke.
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
use OCA\Doriath\Service\GroupShareService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * API controller for group-level secret sharing.
 */
class GroupShareController extends OCSController
{
    /**
     * Constructor for GroupShareController.
     *
     * @param IRequest          $request           The request object
     * @param GroupShareService $groupShareService The group share service
     * @param IUserSession      $userSession       The user session
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private GroupShareService $groupShareService,
        private IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List all group shares for a given secret.
     *
     * @param string $secretId The secret ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    public function index(string $secretId): JSONResponse
    {
        $userId = $this->userSession->getUser()->getUID();

        try {
            $groupShares = $this->groupShareService->getGroupSharesForSecret(
                secretId: $secretId,
                userId: $userId
            );

            $data = array_map(
                callback: static fn($gs) => $gs->jsonSerialize(),
                array: $groupShares
            );

            return new JSONResponse(data: $data);
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
    }//end index()

    /**
     * Create a group share for a secret.
     *
     * Returns the created GroupShare and the list of eligible members with their public keys.
     *
     * @param string $secretId The secret ID to share
     * @param string $groupId  The Nextcloud group ID to share with
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    public function create(string $secretId, string $groupId): JSONResponse
    {
        $userId = $this->userSession->getUser()->getUID();

        try {
            $result = $this->groupShareService->createGroupShare(
                secretId: $secretId,
                groupId: $groupId,
                userId: $userId
            );

            return new JSONResponse(
                data: [
                    'groupShare' => $result['groupShare']->jsonSerialize(),
                    'members'    => $result['members'],
                ],
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
     * Revoke a group share by ID.
     *
     * @param string $id The group share ID to revoke
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    public function destroy(string $id): JSONResponse
    {
        $userId = $this->userSession->getUser()->getUID();

        try {
            $this->groupShareService->revokeGroupShare(
                groupShareId: $id,
                userId: $userId
            );

            return new JSONResponse(data: ['message' => 'Group share revoked successfully']);
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
    }//end destroy()
}//end class
