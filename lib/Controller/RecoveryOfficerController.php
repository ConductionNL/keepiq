<?php

/**
 * Keepiq RecoveryOfficerController
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
use OCA\Keepiq\Service\RecoveryKeyService;
use OCA\Keepiq\Service\RecoveryPolicyService;
use OCA\Keepiq\Service\RecoveryRequestService;
use OCA\Keepiq\Service\VaultKeyProofService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The recovery officer's routes (crypto-organisation-account-recovery 2.1,
 * 4.2, 4.3). Every route checks in the body that the caller is a named
 * officer; handoff material answers anyone it is not for like an unknown id.
 */
class RecoveryOfficerController extends Controller {

	/**
	 * Constructor for RecoveryOfficerController.
	 *
	 * @param IRequest               $request     The request
	 * @param RecoveryPolicyService  $policy      The officers
	 * @param RecoveryKeyService     $keys        The recovery keys
	 * @param RecoveryRequestService $requests    The requests
	 * @param IUserSession           $userSession The user session
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		IRequest $request,
		private RecoveryPolicyService $policy,
		private RecoveryKeyService $keys,
		private RecoveryRequestService $requests,
		private IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The caller's officer view: whether they are an officer, the officers
	 * and their certificates (to wrap a new key for), the active key, and
	 * the open requests.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-the-recovery-private-key-is-generated-and-held-by-officers-only
	 */
	#[NoAdminRequired]
	public function overview(): JSONResponse {
		$uid = $this->uid();
		if ($uid === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		if ($this->policy->isOfficer(userId: $uid) === false) {
			return new JSONResponse(data: ['officer' => false]);
		}

		return new JSONResponse(
			data: [
				'officer' => true,
				'officers' => $this->policy->officers(),
				'threshold' => $this->policy->threshold(),
				'key' => $this->keys->publicInfo(),
				'requests' => $this->requests->forOfficer(officerUid: $uid),
			]
		);
	}//end overview()

	/**
	 * Store a new recovery key: its public half and one wrapped copy per officer.
	 *
	 * @param string               $publicKey The recovery public key PEM
	 * @param array<string,string> $copies    Officer uid => wrapped private key
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-the-recovery-private-key-is-generated-and-held-by-officers-only
	 */
	#[NoAdminRequired]
	public function createKey(string $publicKey = '', array $copies = []): JSONResponse {
		$uid = $this->uid();
		if ($uid === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$key = $this->keys->createKey(officerUid: $uid, publicKeyPem: $publicKey, copies: $copies);
		} catch (ForbiddenException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: $key->jsonSerialize(), statusCode: Http::STATUS_CREATED);
	}//end createKey()

	/**
	 * The caller's own wrapped copy of the active key (or of `keyId`).
	 *
	 * @param string $keyId The key, or '' for the active one
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-the-recovery-private-key-is-generated-and-held-by-officers-only
	 */
	#[NoAdminRequired]
	public function ownCopy(string $keyId = ''): JSONResponse {
		$uid = $this->uid();
		if ($uid === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$recoveryKeyId = null;
		if ($keyId !== '') {
			$recoveryKeyId = $keyId;
		}

		try {
			$copy = $this->keys->ownCopy(officerUid: $uid, recoveryKeyId: $recoveryKeyId);
		} catch (NotFoundException) {
			return new JSONResponse(data: ['message' => 'Not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(
			data: ['recoveryKeyId' => $copy->getRecoveryKeyId(), 'wrappedPrivateKey' => $copy->getWrappedPrivateKey()]
		);
	}//end ownCopy()

	/**
	 * Replace the caller's own copy after their suite rotated.
	 *
	 * @param string $keyId             The key
	 * @param string $wrappedPrivateKey The copy wrapped to the new suite
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-enrolments-and-officer-copies-follow-the-suite
	 */
	#[NoAdminRequired]
	public function replaceOwnCopy(string $keyId, string $wrappedPrivateKey = ''): JSONResponse {
		$uid = $this->uid();
		if ($uid === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->keys->replaceOwnCopy(officerUid: $uid, recoveryKeyId: $keyId, wrapped: $wrappedPrivateKey);
		} catch (NotFoundException) {
			return new JSONResponse(data: ['message' => 'Not found'], statusCode: Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: ['status' => 'replaced']);
	}//end replaceOwnCopy()

	/**
	 * Approve a request. Needs a vault-key proof from the officer's active
	 * suite, so a session alone cannot approve.
	 *
	 * @param string $id The request
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-recovery-needs-a-threshold-of-proven-officer-approvals
	 */
	#[NoAdminRequired]
	#[VaultKeyProofRequired(binds: ['id'], subject: 'active', purpose: VaultKeyProofService::PURPOSE_APPROVE_ACCOUNT_RECOVERY)]
	public function approve(string $id): JSONResponse {
		return $this->run(action: fn (string $uid): array => ['status' => $this->requests->approve(id: $id, officerUid: $uid)]);
	}//end approve()

	/**
	 * Decline a request.
	 *
	 * @param string $id The request
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-recovery-needs-a-threshold-of-proven-officer-approvals
	 */
	#[NoAdminRequired]
	public function decline(string $id): JSONResponse {
		return $this->run(
			action: function (string $uid) use ($id): array {
				$this->requests->decline(id: $id, officerUid: $uid);
				return ['status' => 'declined'];
			}
		);
	}//end decline()

	/**
	 * The handoff material, for an approving officer of an approved request.
	 *
	 * @param string $id The request
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-the-recovered-key-reaches-only-the-requesting-browser
	 */
	#[NoAdminRequired]
	public function handoff(string $id): JSONResponse {
		return $this->run(action: fn (string $uid): array => $this->requests->handoff(id: $id, officerUid: $uid));
	}//end handoff()

	/**
	 * Post the private key sealed to the request key.
	 *
	 * @param string $id           The request
	 * @param string $sealedResult The sealed private key
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-the-recovered-key-reaches-only-the-requesting-browser
	 */
	#[NoAdminRequired]
	public function postSealed(string $id, string $sealedResult = ''): JSONResponse {
		return $this->run(
			action: function (string $uid) use ($id, $sealedResult): array {
				$this->requests->postSealed(id: $id, officerUid: $uid, sealed: $sealedResult);
				return ['status' => 'handed-off'];
			}
		);
	}//end postSealed()

	/**
	 * Run an officer action and map refusals: not an officer is 403,
	 * anything it is not for is 404, a malformed body is 400.
	 *
	 * @param callable $action Receives the caller's uid
	 *
	 * @return JSONResponse
	 */
	private function run(callable $action): JSONResponse {
		$uid = $this->uid();
		if ($uid === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse(data: $action($uid));
		} catch (ForbiddenException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		} catch (NotFoundException) {
			return new JSONResponse(data: ['message' => 'Not found'], statusCode: Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}
	}//end run()

	/**
	 * The caller's uid.
	 *
	 * @return string|null
	 */
	private function uid(): ?string {
		return $this->userSession->getUser()?->getUID();
	}//end uid()
}//end class
