<?php

/**
 * Doriath Encryption Suite Controller
 *
 * API controller for EncryptionSuite CRUD operations.
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

use Exception;
use InvalidArgumentException;
use OCA\Doriath\AppInfo\Application;
use OCA\Doriath\Service\EncryptionSuiteService;
use OCA\Doriath\Service\LinkShareService;
use OCA\Doriath\Service\MigrationService;
use OCA\Doriath\Settings\AdminSettings;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;

/**
 * API controller for EncryptionSuite CRUD operations.
 */
class EncryptionSuiteController extends OCSController
{
    /**
     * Constructor for EncryptionSuiteController.
     *
     * @param IRequest                                 $request          The request object
     * @param EncryptionSuiteService                   $suiteService     The suite service
     * @param MigrationService                         $migrationService The migration service
     * @param LinkShareService                         $linkShareService The link share service (cascade on compromise recovery)
     * @param IUserSession                             $userSession      The user session
     * @param \OCA\Doriath\Service\PasskeyService|null $passkeyService   The passkey service (passkey vault login; null when unwired)
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private EncryptionSuiteService $suiteService,
        private MigrationService $migrationService,
        private LinkShareService $linkShareService,
        private IUserSession $userSession,
        private ?\OCA\Doriath\Service\PasskeyService $passkeyService=null,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List suites for the current user.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-2
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $userId = $user->getUID();
        $suites = $this->suiteService->getSuitesByOwner(ownerType: 'user', ownerId: $userId);

        return new JSONResponse(
            data: array_map(
                static fn ($suite) => $suite->jsonSerialize(),
                $suites
            )
        );
    }//end index()

    /**
     * Get a specific suite.
     *
     * @param string $id The suite ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-2
     */
    #[NoAdminRequired]
    public function show(string $id): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        try {
            $suite = $this->suiteService->getSuite($id);
            $this->validateOwnership(suite: $suite);
            return new JSONResponse(data: $suite->jsonSerialize());
        } catch (Exception $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }
    }//end show()

    /**
     * Create a new EncryptionSuite for the current user.
     *
     * @param string $publicKey           The PEM-encoded public key
     * @param string $encryptedPrivateKey The encrypted private key
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-2
     */
    #[NoAdminRequired]
    public function create(
        ?string $publicKey=null,
        ?string $encryptedPrivateKey=null,
    ): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        // Validate required params HERE so a missing body returns 400, not a 500
        // from the framework dispatcher failing to bind non-nullable arguments.
        if ($publicKey === null || $publicKey === '' || $encryptedPrivateKey === null || $encryptedPrivateKey === '') {
            return new JSONResponse(
                data: ['message' => 'Missing required parameters: publicKey and encryptedPrivateKey are required'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        $userId = $user->getUID();

        // Reject suite creation while a key-compromise migration is in progress —
        // the old suite must finish re-encrypting data before a new one is registered.
        if ($this->migrationService->isWriteLocked(ownerType: 'user', ownerId: $userId) === true) {
            return new JSONResponse(
                data: ['message' => 'Write locked: an active key migration is in progress'],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }

        try {
            $suite = $this->suiteService->createSuite(
                ownerType: 'user',
                ownerId: $userId,
                publicKeyPem: $publicKey,
                encryptedPrivateKey: $encryptedPrivateKey
            );
            return new JSONResponse(data: $suite->jsonSerialize(), statusCode: Http::STATUS_CREATED);
        } catch (InvalidArgumentException $e) {
            // Malformed key material (e.g. a non-PEM publicKey) is a client error,
            // not a server fault — surface it as a 400 instead of a 500.
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        } catch (RuntimeException $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_SERVICE_UNAVAILABLE
            );
        }//end try
    }//end create()

    /**
     * Update the encrypted private key (routine password change).
     *
     * @param string $id                  The suite ID
     * @param string $encryptedPrivateKey The new encrypted private key
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-2
     */
    #[NoAdminRequired]
    public function updatePrivateKey(string $id, string $encryptedPrivateKey): JSONResponse
    {
        try {
            $suite = $this->suiteService->getSuite($id);
            $this->validateOwnership(suite: $suite);

            $suite->setPrivateKey($encryptedPrivateKey);
            // A routine master-password change re-wraps the private key under a
            // new AES key, so every stored passkey unlock envelope now wraps a
            // dead key. Advance the epoch and mark those envelopes stale
            // (passkey-vault-login §D4).
            $suite->setUnlockKeyEpoch($suite->getUnlockKeyEpoch() + 1);
            $this->suiteService->updateSuite($suite);
            $this->passkeyService?->markStaleOnPasswordChange($suite->getOwnerId());

            return new JSONResponse(data: $suite->jsonSerialize());
        } catch (Exception $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }
    }//end updatePrivateKey()

    /**
     * Revoke an EncryptionSuite.
     *
     * @param string $id     The suite ID
     * @param string $reason The revocation reason
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-2
     */
    #[NoAdminRequired]
    public function revoke(string $id, string $reason): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $userId = $user->getUID();

        try {
            // AUTHORISATION, not authentication. The `$user === null` check
            // above only asks whether ANYONE is logged in; it does not ask
            // whether THIS user owns THIS suite. `revokeSuite()` records
            // `$revokedBy` as the audit actor and never compares it to the
            // suite's owner, so without the line below any authenticated user
            // could revoke any other user's suite by id — and revocation is
            // destructive: EncryptionSuiteRevokedListener hard-deletes every
            // ShareTarget where the owner is the recipient and promotes their
            // delegations to permanent. `show()` and `updatePrivateKey()`
            // already call this same helper; revoke() did not.
            $this->validateOwnership(suite: $this->suiteService->getSuite($id));

            $suite = $this->suiteService->revokeSuite(id: $id, reason: $reason, revokedBy: $userId);
            return new JSONResponse(data: $suite->jsonSerialize());
        } catch (RuntimeException $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_FORBIDDEN
            );
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }//end try
    }//end revoke()

    /**
     * Reinstate a revoked EncryptionSuite (admin only).
     *
     * @param string $id The suite ID
     *
     * @AuthorizedAdminSetting(AdminSettings::class)
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-2
     */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function reinstate(string $id): JSONResponse
    {
        $userId = $this->userSession->getUser()->getUID();

        try {
            $suite = $this->suiteService->reinstateSuite(id: $id, reinstatedBy: $userId);
            return new JSONResponse(data: $suite->jsonSerialize());
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }
    }//end reinstate()

    /**
     * Initiate compromise recovery: create new suite and migration record.
     *
     * @param string $publicKey           The PEM-encoded public key
     * @param string $encryptedPrivateKey The encrypted private key
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-2
     * @spec openspec/changes/implement-link-sharing/tasks.md#5.2
     */
    #[NoAdminRequired]
    public function compromiseRecovery(
        string $publicKey,
        string $encryptedPrivateKey,
    ): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $userId = $user->getUID();

        try {
            $oldSuite = $this->suiteService->getActiveSuite(ownerType: 'user', ownerId: $userId);

            // Mark the old suite as compromised immediately — it must not be
            // used for new encryption operations from this point on.
            $this->suiteService->markCompromised(id: $oldSuite->getId(), compromisedBy: $userId);

            // Cascade-revoke every link share created by this user: the
            // public-key fingerprint baked into each share's encrypted
            // snapshot now belongs to a compromised key pair. Any holder of
            // an outstanding link must be force-locked-out so a re-share
            // under the new suite is required. See the method docblock @spec
            // implement-link-sharing#5.2 for the cascade-revoke requirement.
            $this->linkShareService->deleteByUserId(userId: $userId);

            $newSuite  = $this->suiteService->createSuite(
                ownerType: 'user',
                ownerId: $userId,
                publicKeyPem: $publicKey,
                encryptedPrivateKey: $encryptedPrivateKey
            );
            $migration = $this->migrationService->initiateCompromiseRecovery(
                oldSuiteId: $oldSuite->getId(),
                newSuiteId: $newSuite->getId()
            );

            // A new key pair invalidates every passkey unlock envelope (the
            // wrapped unlock key can never open the new suite) — delete them
            // all (passkey-vault-login §D4).
            $this->passkeyService?->deleteAllOnRotation($userId);

            return new JSONResponse(
                data: [
                    'newSuite'               => $newSuite->jsonSerialize(),
                    'migration'              => $migration->jsonSerialize(),
                    'oldEncryptedPrivateKey' => $oldSuite->getPrivateKey(),
                ],
                statusCode: Http::STATUS_CREATED
            );
        } catch (Exception $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end compromiseRecovery()

    /**
     * Validate that the current user owns the suite (or is admin).
     *
     * @param mixed $suite The encryption suite entity
     *
     * @return void
     */
    private function validateOwnership($suite): void
    {
        $userId = $this->userSession->getUser()->getUID();
        if ($suite->getOwnerType() === 'user' && $suite->getOwnerId() !== $userId) {
            throw new RuntimeException('Access denied: suite belongs to another user');
        }
    }//end validateOwnership()
}//end class
