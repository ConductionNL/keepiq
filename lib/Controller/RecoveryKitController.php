<?php

/**
 * Keepiq RecoveryKitController
 *
 * @category Controller
 * @package  OCA\Keepiq\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md
 */

declare(strict_types=1);

namespace OCA\Keepiq\Controller;

use InvalidArgumentException;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Kernel\AppIdentity;
use OCA\Keepiq\Kernel\Contracts\RecoveryKitServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The signed-in user's own recovery kit. Every route acts on the session
 * user only; none takes a user id, so no one reaches another user's copy,
 * an administrator included.
 *
 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#requirement-recovery-is-limited-to-the-owner-and-leaves-a-trail
 */
class RecoveryKitController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                    $request     The request.
	 * @param RecoveryKitServiceInterface $kits        The kits.
	 * @param IUserSession                $userSession The session.
	 *
	 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#requirement-recovery-is-limited-to-the-owner-and-leaves-a-trail
	 */
	public function __construct(
		IRequest $request,
		private RecoveryKitServiceInterface $kits,
		private IUserSession $userSession,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Whether the user has a kit, and when it was made; never the copy.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#requirement-a-user-can-create-a-recovery-kit-that-reopens-their-own-vault
	 */
	#[NoAdminRequired]
	public function show(): JSONResponse {
		return $this->run(action: fn (string $uid): array => $this->kits->status(userId: $uid));
	}//end show()

	/**
	 * Store a new kit, replacing the old one.
	 *
	 * @param string $wrappedKey The suite private key, wrapped in the browser.
	 * @param string $kdf        The derivation parameters the browser used.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#requirement-a-user-can-create-a-recovery-kit-that-reopens-their-own-vault
	 */
	#[NoAdminRequired]
	public function create(string $wrappedKey='', string $kdf=''): JSONResponse {
		return $this->run(
			action: fn (string $uid): array => $this->kits->create(userId: $uid, wrappedKey: $wrappedKey, kdf: $kdf),
			successStatus: Http::STATUS_CREATED
		);
	}//end create()

	/**
	 * The wrapped copy, to unwrap with the recovery code in the browser.
	 * Every attempt to recover fetches it, so the limit counts wrong codes.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#requirement-the-recovery-code-reopens-the-vault-and-sets-a-new-master-password
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 5, period: 3600)]
	public function fetch(): JSONResponse {
		return $this->run(action: fn (string $uid): array => $this->kits->fetch(userId: $uid));
	}//end fetch()

	/**
	 * Delete the spent copy after a recovery.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#requirement-the-recovery-code-reopens-the-vault-and-sets-a-new-master-password
	 */
	#[NoAdminRequired]
	public function spend(): JSONResponse {
		return $this->run(
			action: function (string $uid): array {
				$this->kits->spend(userId: $uid);
				return $this->kits->status(userId: $uid);
			}
		);
	}//end spend()

	/**
	 * Delete the kit without using it.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#requirement-recovery-is-limited-to-the-owner-and-leaves-a-trail
	 */
	#[NoAdminRequired]
	public function revoke(): JSONResponse {
		return $this->run(
			action: function (string $uid): array {
				$this->kits->revoke(userId: $uid);
				return $this->kits->status(userId: $uid);
			}
		);
	}//end revoke()

	/**
	 * Run an action as the session user and map its refusals.
	 *
	 * @param callable $action        The action, given the user id.
	 * @param int      $successStatus The status on success.
	 *
	 * @return JSONResponse
	 */
	private function run(callable $action, int $successStatus=Http::STATUS_OK): JSONResponse {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse(data: $action($uid), statusCode: $successStatus);
		} catch (NotFoundException) {
			return new JSONResponse(data: ['message' => 'Not found'], statusCode: Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}
	}//end run()
}//end class
