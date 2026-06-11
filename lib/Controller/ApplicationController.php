<?php

/**
 * Doriath Application Controller
 *
 * Authenticated API controller for application registration + admin
 * approval queue. The #[PublicPage] anonymous-registration endpoint and
 * the JWT-Bearer secret-write path land with the dedicated
 * implement-application-mgmt build cycle.
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
use OCA\Doriath\AppInfo\Application as DoriathApp;
use OCA\Doriath\Db\Application;
use OCA\Doriath\Service\ApplicationService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Authenticated API controller for application management.
 */
class ApplicationController extends OCSController
{
    /**
     * Constructor for ApplicationController.
     *
     * @param IRequest           $request      The request object
     * @param ApplicationService $service      The application service
     * @param IUserSession       $session      The user session
     * @param IGroupManager      $groupManager The group manager
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private ApplicationService $service,
        private IUserSession $session,
        private IGroupManager $groupManager,
    ) {
        parent::__construct(appName: DoriathApp::APP_ID, request: $request);
    }//end __construct()

    /**
     * List applications visible to the current user.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-application-mgmt/tasks.md#task-6.1
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        $user = $this->session->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $uid     = $user->getUID();
        $isAdmin = $this->groupManager->isAdmin($uid);

        $apps = $this->service->listForUser($uid, $isAdmin);

        return new JSONResponse(
            data: array_map(static fn (Application $a) => $a->jsonSerialize(), $apps)
        );
    }//end index()

    /**
     * List pending applications (admin-only).
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-application-mgmt/tasks.md#task-6.1
     */
    #[NoAdminRequired]
    public function pending(): JSONResponse
    {
        $user = $this->session->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $isAdmin = $this->groupManager->isAdmin($user->getUID());

        try {
            $pending = $this->service->listPending($isAdmin);
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }

        return new JSONResponse(
            data: array_map(static fn (Application $a) => $a->jsonSerialize(), $pending)
        );
    }//end pending()

    /**
     * Get a single application.
     *
     * @param string $id The application ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-application-mgmt/tasks.md#task-6.1
     */
    #[NoAdminRequired]
    public function show(string $id): JSONResponse
    {
        $user = $this->session->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $uid     = $user->getUID();
        $isAdmin = $this->groupManager->isAdmin($uid);

        try {
            $entity = $this->service->get($id, $uid, $isAdmin);
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        return new JSONResponse(data: $entity->jsonSerialize());
    }//end show()

    /**
     * Register a new application.
     *
     * Admin callers auto-approve (status=active); non-admin callers
     * create a pending row.
     *
     * @param string      $name        The application name
     * @param string|null $description Optional description
     * @param string      $type        Application type (internal|external)
     * @param string|null $csr         Optional PKCS#10 CSR
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-application-mgmt/tasks.md#task-6.1
     */
    #[NoAdminRequired]
    public function create(
        string $name,
        ?string $description=null,
        string $type=Application::TYPE_EXTERNAL,
        ?string $csr=null,
    ): JSONResponse {
        $user = $this->session->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $uid     = $user->getUID();
        $isAdmin = $this->groupManager->isAdmin($uid);

        try {
            $entity = $this->service->register(
                name: $name,
                description: $description,
                type: $type,
                csr: $csr,
                userId: $uid,
                isAdmin: $isAdmin
            );
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        return new JSONResponse(data: $entity->jsonSerialize(), statusCode: Http::STATUS_CREATED);
    }//end create()

    /**
     * Approve a pending application (admin-only).
     *
     * @param string $id The application ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-application-mgmt/tasks.md#task-6.1
     */
    #[NoAdminRequired]
    public function approve(string $id): JSONResponse
    {
        $user = $this->session->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $uid     = $user->getUID();
        $isAdmin = $this->groupManager->isAdmin($uid);

        try {
            $entity = $this->service->approve(applicationId: $id, adminUserId: $uid, isAdmin: $isAdmin);
        } catch (InvalidArgumentException $e) {
            $status = ($isAdmin === false) ? Http::STATUS_FORBIDDEN : Http::STATUS_BAD_REQUEST;
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: $status);
        }

        return new JSONResponse(data: $entity->jsonSerialize());
    }//end approve()

    /**
     * Reject a pending application (admin-only).
     *
     * @param string $id The application ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-application-mgmt/tasks.md#task-6.1
     */
    #[NoAdminRequired]
    public function reject(string $id): JSONResponse
    {
        $user = $this->session->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $uid     = $user->getUID();
        $isAdmin = $this->groupManager->isAdmin($uid);

        try {
            $this->service->reject(applicationId: $id, adminUserId: $uid, isAdmin: $isAdmin);
        } catch (InvalidArgumentException $e) {
            $status = ($isAdmin === false) ? Http::STATUS_FORBIDDEN : Http::STATUS_BAD_REQUEST;
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: $status);
        }

        return new JSONResponse(data: ['status' => 'rejected', 'id' => $id]);
    }//end reject()

    /**
     * Delete an application (admin-only).
     *
     * @param string $id The application ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-application-mgmt/tasks.md#task-6.1
     */
    #[NoAdminRequired]
    public function destroy(string $id): JSONResponse
    {
        $user = $this->session->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $isAdmin = $this->groupManager->isAdmin($user->getUID());

        try {
            $this->service->delete(applicationId: $id, isAdmin: $isAdmin);
        } catch (InvalidArgumentException $e) {
            $status = ($isAdmin === false) ? Http::STATUS_FORBIDDEN : Http::STATUS_BAD_REQUEST;
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: $status);
        }

        return new JSONResponse(data: ['status' => 'deleted', 'id' => $id]);
    }//end destroy()
}//end class
