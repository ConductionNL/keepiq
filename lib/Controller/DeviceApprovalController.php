<?php

/**
 * Keepiq DeviceApprovalController
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
use OCA\Keepiq\Service\DeviceApprovalService;
use OCA\Keepiq\Service\VaultKeyProofService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * New device approval (crypto-new-device-approval). Every route acts on the
 * caller's own requests only; another user's request answers like an unknown id.
 */
class DeviceApprovalController extends Controller {

	/**
	 * Constructor for DeviceApprovalController.
	 *
	 * @param IRequest              $request     The request
	 * @param DeviceApprovalService $approvals   The approval service
	 * @param IUserSession          $userSession The user session
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		IRequest $request,
		private DeviceApprovalService $approvals,
		private IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Whether device approval is on, so the lock screen knows to offer it.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/new-device-approval/spec.md#requirement-deny-expiry-audit-and-administrator-switch
	 */
	#[NoAdminRequired]
	public function status(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(data: ['enabled' => $this->approvals->isEnabled()]);
	}//end status()

	/**
	 * A locked device asks to be approved. At most three per user per hour.
	 *
	 * @param string $publicKey   The one-time X25519 public key (base64)
	 * @param string $clientKind  `web` or `extension`
	 * @param string $deviceLabel The device label
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/new-device-approval/spec.md#requirement-a-new-device-requests-approval-with-a-one-time-key
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 3, period: 3600)]
	public function create(string $publicKey = '', string $clientKind = 'web', string $deviceLabel = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$created = $this->approvals->create(
				userId: $user->getUID(),
				publicKey: $publicKey,
				clientKind: $clientKind,
				label: $deviceLabel,
				address: $this->request->getRemoteAddress(),
				agent: (string)$this->request->getHeader('User-Agent'),
			);
		} catch (ForbiddenException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: $created, statusCode: Http::STATUS_CREATED);
	}//end create()

	/**
	 * The caller's open requests, for the approval dialog.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/new-device-approval/spec.md#requirement-both-devices-show-the-same-verification-phrase
	 */
	#[NoAdminRequired]
	public function pending(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(
			data: array_map(static fn ($row) => $row->jsonSerialize(), $this->approvals->pending(userId: $user->getUID()))
		);
	}//end pending()

	/**
	 * Deny one of the caller's open requests.
	 *
	 * @param string $id The request
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/new-device-approval/spec.md#requirement-deny-expiry-audit-and-administrator-switch
	 */
	#[NoAdminRequired]
	public function deny(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->approvals->deny(id: $id, userId: $user->getUID());
		} catch (NotFoundException) {
			return new JSONResponse(data: ['message' => 'Not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(data: ['status' => 'denied']);
	}//end deny()

	/**
	 * Approve one of the caller's open requests with the sealed unlock key.
	 * Needs a vault-key proof from the caller's active suite, bound to the
	 * request id and the sealed key, so an unlocked tab cannot approve alone.
	 *
	 * @param string $id              The request
	 * @param string $sealedUnlockKey The unlock key sealed to the request key
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/new-device-approval/spec.md#requirement-approval-seals-the-unlock-key-and-needs-proof-of-the-master-password
	 */
	#[NoAdminRequired]
	#[VaultKeyProofRequired(binds: ['id', 'sealedUnlockKey'], subject: 'active', purpose: VaultKeyProofService::PURPOSE_APPROVE_DEVICE)]
	public function approve(string $id, string $sealedUnlockKey = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->approvals->approve(id: $id, userId: $user->getUID(), sealedUnlockKey: $sealedUnlockKey);
		} catch (NotFoundException) {
			return new JSONResponse(data: ['message' => 'Not found'], statusCode: Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: ['status' => 'approved']);
	}//end approve()

	/**
	 * The requesting device's poll, with its request secret in the
	 * `X-Keepiq-Request-Secret` header (kept out of URLs and access logs).
	 * Carries the sealed key once.
	 *
	 * @param string $id The request
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/new-device-approval/spec.md#requirement-pickup-is-one-time-and-unlocks-one-session
	 */
	#[NoAdminRequired]
	public function show(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse(
				data: $this->approvals->pickup(
					id: $id,
					userId: $user->getUID(),
					requestSecret: (string)$this->request->getHeader('X-Keepiq-Request-Secret')
				)
			);
		} catch (NotFoundException) {
			return new JSONResponse(data: ['message' => 'Not found'], statusCode: Http::STATUS_NOT_FOUND);
		}
	}//end show()
}//end class
