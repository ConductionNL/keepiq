<?php

/**
 * Keepiq Federated Inbound Controller
 *
 * "Incoming from other organisations" (sharing-federated-recipients D4): the
 * recipient lists the secrets partners shared with them and accepts or
 * declines each one.
 *
 * @category Controller
 * @package  OCA\Keepiq\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Controller;

use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Service\FederatedInboundService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;

/**
 * The recipient's inbound federated shares.
 *
 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#scenario-bob-accepts-a-shared-login
 */
class FederatedInboundController extends Controller {
	/**
	 * Constructor for FederatedInboundController.
	 *
	 * @param IRequest $request The request object
	 * @param FederatedInboundService $inbound The receiving side
	 * @param IUserSession $userSession The user session
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		IRequest $request,
		private FederatedInboundService $inbound,
		private IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The user's inbound shares, newest first.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#scenario-bob-accepts-a-shared-login
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$rows = $this->inbound->listFor(userId: $user->getUID());

		return new JSONResponse(data: array_map(static fn ($row) => $row->jsonSerialize(), $rows));
	}//end index()

	/**
	 * Accept a pending share: the server pulls the ciphertext and stores a
	 * read-only copy in the user's vault.
	 *
	 * @param string $id The inbound share
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#scenario-bob-accepts-a-shared-login
	 */
	#[NoAdminRequired]
	public function accept(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$row = $this->inbound->accept(id: $id, userId: $user->getUID());
		} catch (NotFoundException $exception) {
			return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: Http::STATUS_NOT_FOUND);
		} catch (RuntimeException $exception) {
			$status = Http::STATUS_BAD_GATEWAY;
			if ($exception->getMessage() === 'no_suite') {
				$status = Http::STATUS_CONFLICT;
			}

			return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: $status);
		}

		return new JSONResponse(data: $row->jsonSerialize());
	}//end accept()

	/**
	 * Decline a pending share.
	 *
	 * @param string $id The inbound share
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#scenario-bob-accepts-a-shared-login
	 */
	#[NoAdminRequired]
	public function decline(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$row = $this->inbound->decline(id: $id, userId: $user->getUID());
		} catch (NotFoundException $exception) {
			return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(data: $row->jsonSerialize());
	}//end decline()
}//end class
