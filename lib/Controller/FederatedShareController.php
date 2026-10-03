<?php

/**
 * Keepiq Federated Share Controller
 *
 * The owner's routes for sharing a secret with a user of a partner instance
 * (sharing-federated-recipients D4): the owner's browser posts the
 * ciphertext it made for the recipient's verified certificate, and lists the
 * federated shares of a secret.
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

use InvalidArgumentException;
use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Attribute\VaultKeyProofRequired;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Service\FederatedShareService;
use OCA\Keepiq\Service\VaultKeyProofService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;

/**
 * Owner-side federated share routes.
 *
 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
 */
class FederatedShareController extends Controller {
	/**
	 * Constructor for FederatedShareController.
	 *
	 * @param IRequest $request The request object
	 * @param FederatedShareService $shares The outbound federated shares
	 * @param IUserSession $userSession The user session
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		IRequest $request,
		private FederatedShareService $shares,
		private IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Share one of the user's secrets with a user of an outbound partner.
	 *
	 * Every federated recipient is a new party, so the vault key proof of
	 * user-sharing ("Sharing with a new party requires a verified key proof")
	 * is always asked.
	 *
	 * @param string $secretId The owner's secret
	 * @param string $recipientCloudId The recipient's cloud id
	 * @param string $certFingerprint SHA-256 of the recipient certificate the browser verified
	 * @param string $key The value, encrypted for the recipient
	 * @param string|null $login The login, encrypted for the recipient
	 * @param string|null $additionalFields The additional fields, encrypted for the recipient
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
	 * @spec openspec/specs/user-sharing/spec.md#requirement-sharing-with-a-new-party-requires-a-verified-key-proof
	 */
	#[NoAdminRequired]
	#[VaultKeyProofRequired(binds: ['secretId', 'recipientCloudId'], purpose: VaultKeyProofService::PURPOSE_SHARE_NEW_RECIPIENT)]
	public function create(
		string $secretId,
		string $recipientCloudId = '',
		string $certFingerprint = '',
		string $key = '',
		?string $login = null,
		?string $additionalFields = null,
	): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$share = $this->shares->create(
				secretId: $secretId,
				userId: $user->getUID(),
				recipientCloudId: $recipientCloudId,
				certFingerprint: $certFingerprint,
				ciphertext: ['key' => $key, 'login' => $login, 'additionalFields' => $additionalFields],
			);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		} catch (RuntimeException $exception) {
			return $this->refusal(exception: $exception);
		}

		return new JSONResponse(data: $share->jsonSerialize(), statusCode: Http::STATUS_CREATED);
	}//end create()

	/**
	 * The federated shares of one of the user's own secrets.
	 *
	 * @param string $secretId The owner's secret
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-owner-updates-reach-the-remote-copy-and-revocation-removes-it
	 */
	#[NoAdminRequired]
	public function index(string $secretId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$rows = $this->shares->listForSecret(secretId: $secretId, userId: $user->getUID());
		} catch (NotFoundException $exception) {
			return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(data: array_map(static fn ($row) => $row->jsonSerialize(), $rows));
	}//end index()

	/**
	 * Map a service refusal to its status: not found, forbidden (a read-only
	 * copy), federation unavailable below Nextcloud 33, or a partner that
	 * did not take the share.
	 *
	 * @param RuntimeException $exception The refusal
	 *
	 * @return JSONResponse
	 */
	private function refusal(RuntimeException $exception): JSONResponse {
		$status = match (true) {
			$exception instanceof NotFoundException => Http::STATUS_NOT_FOUND,
			$exception instanceof ForbiddenException => Http::STATUS_FORBIDDEN,
			$exception->getMessage() === 'federation_unavailable' => Http::STATUS_CONFLICT,
			default => Http::STATUS_BAD_GATEWAY,
		};

		return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: $status);
	}//end refusal()
}//end class
