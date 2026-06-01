<?php

/**
 * Doriath Dashboard Controller
 *
 * Controller for the main Doriath dashboard page.
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

use OCA\Doriath\AppInfo\Application;
use OCA\Doriath\Service\DashboardService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for the main Doriath dashboard page.
 */
class DashboardController extends Controller
{
    /**
     * Constructor for the DashboardController.
     *
     * @param IRequest         $request          The request object
     * @param DashboardService $dashboardService The dashboard aggregation service
     * @param IUserSession     $userSession      The user session
     * @param IGroupManager    $groupManager     The group manager
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private DashboardService $dashboardService,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Render the main dashboard page.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return TemplateResponse
     *
     * @spec exclude SPA-shell render — returns the index TemplateResponse with no domain logic; framework plumbing.
     */
    public function page(): TemplateResponse
    {
        return new TemplateResponse(appName: Application::APP_ID, templateName: 'index');
    }//end page()

    /**
     * Serve the SPA for deep links (Vue history mode). Delegates to {@see page()}.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return TemplateResponse
     *
     * @spec exclude Vue history-mode fallback — delegates to page(); pure framework plumbing, no domain logic.
     */
    public function catchAll(): TemplateResponse
    {
        return $this->page();
    }//end catchAll()

    /**
     * Return the aggregated vault summary for the current user.
     *
     * Determines admin status server-side (never trusted from the
     * client) and delegates aggregation to the DashboardService. The
     * endpoint is per-user: the summary only ever describes the
     * authenticated caller's own vault.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-dashboard-settings/specs/dashboard/spec.md
     */
    #[NoAdminRequired]
    public function summary(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $userId  = $user->getUID();
        $isAdmin = $this->groupManager->isAdmin($userId);

        return new JSONResponse(
            data: $this->dashboardService->fetchSummary($userId, $isAdmin)
        );
    }//end summary()
}//end class
