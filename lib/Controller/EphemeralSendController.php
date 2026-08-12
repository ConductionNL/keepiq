<?php

/**
 * Doriath Ephemeral Send Controller
 *
 * Session-authenticated owner surface (ephemeral-send §4.1): create a
 * send from client-encrypted material, list my sends, revoke. Owner
 * scoping lives in the service bodies (no cross-user reads).
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
use OCA\Doriath\Db\EphemeralSend;
use OCA\Doriath\Service\EphemeralSendService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Owner endpoints for ephemeral sends.
 */
class EphemeralSendController extends OCSController {
	/**
	 * Constructor for EphemeralSendController.
	 *
	 * @param IRequest $request The request object
	 * @param EphemeralSendService $service The send service
	 * @param IUserSession $userSession The user session
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private EphemeralSendService $service,
		private IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Create a send from client-encrypted material.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/ephemeral-send/spec.md#requirement-create-a-standalone-ephemeral-send
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$send = $this->service->create(ownerId: $user->getUID(), params: $this->request->getParams());
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(
				data: ['message' => $exception->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(data: $send->jsonSerialize(), statusCode: Http::STATUS_CREATED);
	}//end create()

	/**
	 * List the caller's sends.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(
			data: array_map(
				static fn (EphemeralSend $send) => $send->jsonSerialize(),
				$this->service->listForOwner(ownerId: $user->getUID())
			)
		);
	}//end index()

	/**
	 * Revoke one of the caller's sends.
	 *
	 * @param string $id The send UUID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function destroy(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->service->revoke(id: $id, ownerId: $user->getUID());
		} catch (DoesNotExistException) {
			return new JSONResponse(data: ['message' => 'Send not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(data: ['revoked' => true]);
	}//end destroy()
}//end class
