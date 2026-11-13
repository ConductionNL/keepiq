<?php

/**
 * Keepiq Passkey Controller
 *
 * Passkey vault-login endpoints (passkey-vault-login §2.4): list,
 * enrollment challenge, enroll, unlock options, unlock, record-use, and
 * revoke.
 * All methods are `#[NoAdminRequired]` and owner-scoped — the lock
 * screen runs while the Nextcloud session is valid, only the VAULT is
 * locked (§D3). No `#[PublicPage]`, so no public rate-limit surface.
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
use OCA\Keepiq\Db\PasskeyCredential;
use OCA\Keepiq\Kernel\AppIdentity;
use OCA\Keepiq\Service\PasskeyService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;

/**
 * Endpoints for passkey vault login.
 */
class PasskeyController extends OCSController {
	/**
	 * The throttler action of a passkey enrolment.
	 *
	 * @var string
	 */
	public const ENROL_ACTION = 'keepiq_passkey_enrol';

	/**
	 * The throttler action of a passkey unlock.
	 *
	 * @var string
	 */
	public const UNLOCK_ACTION = 'keepiq_passkey_unlock';

	/**
	 * Constructor for PasskeyController.
	 *
	 * @param IRequest $request The request object
	 * @param PasskeyService $service The passkey service
	 * @param IUserSession $userSession The user session
	 * @param IThrottler $throttler Nextcloud's brute-force throttler
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private PasskeyService $service,
		private IUserSession $userSession,
		private IThrottler $throttler,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The calling user's id, or null.
	 *
	 * @return string|null
	 */
	private function uid(): ?string {
		return $this->userSession->getUser()?->getUID();
	}//end uid()

	/**
	 * 403 for unauthenticated callers.
	 *
	 * @return JSONResponse
	 */
	private function unauth(): JSONResponse {
		return new JSONResponse(data: ['message' => 'Unauthenticated'], statusCode: Http::STATUS_FORBIDDEN);
	}//end unauth()

	/**
	 * List the caller's enrolled passkeys (management view).
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passkeys-are-manageable-revocable-and-owner-scoped
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$uid = $this->uid();
		if ($uid === null) {
			return $this->unauth();
		}

		return new JSONResponse(
			data: array_map(
				static fn (PasskeyCredential $c) => $c->jsonSerialize(),
				$this->service->listForOwner($uid)
			)
		);
	}//end index()

	/**
	 * The challenge pair for an enrollment ceremony: `challenge` for
	 * create(), `assertionChallenge` for the proof get(). The web app's pair
	 * is held in the session; the extension's is held server-side, bound to
	 * its app password, and comes with the relying party and user handle.
	 *
	 * @param string $client web (default) or extension
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passkey-enrollment-requires-an-unlocked-vault
	 */
	#[NoAdminRequired]
	#[UseSession]
	#[BruteForceProtection(action: self::ENROL_ACTION)]
	public function challenge(string $client='web'): JSONResponse {
		$uid = $this->uid();
		if ($uid === null) {
			return $this->unauth();
		}

		try {
			return new JSONResponse(data: $this->service->enrolmentChallenge(uid: $uid, client: $client));
		} catch (InvalidArgumentException $exception) {
			return $this->refused(message: $exception->getMessage(), action: self::ENROL_ACTION);
		}
	}//end challenge()

	/**
	 * Enroll a passkey unlock envelope (client builds it while the vault
	 * is unlocked). The create() attestation and the proof get() assertion
	 * are verified against the session's challenge pair; the public key and
	 * authenticator model are read from them.
	 *
	 * @param string $credentialId base64url WebAuthn credential id
	 * @param string $wrappedUnlockKey AES-GCM envelope of the vault unlock key
	 * @param string $prfSalt base64 PRF input salt
	 * @param array<string,mixed> $attestation The create() PublicKeyCredential as JSON
	 * @param array<string,mixed> $assertion The proof get() PublicKeyCredential as JSON
	 * @param string $label User nickname
	 * @param string $transports Comma-joined transports
	 * @param string $clientKind web (default) or extension
	 * @param string $rpId The relying party id of a web credential (an extension credential's is the server host)
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passkey-enrollment-requires-an-unlocked-vault
	 */
	#[NoAdminRequired]
	#[UseSession]
	#[BruteForceProtection(action: self::ENROL_ACTION)]
	public function create(
		string $credentialId = '',
		string $wrappedUnlockKey = '',
		string $prfSalt = '',
		array $attestation = [],
		array $assertion = [],
		string $label = '',
		string $transports = '',
		string $clientKind = 'web',
		string $rpId = '',
	): JSONResponse {
		$uid = $this->uid();
		if ($uid === null) {
			return $this->unauth();
		}

		try {
			$credential = $this->service->enroll(
				uid: $uid,
				dto: [
					'credentialId' => $credentialId,
					'wrappedUnlockKey' => $wrappedUnlockKey,
					'prfSalt' => $prfSalt,
					'attestation' => $attestation,
					'assertion' => $assertion,
					'label' => $label,
					'transports' => $transports,
					'clientKind' => $clientKind,
					'rpId' => $rpId,
				],
			);
		} catch (InvalidArgumentException $exception) {
			return $this->refused(message: $exception->getMessage(), action: self::ENROL_ACTION);
		}

		return new JSONResponse(data: $credential->jsonSerialize(), statusCode: Http::STATUS_CREATED);
	}//end create()

	/**
	 * The unlock options for the lock screen (active credential ids + salts
	 * + a fresh challenge stored in the session; stale/revoked refused),
	 * scoped to the asking client. No wrapped envelope.
	 *
	 * @param string $client web (default) or extension
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passwordless-unlock-derives-the-unlock-key-client-side
	 */
	#[NoAdminRequired]
	#[UseSession]
	public function loginOptions(string $client='web'): JSONResponse {
		$uid = $this->uid();
		if ($uid === null) {
			return $this->unauth();
		}

		return new JSONResponse(data: $this->service->loginOptions(uid: $uid, client: $client));
	}//end loginOptions()

	/**
	 * Verify an unlock assertion against the session's unlock challenge and
	 * return the answering credential's wrapped envelope. Any failure is one
	 * generic 400 without an envelope.
	 *
	 * @param array<string,mixed> $assertion The get() PublicKeyCredential as JSON
	 * @param string $client web (default) or extension
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passwordless-unlock-derives-the-unlock-key-client-side
	 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-passkey-trust/spec.md#requirement-passkey-ceremonies-are-throttled-with-nextclouds-throttler
	 */
	#[NoAdminRequired]
	#[UseSession]
	#[BruteForceProtection(action: self::UNLOCK_ACTION)]
	public function unlock(array $assertion=[], string $client='web'): JSONResponse {
		$uid = $this->uid();
		if ($uid === null) {
			return $this->unauth();
		}

		try {
			$envelope = $this->service->unlock(uid: $uid, assertion: $assertion, client: $client);
		} catch (InvalidArgumentException $exception) {
			return $this->refused(message: $exception->getMessage(), action: self::UNLOCK_ACTION);
		}

		$this->throttler->resetDelay($this->request->getRemoteAddress(), self::UNLOCK_ACTION, []);

		return new JSONResponse(data: $envelope);
	}//end unlock()

	/**
	 * A 400 refusal that counts as a throttled attempt for its action.
	 *
	 * @param string $message The generic refusal message
	 * @param string $action The throttler action
	 *
	 * @return JSONResponse
	 */
	private function refused(string $message, string $action): JSONResponse {
		$response = new JSONResponse(data: ['message' => $message], statusCode: Http::STATUS_BAD_REQUEST);
		$response->throttle(['action' => $action]);

		return $response;
	}//end refused()

	/**
	 * Record a successful passkey unlock (last-used stamp).
	 *
	 * @param string $id The credential UUID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passkeys-are-manageable-revocable-and-owner-scoped
	 */
	#[NoAdminRequired]
	public function used(string $id): JSONResponse {
		$uid = $this->uid();
		if ($uid === null) {
			return $this->unauth();
		}

		$this->service->recordUse(uid: $uid, id: $id);

		return new JSONResponse(data: ['recorded' => true]);
	}//end used()

	/**
	 * Revoke (delete) one passkey.
	 *
	 * @param string $id The credential UUID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passkeys-are-manageable-revocable-and-owner-scoped
	 */
	#[NoAdminRequired]
	public function destroy(string $id): JSONResponse {
		$uid = $this->uid();
		if ($uid === null) {
			return $this->unauth();
		}

		try {
			$this->service->revoke(uid: $uid, id: $id);
		} catch (DoesNotExistException) {
			return new JSONResponse(data: ['message' => 'Passkey not found'], statusCode: Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(data: ['revoked' => true]);
	}//end destroy()
}//end class
