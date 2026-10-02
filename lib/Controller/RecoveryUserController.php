<?php

/**
 * Keepiq RecoveryUserController
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
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Service\RecoveryEnrolmentService;
use OCA\Keepiq\Service\RecoveryRequestService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * A user's own enrolment and recovery request (crypto-organisation-account-
 * recovery 3.1, 4.1, 4.5). Every route acts on the caller's own records only.
 */
class RecoveryUserController extends Controller {

	/**
	 * Constructor for RecoveryUserController.
	 *
	 * @param IRequest                 $request     The request
	 * @param RecoveryEnrolmentService $enrolments  The enrolment service
	 * @param RecoveryRequestService   $requests    The request service
	 * @param IUserSession             $userSession The user session
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		IRequest $request,
		private RecoveryEnrolmentService $enrolments,
		private RecoveryRequestService $requests,
		private IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The caller's enrolment status and the active key to enrol with.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-users-enrol-by-wrapping-their-own-key-to-the-recovery-certificate
	 */
	#[NoAdminRequired]
	public function enrolment(): JSONResponse {
		return $this->run(action: fn (string $uid): array => $this->enrolments->status(userId: $uid));
	}//end enrolment()

	/**
	 * Enrol with an envelope built in the caller's browser.
	 *
	 * @param string $recoveryKeyId The active key
	 * @param string $envelope      The hybrid envelope of the caller's private key
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-users-enrol-by-wrapping-their-own-key-to-the-recovery-certificate
	 */
	#[NoAdminRequired]
	public function enrol(string $recoveryKeyId = '', string $envelope = ''): JSONResponse {
		return $this->run(
			action: function (string $uid) use ($recoveryKeyId, $envelope): array {
				$this->enrolments->enrol(userId: $uid, recoveryKeyId: $recoveryKeyId, envelope: $envelope);
				return $this->enrolments->status(userId: $uid);
			}
		);
	}//end enrol()

	/**
	 * Withdraw, refused under the required policy.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-users-enrol-by-wrapping-their-own-key-to-the-recovery-certificate
	 */
	#[NoAdminRequired]
	public function withdraw(): JSONResponse {
		return $this->run(
			action: function (string $uid): array {
				$this->enrolments->withdraw(userId: $uid);
				return $this->enrolments->status(userId: $uid);
			}
		);
	}//end withdraw()

	/**
	 * File a request from the lock screen with the browser's one-time key.
	 *
	 * @param string $publicKey The one-time X25519 public key (base64)
	 * @param string $purpose   `password` (forgot it) or `device` (unlock a new device once)
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-a-recovery-request-carries-a-one-time-key-and-a-verification-phrase
	 */
	#[NoAdminRequired]
	public function createRequest(string $publicKey = '', string $purpose = 'password'): JSONResponse {
		return $this->run(
			action: fn (string $uid): array => $this->requests->create(
				userId: $uid,
				publicKey: $publicKey,
				purpose: $purpose
			)->jsonSerialize(),
			successStatus: Http::STATUS_CREATED
		);
	}//end createRequest()

	/**
	 * The caller's latest request, with the sealed result once it exists.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-the-recovered-key-reaches-only-the-requesting-browser
	 */
	#[NoAdminRequired]
	public function myRequest(): JSONResponse {
		return $this->run(action: fn (string $uid): array => ['request' => $this->requests->forUser(userId: $uid)]);
	}//end myRequest()

	/**
	 * Mark the caller's request fulfilled, after the suite was re-wrapped.
	 *
	 * @param string $id The request
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-the-user-is-told-what-happened-and-offered-a-rotation
	 */
	#[NoAdminRequired]
	public function complete(string $id): JSONResponse {
		return $this->run(action: fn (string $uid): array => ['handledBy' => $this->requests->complete(id: $id, userId: $uid)]);
	}//end complete()

	/**
	 * Run a user action and map refusals.
	 *
	 * @param callable $action        Receives the caller's uid
	 * @param int      $successStatus The status of a success
	 *
	 * @return JSONResponse
	 */
	private function run(callable $action, int $successStatus = Http::STATUS_OK): JSONResponse {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse(data: $action($uid), statusCode: $successStatus);
		} catch (ForbiddenException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		} catch (NotFoundException) {
			return new JSONResponse(data: ['message' => 'Not found'], statusCode: Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}
	}//end run()
}//end class
