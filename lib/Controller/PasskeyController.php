<?php

/**
 * Doriath Passkey Controller
 *
 * Passkey vault-login endpoints (passkey-vault-login §2.4): list,
 * enrollment challenge, enroll, unlock options, record-use, and revoke.
 * All methods are `#[NoAdminRequired]` and owner-scoped — the lock
 * screen runs while the Nextcloud session is valid, only the VAULT is
 * locked (§D3). No `#[PublicPage]`, so no public rate-limit surface.
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
use OCA\Doriath\Db\PasskeyCredential;
use OCA\Doriath\Service\PasskeyService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Endpoints for passkey vault login.
 */
class PasskeyController extends OCSController
{
    /**
     * Constructor for PasskeyController.
     *
     * @param IRequest       $request     The request object
     * @param PasskeyService $service     The passkey service
     * @param IUserSession   $userSession The user session
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private PasskeyService $service,
        private IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * The calling user's id, or null.
     *
     * @return string|null
     */
    private function uid(): ?string
    {
        return $this->userSession->getUser()?->getUID();
    }//end uid()

    /**
     * 403 for unauthenticated callers.
     *
     * @return JSONResponse
     */
    private function unauth(): JSONResponse
    {
        return new JSONResponse(data: ['message' => 'Unauthenticated'], statusCode: Http::STATUS_FORBIDDEN);
    }//end unauth()

    /**
     * List the caller's enrolled passkeys (management view).
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/passkey-vault-login/specs/passkey-vault-login/spec.md#requirement-passkey-management
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
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
     * A fresh WebAuthn challenge for an enrollment ceremony.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function challenge(): JSONResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->unauth();
        }

        return new JSONResponse(data: ['challenge' => $this->service->freshChallenge()]);
    }//end challenge()

    /**
     * Enroll a passkey unlock envelope (client builds it while the vault
     * is unlocked).
     *
     * @param string $credentialId     base64url WebAuthn credential id
     * @param string $wrappedUnlockKey AES-GCM envelope of the vault unlock key
     * @param string $prfSalt          base64 PRF input salt
     * @param string $publicKey        COSE public key (optional)
     * @param string $label            User nickname
     * @param string $transports       Comma-joined transports
     * @param string $aaguid           Authenticator model id
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function create(
        string $credentialId='',
        string $wrappedUnlockKey='',
        string $prfSalt='',
        string $publicKey='',
        string $label='',
        string $transports='',
        string $aaguid='',
    ): JSONResponse {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->unauth();
        }

        try {
            $credential = $this->service->enroll(
                uid: $uid,
                dto: [
                    'credentialId'     => $credentialId,
                    'wrappedUnlockKey' => $wrappedUnlockKey,
                    'prfSalt'          => $prfSalt,
                    'publicKey'        => $publicKey,
                    'label'            => $label,
                    'transports'       => $transports,
                    'aaguid'           => $aaguid,
                ],
            );
        } catch (InvalidArgumentException $exception) {
            return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
        }

        return new JSONResponse(data: $credential->jsonSerialize(), statusCode: Http::STATUS_CREATED);
    }//end create()

    /**
     * The unlock options for the lock screen (active envelopes + salts +
     * a fresh challenge; stale/revoked refused).
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function loginOptions(): JSONResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->unauth();
        }

        return new JSONResponse(data: $this->service->loginOptions($uid));
    }//end loginOptions()

    /**
     * Record a successful passkey unlock (last-used stamp).
     *
     * @param string $id The credential UUID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function used(string $id): JSONResponse
    {
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
     */
    #[NoAdminRequired]
    public function destroy(string $id): JSONResponse
    {
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
