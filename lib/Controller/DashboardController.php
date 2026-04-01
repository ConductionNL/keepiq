<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Doriath Dashboard Controller
 *
 * Serves the main dashboard SPA page and the summary API endpoint.
 *
 * @category Controller
 * @package  OCA\AppTemplate\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\AppTemplate\Controller;

use OCA\AppTemplate\AppInfo\Application;
use OCA\AppTemplate\Service\DashboardService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for the Doriath dashboard page and summary API.
 */
class DashboardController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest          $request          The request object.
     * @param DashboardService  $dashboardService The dashboard service.
     * @param IUserSession      $userSession      The user session.
     * @param IGroupManager     $groupManager     The group manager.
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
     * Render the main dashboard SPA page.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return TemplateResponse
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function page(): TemplateResponse
    {
        return new TemplateResponse(Application::APP_ID, 'index');
    }//end page()

    /**
     * Return vault summary KPIs.
     *
     * @OA\Get(
     *     path="/apps/app-template/api/dashboard/summary",
     *     summary="Get vault summary statistics",
     *     tags={"Dashboard"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Vault summary statistics",
     *         @OA\JsonContent(
     *             @OA\Property(property="totalSecrets",       type="integer"),
     *             @OA\Property(property="sharedSecrets",      type="integer"),
     *             @OA\Property(property="totalFolders",       type="integer"),
     *             @OA\Property(property="compromisedSecrets", type="integer"),
     *             @OA\Property(property="migrationPending",   type="integer"),
     *             @OA\Property(property="migrationFailed",    type="integer"),
     *             @OA\Property(property="pendingApps",        type="integer"),
     *             @OA\Property(property="caHealthy",          type="boolean")
     *         )
     *     )
     * )
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function summary(): JSONResponse
    {
        $user    = $this->userSession->getUser();
        $userId  = $user !== null ? $user->getUID() : '';
        $isAdmin = $this->groupManager->isAdmin($userId);

        $data = $this->dashboardService->fetchSummary(
            userId: $userId,
            isAdmin: $isAdmin
        );

        return new JSONResponse($data);
    }//end summary()
}//end class
