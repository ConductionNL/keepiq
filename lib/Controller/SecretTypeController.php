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

use InvalidArgumentException;
use OCA\Doriath\AppInfo\Application;
use OCA\Doriath\Service\SecretTypeService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
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
     * @param IGroupManager     $groupManager The group manager (admin checks)
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
     * List the types available to the current user.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-secrets/specs/secret-types/spec.md#requirement-user-custom-secrettypes
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        $userId = $this->requireUserId();
        if ($userId === null) {
            return $this->unauthorized();
        }

        $types = $this->typeService->getAvailableTypes($userId);
        return new JSONResponse(
            data: array_map(static fn ($t) => $t->jsonSerialize(), $types)
        );
    }//end index()

    /**
     * Create a custom type. Global scope requires admin.
     *
     * @param string      $name  The unique type name
     * @param string      $label The label
     * @param string|null $scope The scope (user or global; defaults to user)
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-secrets/specs/secret-types/spec.md#requirement-admin-global-secrettypes
     */
    #[NoAdminRequired]
    public function create(string $name, string $label, ?string $scope='user'): JSONResponse
    {
        $userId = $this->requireUserId();
        if ($userId === null) {
            return $this->unauthorized();
        }

        $scope = ($scope ?? 'user');
        if ($scope === 'global' && $this->isAdmin(userId: $userId) === false) {
            return new JSONResponse(
                data: ['message' => 'Only administrators can create global types'],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }

        try {
            $type = $this->typeService->createType($name, $label, $scope, $userId);
            return new JSONResponse(data: $type->jsonSerialize(), statusCode: Http::STATUS_CREATED);
        } catch (InvalidArgumentException $e) {
            // Duplicate names map to 409, scope errors to 400.
            $status = Http::STATUS_BAD_REQUEST;
            if (str_contains($e->getMessage(), 'already exists') === true) {
                $status = Http::STATUS_CONFLICT;
            }

            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: $status);
        }
    }//end create()

    /**
     * Update a custom type's label.
     *
     * @param string $id    The type ID
     * @param string $label The new label
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-secrets/specs/secret-types/spec.md#requirement-system-secrettypes
     */
    #[NoAdminRequired]
    public function update(string $id, string $label): JSONResponse
    {
        $userId = $this->requireUserId();
        if ($userId === null) {
            return $this->unauthorized();
        }

        try {
            $type = $this->typeService->updateType($id, $label, $userId, $this->isAdmin(userId: $userId));
            return new JSONResponse(data: $type->jsonSerialize());
        } catch (DoesNotExistException) {
            return new JSONResponse(data: ['message' => 'Type not found'], statusCode: Http::STATUS_NOT_FOUND);
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
        }
    }//end update()

    /**
     * Delete a custom type, reassigning its secrets to the login type.
     *
     * @param string $id The type ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-secrets/specs/secret-types/spec.md#requirement-custom-type-deletion-with-fallback
     */
    #[NoAdminRequired]
    public function destroy(string $id): JSONResponse
    {
        $userId = $this->requireUserId();
        if ($userId === null) {
            return $this->unauthorized();
        }

        try {
            $this->typeService->deleteType($id, $userId, $this->isAdmin(userId: $userId));
            return new JSONResponse(data: ['status' => 'deleted']);
        } catch (DoesNotExistException) {
            return new JSONResponse(data: ['message' => 'Type not found'], statusCode: Http::STATUS_NOT_FOUND);
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
        }
    }//end destroy()

    /**
     * Whether the user is in the admin group.
     *
     * @param string $userId The user UID
     *
     * @return bool
     */
    private function isAdmin(string $userId): bool
    {
        return $this->groupManager->isAdmin($userId);
    }//end isAdmin()

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
