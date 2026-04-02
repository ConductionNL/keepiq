<?php

/**
 * Doriath Secret Type Controller
 *
 * API controller for SecretType CRUD operations.
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
use OCA\Doriath\Service\SecretTypeService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * API controller for SecretType CRUD operations.
 */
class SecretTypeController extends OCSController
{
    /**
     * Constructor for SecretTypeController.
     *
     * @param IRequest          $request      The request object
     * @param SecretTypeService $typeService  The secret type service
     * @param IUserSession      $userSession  The user session
     * @param IGroupManager     $groupManager The group manager
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private SecretTypeService $typeService,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List all available secret types for the current user.
     *
     * Returns system types, global types and the user's own types.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    public function index(): JSONResponse
    {
        $userId = $this->userSession->getUser()->getUID();
        $types  = $this->typeService->getAvailableTypes(userId: $userId);

        return new JSONResponse(
            data: array_map(
                static fn ($type) => $type->jsonSerialize(),
                $types
            )
        );
    }//end index()

    /**
     * Create a new secret type.
     *
     * For 'global' scope, the current user must be an admin.
     * For 'user' scope, the type is owned by the current user.
     *
     * @param string $name  The slug identifier for the type
     * @param string $label The human-readable label
     * @param string $scope The scope ('user' or 'global')
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    public function create(string $name, string $label, string $scope): JSONResponse
    {
        $userId = $this->userSession->getUser()->getUID();

        if ($scope === 'global') {
            if ($this->groupManager->isAdmin(userId: $userId) === false) {
                return new JSONResponse(
                    data: ['message' => 'Only administrators may create global secret types'],
                    statusCode: Http::STATUS_FORBIDDEN
                );
            }

            $ownerId = null;
        } else {
            $ownerId = $userId;
        }

        try {
            $type = $this->typeService->createType(
                name: $name,
                label: $label,
                scope: $scope,
                ownerId: $ownerId
            );
            return new JSONResponse(data: $type->jsonSerialize(), statusCode: Http::STATUS_CREATED);
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }
    }//end create()

    /**
     * Update the label of an existing secret type.
     *
     * @param string $id    The secret type ID
     * @param string $label The new human-readable label
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    public function update(string $id, string $label): JSONResponse
    {
        $userId = $this->userSession->getUser()->getUID();

        try {
            $type = $this->typeService->updateType(id: $id, label: $label, userId: $userId);
            return new JSONResponse(data: $type->jsonSerialize());
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
    }//end update()

    /**
     * Delete a secret type.
     *
     * @param string $id The secret type ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    public function destroy(string $id): JSONResponse
    {
        $userId = $this->userSession->getUser()->getUID();

        try {
            $this->typeService->deleteType(id: $id, userId: $userId);
            return new JSONResponse(data: ['message' => 'Secret type deleted successfully']);
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
