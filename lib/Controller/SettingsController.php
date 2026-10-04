<?php

/**
 * Keepiq Settings Controller
 *
 * Controller for managing Keepiq application settings.
 *
 * @category Controller
 * @package  OCA\Keepiq\Controller
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

namespace OCA\Keepiq\Controller;

use InvalidArgumentException;
use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Service\AdminAreaAuthorizer;
use OCA\Keepiq\Service\SettingsService;
use OCA\Keepiq\Settings\AdminSettings;
use OCA\Keepiq\Settings\PolicyAdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for managing Keepiq application settings.
 */
class SettingsController extends Controller {
	/**
	 * Constructor for the SettingsController.
	 *
	 * @param IRequest $request The request object
	 * @param SettingsService $settingsService The settings service
	 * @param IUserSession $userSession The user session
	 * @param AdminAreaAuthorizer|null $areas The admin areas the session user holds, for the settings payload
	 * @param \OCA\Keepiq\Service\TwoFactorGate|null $twoFactor The two-factor gap count (admin-vault-policies §1.3)
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-002-a-save-asks-integriq-to-look-again-and-a-lookup-or-a-drain-reports-what-it-met
	 */
	public function __construct(
		IRequest $request,
		private SettingsService $settingsService,
		private IUserSession $userSession,
		private ?AdminAreaAuthorizer $areas = null,
		private ?\OCA\Keepiq\Service\TwoFactorGate $twoFactor = null,
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
	 * @spec openspec/changes/admin-scoped-roles/tasks.md#2.5
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		// The admin areas the user holds (admin-scoped-roles §2.5), so the UI
		// offers an admin panel only to someone its endpoint lets through.
		// Display only: every endpoint checks its own area.
		$settings = $this->settingsService->getSettings();
		$settings['adminAreas'] = ($this->areas?->areasOf(userId: $this->userSession->getUser()->getUID()) ?? []);

		return new JSONResponse(data: $settings);
	}//end index()

	/**
	 * Update settings with provided data (admin only).
	 *
	 * This is the canonical AppHost write, matching
	 * {@see \OCA\OpenRegister\AppHost\Controller\GenericSettingsControllerBase::update()}.
	 * `\OCA\OpenRegister\AppHost\Routes::standard()` — which `appinfo/routes.php`
	 * returns wholesale — ships `['name' => 'settings#update', 'url' =>
	 * '/api/settings', 'verb' => 'PUT']`, and because Keepiq ships its own
	 * `SettingsController` class the AppHost generic is never aliased in
	 * (`AppHost\Bootstrap::aliasControllerUnlessLeafDefinesIt()` only binds the
	 * alias when the leaf does NOT define the class). So this method has to
	 * exist here: without it the router matches the URL, the dispatcher
	 * reflects the method, and the request dies with a 500 ReflectionException
	 * rather than a 404.
	 *
	 * The write itself delegates to {@see SettingsService::updateSettings()},
	 * which persists the app-scoped `CONFIG_KEYS` via `IAppConfig` and returns
	 * the refreshed settings map (stored keys plus the `openregisters` and
	 * `isAdmin` metadata flags read by the settings UI).
	 *
	 * It writes the master password floor, so it is guarded by the Policies
	 * area (admin-scoped-roles D2).
	 *
	 * A rejected value answers 400 rather than the `{success: true}` envelope.
	 * Before #192 an unwritable value was indistinguishable from a stored one,
	 * because the write loop simply never matched and the envelope was
	 * unconditional; a bounded key that fails validation must now say so.
	 *
	 * @AuthorizedAdminSetting(PolicyAdminSettings::class)
	 *
	 * @return JSONResponse The refreshed settings, wrapped as `{success, config}`.
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md — Requirement: Boilerplate Plumbing
	 *   Served by AppHost Generics (Scenario: Admin settings page still renders
	 *   through the generic section)
	 */
	#[AuthorizedAdminSetting(PolicyAdminSettings::class)]
	public function update(): JSONResponse {
		$data = $this->request->getParams();

		try {
			$config = $this->settingsService->updateSettings($data);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(
				data: ['success' => false, 'message' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(
			data: [
				'success' => true,
				'config' => $config,
			]
		);
	}//end update()

	/**
	 * Legacy POST alias for {@see update()} (admin only).
	 *
	 * The canonical AppHost route table still ships `settings#create`
	 * (POST /api/settings) for the pre-ADR-066 `index/create/load` dialect, and
	 * two Keepiq callers still use it — `src/components/settings/
	 * PasswordPolicySection.vue::save()` and `src/store/modules/
	 * settings.js::saveSettings()` — so it stays reachable and keeps writing
	 * exactly what it wrote before (ADR-029).
	 *
	 * The `#[AuthorizedAdminSetting]` attribute is repeated deliberately:
	 * Nextcloud's SecurityMiddleware only evaluates the attributes of the
	 * DISPATCHED method, so delegating to `update()` does not inherit its
	 * posture. Both entry points therefore declare the same admin gate.
	 *
	 * @AuthorizedAdminSetting(PolicyAdminSettings::class)
	 *
	 * @return JSONResponse The refreshed settings, wrapped as `{success, config}`.
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md — Requirement: Boilerplate Plumbing
	 *   Served by AppHost Generics (Scenario: Admin settings page still renders
	 *   through the generic section)
	 */
	#[AuthorizedAdminSetting(PolicyAdminSettings::class)]
	public function create(): JSONResponse {
		return $this->update();
	}//end create()

	/**
	 * Re-import the configuration from keepiq_register.json (admin only).
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
	public function load(): JSONResponse {
		$result = $this->settingsService->loadConfiguration(force: true);

		return new JSONResponse(data: $result);
	}//end load()

	/**
	 * How many users a two-factor vault policy for these groups covers, and
	 * how many have no second factor yet (admin-vault-policies §1.3). Counts
	 * only, admin only.
	 *
	 * @param array<int,string> $groups The group scope; empty means everyone
	 *
	 * @AuthorizedAdminSetting(PolicyAdminSettings::class)
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/admin-vault-policies/tasks.md#1.3
	 */
	#[AuthorizedAdminSetting(PolicyAdminSettings::class)]
	public function twoFactorGaps(array $groups = []): JSONResponse {
		if ($this->twoFactor === null) {
			return new JSONResponse(data: ['message' => 'Unavailable'], statusCode: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse(data: $this->twoFactor->gapReport(groupIds: array_values(array_map('strval', $groups))));
	}//end twoFactorGaps()

	/**
	 * Get the current user's preferences (implement-dashboard-settings §2.3).
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-dashboard-settings/tasks.md#task-2.3
	 */
	#[NoAdminRequired]
	public function getUserSettings(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(data: $this->settingsService->getUserPreferences($user->getUID()));
	}//end getUserSettings()

	/**
	 * Update the current user's preferences (implement-dashboard-settings §2.3).
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-dashboard-settings/tasks.md#task-2.3
	 */
	#[NoAdminRequired]
	public function updateUserSettings(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$data = $this->request->getParams();
		$result = $this->settingsService->updateUserPreferences($user->getUID(), $data);

		return new JSONResponse(data: $result);
	}//end updateUserSettings()

	/**
	 * Read-only org password policy for the write dialogs — every
	 * authenticated user may read the floor (org-password-policies §1.3).
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/org-password-policies/spec.md#requirement-configurable-org-password-policy
	 */
	#[NoAdminRequired]
	public function getPolicy(): JSONResponse {
		// Same guard as index(): the policy floor is readable by every
		// AUTHENTICATED user, which is not the same as being public. The
		// middleware already rejects anonymous callers, but this endpoint
		// states its own precondition rather than relying on a route
		// attribute staying correct — index() has always done so, and the two
		// reads on this controller should not disagree about who may call
		// them.
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(data: $this->settingsService->getPolicy());
	}//end getPolicy()
}//end class
