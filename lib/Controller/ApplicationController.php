<?php

/**
 * Keepiq Application Controller
 *
 * Authenticated API controller for application registration + admin
 * approval queue. The #[PublicPage] anonymous-registration endpoint and
 * the JWT-Bearer secret-write path land with the dedicated
 * implement-application-mgmt build cycle.
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
use OCA\Keepiq\AppInfo\Application as KeepiqApp;
use OCA\Keepiq\Db\Application;
use OCA\Keepiq\Service\ApplicationService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Authenticated API controller for application management.
 */
class ApplicationController extends OCSController {
	/**
	 * Constructor for ApplicationController.
	 *
	 * @param IRequest $request The request object
	 * @param ApplicationService $service The application service
	 * @param IUserSession $session The user session
	 * @param IGroupManager $groupManager The group manager
	 * @param IAppConfig $appConfig The app config
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private ApplicationService $service,
		private IUserSession $session,
		private IGroupManager $groupManager,
		private IAppConfig $appConfig,
	) {
		parent::__construct(appName: KeepiqApp::APP_ID, request: $request);
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
	public function index(): JSONResponse {
		$user = $this->session->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$uid = $user->getUID();
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
	public function pending(): JSONResponse {
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
	public function show(string $id): JSONResponse {
		$user = $this->session->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$uid = $user->getUID();
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
	 * Three authorization branches:
	 *  1. Admin caller: auto-approve (status=active).
	 *  2. Authenticated non-admin: create a pending row, admin queue.
	 *  3. Anonymous (no NC session): ONLY accepted when the admin has
	 *     explicitly opted in via the app-config flag
	 *     `anonymous_application_registration_enabled` = "1". The row is
	 *     created with registeredBy='anonymous' and goes through the
	 *     standard pending queue — the admin-notification dispatch in
	 *     ApplicationService::register is the audit trail.
	 *
	 * The route is declared PublicPage so the NC framework lets
	 * anonymous traffic through; the opt-in check runs at the top of
	 * the controller body so a non-opted-in instance still 401s.
	 *
	 * @param string $name The application name
	 * @param string|null $description Optional description
	 * @param string $type Application type (internal|external)
	 * @param string|null $csr Optional PKCS#10 CSR
	 *
	 * @NoAdminRequired
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-application-mgmt/tasks.md#task-6.1
	 * @spec openspec/changes/implement-application-mgmt/tasks.md#task-6.2
	 */
	#[NoAdminRequired]
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	public function create(
		?string $name = null,
		?string $description = null,
		string $type = Application::TYPE_EXTERNAL,
		?string $csr = null,
	): JSONResponse {
		// A missing/blank name is a client validation error, not a 500. Without
		// a nullable default, NC's dispatcher passes null for an omitted `name`
		// and PHP raises a TypeError before the method body runs.
		if ($name === null || trim($name) === '') {
			return new JSONResponse(
				data: ['message' => 'name is required'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		$user = $this->session->getUser();

		if ($user === null) {
			// Anonymous branch: only honoured when the admin opts in via
			// app-config. The flag is read on every call (cheap key
			// lookup) so toggling it takes effect immediately.
			$anonEnabled = $this->appConfig->getValueString(
				KeepiqApp::APP_ID,
				'anonymous_application_registration_enabled',
				'0'
			);
			if ($anonEnabled !== '1') {
				return new JSONResponse(
					data: ['message' => 'Unauthorized'],
					statusCode: Http::STATUS_UNAUTHORIZED
				);
			}
		}

		// Anonymous registrations carry no uid and are never admin.
		$uid = null;
		$isAdmin = false;
		if ($user !== null) {
			$uid = $user->getUID();
			$isAdmin = $this->groupManager->isAdmin($uid);
		}

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
	public function approve(string $id): JSONResponse {
		$user = $this->session->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$uid = $user->getUID();
		$isAdmin = $this->groupManager->isAdmin($uid);

		try {
			$entity = $this->service->approve(applicationId: $id, adminUserId: $uid, isAdmin: $isAdmin);
		} catch (InvalidArgumentException $e) {
			$status = Http::STATUS_BAD_REQUEST;
			if ($isAdmin === false) {
				$status = Http::STATUS_FORBIDDEN;
			}

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
	public function reject(string $id): JSONResponse {
		$user = $this->session->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$uid = $user->getUID();
		$isAdmin = $this->groupManager->isAdmin($uid);

		try {
			$this->service->reject(applicationId: $id, adminUserId: $uid, isAdmin: $isAdmin);
		} catch (InvalidArgumentException $e) {
			$status = Http::STATUS_BAD_REQUEST;
			if ($isAdmin === false) {
				$status = Http::STATUS_FORBIDDEN;
			}

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
	public function destroy(string $id): JSONResponse {
		$user = $this->session->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$isAdmin = $this->groupManager->isAdmin($user->getUID());

		try {
			$this->service->delete(applicationId: $id, isAdmin: $isAdmin);
		} catch (InvalidArgumentException $e) {
			$status = Http::STATUS_BAD_REQUEST;
			if ($isAdmin === false) {
				$status = Http::STATUS_FORBIDDEN;
			}

			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: $status);
		}

		return new JSONResponse(data: ['status' => 'deleted', 'id' => $id]);
	}//end destroy()

	/**
	 * Get the active EncryptionSuite certificate for an application.
	 *
	 * Used by the write-secret-for-app flow: the caller imports the
	 * embedded public key client-side, encrypts the plaintext, and
	 * POSTs the ciphertext to /api/v1/secrets with owner_type=application.
	 * Authenticated NC users only; the certificate is non-secret but the
	 * existence/status leak is gated to active applications.
	 *
	 * @param string $id The application ID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-application-mgmt/tasks.md#task-9.4
	 *
	 * @no-admin-idor-exempt public-key distribution, same shape as
	 * ShareController::recipientCertificate(). Returns the application's
	 * certificate — the PUBLIC half of its EncryptionSuite — and no private
	 * material. A caller needs it to encrypt to that application, so it is
	 * meant to be readable by any authenticated user.
	 */
	#[NoAdminRequired]
	public function certificate(string $id): JSONResponse {
		$user = $this->session->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$certificate = $this->service->getCertificate(applicationId: $id);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(
				data: ['message' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		if ($certificate === null) {
			return new JSONResponse(
				data: ['message' => 'No active EncryptionSuite for this application'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		return new JSONResponse(data: ['id' => $id, 'certificate' => $certificate]);
	}//end certificate()
}//end class
