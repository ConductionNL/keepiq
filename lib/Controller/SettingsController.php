<?php

/**
 * Doriath Settings Controller
 *
 * Controller for managing Doriath application settings.
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
use OCA\Doriath\Service\SettingsService;
use OCA\Doriath\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for managing Doriath application settings.
 */
class SettingsController extends Controller
{
    /**
     * Constructor for the SettingsController.
     *
     * @param IRequest        $request         The request object
     * @param SettingsService $settingsService The settings service
     * @param IUserSession    $userSession     The user session
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

    /**
     * Retrieve all current settings.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-5
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        return new JSONResponse(
            data: $this->settingsService->getSettings()
        );
    }//end index()

    /**
     * Update settings with provided data (admin only).
     *
     * @AuthorizedAdminSetting(AdminSettings::class)
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-5
     */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function create(): JSONResponse
    {
        $data   = $this->request->getParams();
        $config = $this->settingsService->updateSettings($data);

        return new JSONResponse(
            data: [
                'success' => true,
                'config'  => $config,
            ]
        );
    }//end create()

    /**
     * Re-import the configuration from doriath_register.json (admin only).
     *
     * Forces a fresh import regardless of version, auto-configuring
     * all schema and register IDs from the import result.
     *
     * @AuthorizedAdminSetting(AdminSettings::class)
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-5
     */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function load(): JSONResponse
    {
        $result = $this->settingsService->loadConfiguration(force: true);

        return new JSONResponse(data: $result);
    }//end load()

    /**
     * Return the administrator-configurable settings (admin only).
     *
     * @AuthorizedAdminSetting(AdminSettings::class)
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-dashboard-settings/specs/admin-settings/spec.md
     */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function getAdminSettings(): JSONResponse
    {
        return new JSONResponse(data: $this->settingsService->getAdminSettings());
    }//end getAdminSettings()

    /**
     * Validate and persist administrator settings (admin only).
     *
     * Out-of-bounds values raise an InvalidArgumentException in the
     * service, surfaced here as a 400 with the validation message.
     *
     * @AuthorizedAdminSetting(AdminSettings::class)
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-dashboard-settings/specs/admin-settings/spec.md
     */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function updateAdminSettings(): JSONResponse
    {
        try {
            $data    = $this->request->getParams();
            $updated = $this->settingsService->updateAdminSettings($data);
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        return new JSONResponse(
            data: [
                'success' => true,
                'config'  => $updated,
            ]
        );
    }//end updateAdminSettings()

    /**
     * Return the current user's per-user preferences.
     *
     * Scoped to the authenticated caller — the user identifier comes
     * from the session, never from the request, so a user can only ever
     * read their own preferences.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-dashboard-settings/specs/user-settings/spec.md
     */
    #[NoAdminRequired]
    public function getUserSettings(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        return new JSONResponse(
            data: $this->settingsService->getUserPreferences($user->getUID())
        );
    }//end getUserSettings()

    /**
     * Update the current user's per-user preferences.
     *
     * Scoped to the authenticated caller — the user identifier comes
     * from the session, never the request. Only whitelisted keys are
     * persisted; the service silently ignores any other key.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-dashboard-settings/specs/user-settings/spec.md
     */
    #[NoAdminRequired]
    public function updateUserSettings(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $data    = $this->request->getParams();
        $updated = $this->settingsService->updateUserPreferences($user->getUID(), $data);

        return new JSONResponse(
            data: [
                'success'     => true,
                'preferences' => $updated,
            ]
        );
    }//end updateUserSettings()
}//end class
