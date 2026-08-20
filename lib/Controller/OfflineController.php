<?php

/**
 * Doriath Offline Controller
 *
 * The consolidated offline-cache manifest endpoint (offline-readonly-cache
 * §1.3). The controller owns the two transport-level decisions — is the
 * caller authenticated, and is the org-wide switch on — and delegates the
 * snapshot itself to OfflineManifestService, which reads through the mappers
 * directly and NEVER decrypts: the secret key/login/additionalFields are
 * already ciphertext (ADR-003), and the plaintext name/url metadata is
 * encrypted at rest client-side. It is a bulk cache sync, not an individual
 * reveal, so it emits no secret.read audit event.
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
use OCA\Doriath\Service\OfflineManifestService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The owner-scoped offline-cache manifest endpoint.
 */
class OfflineController extends OCSController {
	/**
	 * Constructor for OfflineController.
	 *
	 * @param IRequest $request The request object
	 * @param OfflineManifestService $manifestService The snapshot assembler
	 * @param IAppConfig $appConfig The app config (off switch)
	 * @param IUserSession $userSession The user session
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private OfflineManifestService $manifestService,
		private IAppConfig $appConfig,
		private IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The consolidated offline snapshot for the calling user.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/offline-readonly-cache/spec.md#requirement-online-sessions-write-through-an-encrypted-local-snapshot
	 * @spec openspec/specs/offline-readonly-cache/spec.md#requirement-an-admin-can-disable-offline-caching-org-wide
	 */
	#[NoAdminRequired]
	public function manifest(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthenticated'], statusCode: Http::STATUS_FORBIDDEN);
		}

		if ($this->appConfig->getValueBool(Application::APP_ID, 'offline_cache_enabled', true) === false) {
			return new JSONResponse(
				data: ['message' => 'Offline caching is disabled by the administrator'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		try {
			$manifest = $this->manifestService->buildForUser(userId: $user->getUID());
		} catch (DoesNotExistException) {
			return new JSONResponse(data: ['message' => 'No active encryption suite'], statusCode: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(data: $manifest);
	}//end manifest()
}//end class
