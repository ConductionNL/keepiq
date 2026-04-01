<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Doriath Settings Controller
 *
 * Handles admin application settings and per-user preferences via REST API.
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
use OCA\AppTemplate\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Handles admin application settings and per-user preferences.
 */
class SettingsController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest         $request         The request object.
     * @param SettingsService  $settingsService The settings service.
     * @param IUserSession     $userSession     The user session.
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private SettingsService $settingsService,
        private IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    // =========================================================================
    // Admin endpoints (Task 2.2)
    // =========================================================================

    /**
     * Get admin settings (admin-only).
     *
     * @OA\Get(
     *     path="/apps/app-template/api/settings/admin",
     *     summary="Get admin settings",
     *     tags={"Settings"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Current admin settings",
     *         @OA\JsonContent(
     *             @OA\Property(property="passwordMinLength", type="integer", minimum=12, maximum=20),
     *             @OA\Property(property="passwordMinScore",  type="integer", minimum=3, maximum=4),
     *             @OA\Property(property="caStatus",          type="string",
     *                 enum={"healthy","degraded","unknown"})
     *         )
     *     ),
     *     @OA\Response(response=403, description="Forbidden — admin required")
     * )
     *
     * @return JSONResponse
     */
    public function getAdminSettings(): JSONResponse
    {
        return new JSONResponse($this->settingsService->getAdminSettings());
    }//end getAdminSettings()

    /**
     * Update admin settings (admin-only).
     *
     * @OA\Put(
     *     path="/apps/app-template/api/settings/admin",
     *     summary="Update admin settings",
     *     tags={"Settings"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="passwordMinLength", type="integer", minimum=12, maximum=20),
     *             @OA\Property(property="passwordMinScore",  type="integer", minimum=3,  maximum=4)
     *         )
     *     ),
     *     @OA\Response(response=200, description="Updated admin settings"),
     *     @OA\Response(response=400, description="Validation error"),
     *     @OA\Response(response=403, description="Forbidden — admin required")
     * )
     *
     * @return JSONResponse
     */
    public function updateAdminSettings(): JSONResponse
    {
        $data = $this->request->getParams();

        try {
            $result = $this->settingsService->updateAdminSettings($data);
            return new JSONResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST
            );
        }
    }//end updateAdminSettings()

    // =========================================================================
    // User endpoints (Task 2.3)
    // =========================================================================

    /**
     * Get the current user's preferences.
     *
     * @OA\Get(
     *     path="/apps/app-template/api/settings/user",
     *     summary="Get user preferences",
     *     tags={"Settings"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Current user preferences",
     *         @OA\JsonContent(
     *             @OA\Property(property="sessionTimeout",    type="integer"),
     *             @OA\Property(property="notifyShares",      type="boolean"),
     *             @OA\Property(property="notifyRequests",    type="boolean"),
     *             @OA\Property(property="notifyGroupShares", type="boolean"),
     *             @OA\Property(property="notifySecurity",    type="boolean"),
     *             @OA\Property(property="defaultType",       type="string"),
     *             @OA\Property(property="defaultView",       type="string")
     *         )
     *     )
     * )
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function getUserSettings(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        return new JSONResponse(
            $this->settingsService->getUserPreferences($user->getUID())
        );
    }//end getUserSettings()

    /**
     * Update the current user's preferences.
     *
     * @OA\Put(
     *     path="/apps/app-template/api/settings/user",
     *     summary="Update user preferences",
     *     tags={"Settings"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="sessionTimeout",    type="integer"),
     *             @OA\Property(property="notifyShares",      type="boolean"),
     *             @OA\Property(property="notifyRequests",    type="boolean"),
     *             @OA\Property(property="notifyGroupShares", type="boolean"),
     *             @OA\Property(property="notifySecurity",    type="boolean")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Updated user preferences")
     * )
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function updateUserSettings(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $data   = $this->request->getParams();
        $result = $this->settingsService->updateUserPreferences($user->getUID(), $data);

        return new JSONResponse($result);
    }//end updateUserSettings()
}//end class
