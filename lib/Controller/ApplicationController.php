<?php

/**
 * Doriath Application Controller
 *
 * Nextcloud-session API controller for application registration, listing,
 * detail, approval queue actions, and hard-cascade deletion.
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
use OCA\Doriath\AppInfo\Application as AppInfo;
use OCA\Doriath\Service\ApplicationService;
use OCA\Doriath\Settings\AdminSettings;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;

/**
 * Session-authenticated API controller for application management.
 */
class ApplicationController extends OCSController
{
    /**
     * Constructor for ApplicationController.
     *
     * @param IRequest           $request            The request object
     * @param ApplicationService $applicationService The application service
     * @param IUserSession       $userSession        The user session
     * @param IGroupManager      $groupManager       The group manager
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private ApplicationService $applicationService,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
    ) {
        parent::__construct(appName: AppInfo::APP_ID, request: $request);
    }//end __construct()

    /**
     * List applications visible to the current user.
     *
     * @param string|null $status Optional status filter ('pending'|'active')
     * @param string|null $type   Optional type filter ('internal'|'external')
     * @param string      $sort   Sort column
     * @param string      $order  Sort direction
     * @param int         $page   1-based page number
     * @param int         $limit  Page size
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function index(
        ?string $status=null,
        ?string $type=null,
        string $sort='created_at',
        string $order='DESC',
        int $page=1,
        int $limit=25,
    ): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $filters = [];
        if ($status !== null && $status !== '') {
            $filters['status'] = $status;
        }

        if ($type !== null && $type !== '') {
            $filters['type'] = $type;
        }

        $result = $this->applicationService->list(
            userId: $user->getUID(),
            isAdmin: $this->isAdmin(userId: $user->getUID()),
            filters: $filters,
            sort: $sort,
            order: $order,
            page: $page,
            limit: $limit
        );

        return new JSONResponse(
            data: [
                'results' => array_map(static fn ($app) => $app->jsonSerialize(), $result['results']),
                'total'   => $result['total'],
                'page'    => $page,
                'limit'   => $limit,
            ]
        );
    }//end index()

    /**
     * Get a single application by ID.
     *
     * @param string $id The application ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function show(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        try {
            $application = $this->applicationService->get(
                applicationId: $id,
                userId: $user->getUID(),
                isAdmin: $this->isAdmin(userId: $user->getUID())
            );
        } catch (InvalidArgumentException) {
            return new JSONResponse(data: ['message' => 'Access denied'], statusCode: Http::STATUS_FORBIDDEN);
        } catch (RuntimeException) {
            return new JSONResponse(data: ['message' => 'Not found'], statusCode: Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse(data: $application->jsonSerialize());
    }//end show()

    /**
     * Register a new application (open to authenticated users and anonymous).
     *
     * @param string      $name        The application name (required)
     * @param string      $type        'internal' or 'external'
     * @param string|null $description Optional description
     * @param string|null $csr         Optional PEM-encoded PKCS#10 CSR
     *
     * @PublicPage
     *
     * @return JSONResponse
     */
    #[PublicPage]
    public function register(
        string $name,
        string $type='external',
        ?string $description=null,
        ?string $csr=null,
    ): JSONResponse {
        $user    = $this->userSession->getUser();
        $userId  = null;
        $isAdmin = false;
        if ($user !== null) {
            $userId  = $user->getUID();
            $isAdmin = $this->isAdmin(userId: $userId);
        }

        try {
            $result = $this->applicationService->register(
                name: $name,
                description: $description,
                type: $type,
                csr: $csr,
                userId: $userId,
                isAdmin: $isAdmin
            );
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
        }

        $payload = ['application' => $result['application']->jsonSerialize()];
        if ($result['privateKey'] !== null) {
            $payload['privateKey'] = $result['privateKey'];
        }

        return new JSONResponse(data: $payload, statusCode: Http::STATUS_CREATED);
    }//end register()

    /**
     * Delete an application with a hard cascade (admin only).
     *
     * @param string $id The application ID
     *
     * @AuthorizedAdminSetting(AdminSettings::class)
     *
     * @return JSONResponse
     */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function destroy(string $id): JSONResponse
    {
        try {
            $this->applicationService->delete($id);
        } catch (RuntimeException) {
            return new JSONResponse(data: ['message' => 'Not found'], statusCode: Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse(data: ['status' => 'deleted']);
    }//end destroy()

    /**
     * Approve a pending application (admin only).
     *
     * @param string $id The application ID
     *
     * @AuthorizedAdminSetting(AdminSettings::class)
     *
     * @return JSONResponse
     */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function approve(string $id): JSONResponse
    {
        $admin = $this->userSession->getUser();
        if ($admin === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        try {
            $result = $this->applicationService->approve(applicationId: $id, adminUserId: $admin->getUID());
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
        } catch (RuntimeException) {
            return new JSONResponse(data: ['message' => 'Not found'], statusCode: Http::STATUS_NOT_FOUND);
        }

        $payload = ['application' => $result['application']->jsonSerialize()];
        if ($result['privateKey'] !== null) {
            $payload['privateKey'] = $result['privateKey'];
        }

        return new JSONResponse(data: $payload);
    }//end approve()

    /**
     * Reject a pending application (hard delete; admin only).
     *
     * @param string $id The application ID
     *
     * @AuthorizedAdminSetting(AdminSettings::class)
     *
     * @return JSONResponse
     */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function reject(string $id): JSONResponse
    {
        $admin = $this->userSession->getUser();
        if ($admin === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        try {
            $this->applicationService->reject(applicationId: $id, adminUserId: $admin->getUID());
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
        } catch (RuntimeException) {
            return new JSONResponse(data: ['message' => 'Not found'], statusCode: Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse(data: ['status' => 'rejected']);
    }//end reject()

    /**
     * List pending applications (admin only).
     *
     * @AuthorizedAdminSetting(AdminSettings::class)
     *
     * @return JSONResponse
     */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function pending(): JSONResponse
    {
        $admin = $this->userSession->getUser();
        if ($admin === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $result = $this->applicationService->list(
            userId: $admin->getUID(),
            isAdmin: true,
            filters: ['status' => 'pending'],
            sort: 'created_at',
            order: 'ASC'
        );

        return new JSONResponse(
            data: array_map(static fn ($app) => $app->jsonSerialize(), $result['results'])
        );
    }//end pending()

    /**
     * Determine whether a user is a Nextcloud administrator.
     *
     * @param string $userId The user ID
     *
     * @return bool
     */
    private function isAdmin(string $userId): bool
    {
        return $this->groupManager->isAdmin($userId);
    }//end isAdmin()
}//end class
