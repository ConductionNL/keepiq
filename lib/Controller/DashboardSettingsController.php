<?php

/**
 * Keepiq Dashboard Settings Controller
 *
 * Authenticated API controller for the per-user dashboard preference
 * scaffold. The full dashboard-summary endpoint (totals + CA health +
 * migration banner + pending-apps card) and the admin/user settings
 * split land with the dedicated implement-dashboard-settings build cycle.
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
use OCA\Keepiq\Service\DashboardService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Authenticated API controller for per-user dashboard settings.
 */
class DashboardSettingsController extends OCSController {
	/**
	 * Constructor for DashboardSettingsController.
	 *
	 * @param IRequest $request The request object
	 * @param DashboardService $service The dashboard-settings service
	 * @param IUserSession $session The user session
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private DashboardService $service,
		private IUserSession $session,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Return the current user's dashboard settings as a flat map.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-dashboard-settings/tasks.md#task-2.3
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$user = $this->session->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(data: $this->service->listForUser($user->getUID()));
	}//end index()

	/**
	 * Read a single dashboard setting for the current user.
	 *
	 * @param string $key The setting key
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-dashboard-settings/tasks.md#task-2.3
	 */
	#[NoAdminRequired]
	public function show(string $key): JSONResponse {
		$user = $this->session->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$value = $this->service->get($user->getUID(), $key);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(
				data: ['message' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(data: ['key' => $key, 'value' => $value]);
	}//end show()

	/**
	 * Set (or replace) one or more dashboard settings for the current user.
	 *
	 * The request body may either contain a `key`+`value` pair to set a
	 * single setting, or a `settings` object to bulk-set a map. Unknown
	 * keys are rejected.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-dashboard-settings/tasks.md#task-2.3
	 */
	#[NoAdminRequired]
	public function update(): JSONResponse {
		$user = $this->session->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$params = $this->request->getParams();
		$userId = $user->getUID();

		try {
			$result = $this->applyUpdate(userId: $userId, params: $params);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(
				data: ['message' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(data: $result);
	}//end update()

	/**
	 * Apply one update request: a bulk `settings` map when present, else a
	 * single `key`/`value` pair. Returns the resulting settings list.
	 *
	 * @param string $userId The session user
	 * @param array<string,mixed> $params The request parameters
	 *
	 * @return array<string,mixed>
	 *
	 * @throws InvalidArgumentException When neither shape is satisfied.
	 */
	private function applyUpdate(string $userId, array $params): array {
		if (isset($params['settings']) === true && is_array($params['settings']) === true) {
			return $this->service->setMany($userId, $params['settings']);
		}

		if (isset($params['key']) === false || is_string($params['key']) === false || $params['key'] === '') {
			throw new InvalidArgumentException(message: 'key is required');
		}

		$value = ($params['value'] ?? null);
		$this->service->set($userId, $params['key'], $value);

		return $this->service->listForUser($userId);
	}//end applyUpdate()

	/**
	 * Reset (cascade-delete) all dashboard settings for the current user.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-dashboard-settings/tasks.md#task-2.3
	 */
	#[NoAdminRequired]
	public function destroy(): JSONResponse {
		$user = $this->session->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$this->service->deleteAllForUser($user->getUID());

		return new JSONResponse(data: ['status' => 'deleted']);
	}//end destroy()
}//end class
