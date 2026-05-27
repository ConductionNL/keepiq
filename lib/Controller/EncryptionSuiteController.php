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
     * @param IRequest               $request          The request object
     * @param EncryptionSuiteService $suiteService     The suite service
     * @param MigrationService       $migrationService The migration service
     * @param IUserSession           $userSession      The user session
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private EncryptionSuiteService $suiteService,
        private MigrationService $migrationService,
        private IUserSession $userSession,
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
        string $publicKey,
        string $encryptedPrivateKey,
    ): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
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
        } catch (RuntimeException $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_SERVICE_UNAVAILABLE
            );
        }
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
            $this->suiteService->updateSuite($suite);
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
            $suite = $this->suiteService->revokeSuite(id: $id, reason: $reason, revokedBy: $userId);
            return new JSONResponse(data: $suite->jsonSerialize());
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }
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
