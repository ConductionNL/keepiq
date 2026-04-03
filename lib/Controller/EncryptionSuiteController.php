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
use OCP\AppFramework\Http;
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
     */
    public function index(): JSONResponse
    {
        $userId = $this->userSession->getUser()->getUID();
        $suites = $this->suiteService->getSuitesByOwner(ownerType: 'user', ownerId: $userId);

        return new JSONResponse(
            data: array_map(
                static fn ($suite) => $suite->jsonSerialize(),
                $suites
            )
        );
    }//end index()

    /**
     * Get the public key of another user's active encryption suite.
     * Public keys are not secret — this is needed for sharing (encrypting
     * a secret for a recipient).
     *
     * @param string $userId The target user ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    public function publicKey(string $userId): JSONResponse
    {
        try {
            $suite      = $this->suiteService->getActiveSuite(ownerType: 'user', ownerId: $userId);
            $serialized = $suite->jsonSerialize();

            // Only return the public key and certificate — not the encrypted private key.
            return new JSONResponse(
                data: [
                    'id'        => $serialized['id'],
                    'publicKey' => $serialized['publicKey'] ?? null,
                    'ownerId'   => $userId,
                ]
            );
        } catch (Exception $e) {
            return new JSONResponse(
                data: ['message' => 'No active encryption suite found for user: '.$userId],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }
    }//end publicKey()

    /**
     * Get a specific suite.
     *
     * @param string $id The suite ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    public function show(string $id): JSONResponse
    {
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
     * Create a new EncryptionSuite for the current user (Phase 1).
     *
     * Returns the suite, a passphrase-encrypted private key, the temporary
     * passphrase, and the public key PEM. The browser decrypts the private key
     * with the passphrase, re-encrypts with the master password, and sends
     * the final blob back via updatePrivateKey (Phase 2).
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    public function create(): JSONResponse
    {
        $userId = $this->userSession->getUser()->getUID();

        try {
            $result = $this->suiteService->createSuite(
                ownerType: 'user',
                ownerId: $userId
            );
            return new JSONResponse(
                data: [
                    'suite'               => $result['suite']->jsonSerialize(),
                    'encryptedPrivateKey' => $result['encryptedPrivateKey'],
                    'passphrase'          => $result['passphrase'],
                    'publicKeyPem'        => $result['publicKeyPem'],
                ],
                statusCode: Http::STATUS_CREATED
            );
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
     */
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
     */
    public function revoke(string $id, string $reason): JSONResponse
    {
        $userId = $this->userSession->getUser()->getUID();

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
     * @return JSONResponse
     */
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
     * Uses the same two-phase key generation as create(). The server generates
     * a new key pair, signs the certificate, and returns the private key
     * encrypted with a temporary passphrase. The browser decrypts, re-encrypts
     * with the new master password, and sends the blob back via updatePrivateKey.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    public function compromiseRecovery(): JSONResponse
    {
        $userId = $this->userSession->getUser()->getUID();

        try {
            $oldSuite = $this->suiteService->getActiveSuite(ownerType: 'user', ownerId: $userId);

            // Mark the old suite as compromised immediately.
            $this->suiteService->markCompromised(id: $oldSuite->getId(), compromisedBy: $userId);

            $result    = $this->suiteService->createSuite(
                ownerType: 'user',
                ownerId: $userId
            );
            $migration = $this->migrationService->initiateCompromiseRecovery(
                oldSuiteId: $oldSuite->getId(),
                newSuiteId: $result['suite']->getId()
            );

            return new JSONResponse(
                data: [
                    'newSuite'               => $result['suite']->jsonSerialize(),
                    'encryptedPrivateKey'    => $result['encryptedPrivateKey'],
                    'passphrase'             => $result['passphrase'],
                    'publicKeyPem'           => $result['publicKeyPem'],
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
     * Repair a suite that has no private key (Phase 1 — generate new key pair).
     * Returns encrypted PK + passphrase + nonce for identity verification.
     *
     * @param string $id The suite ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    public function repair(string $id): JSONResponse
    {
        try {
            $result = $this->suiteService->repairSuite(suiteId: $id);
            return new JSONResponse(
                data: [
                    'suite'               => $result['suite']->jsonSerialize(),
                    'encryptedPrivateKey' => $result['encryptedPrivateKey'],
                    'passphrase'          => $result['passphrase'],
                    'publicKeyPem'        => $result['publicKeyPem'],
                    'nonce'               => $result['nonce'],
                ]
            );
        } catch (Exception $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }
    }//end repair()

    /**
     * Confirm suite repair by verifying a nonce signed with the old private key.
     *
     * @param string $id                  The new suite ID
     * @param string $oldSuiteId          The old compromised suite ID
     * @param string $nonce               The nonce that was signed
     * @param string $signature           Base64-encoded signature
     * @param string $encryptedPrivateKey The master-password-encrypted private key
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    public function confirmRepair(
        string $id,
        string $oldSuiteId='',
        string $nonce='',
        string $signature='',
        string $encryptedPrivateKey='',
    ): JSONResponse {
        try {
            $suite = $this->suiteService->confirmRepair(
                suiteId: $id,
                oldSuiteId: $oldSuiteId,
                nonce: $nonce,
                signature: $signature,
                encryptedPrivateKey: $encryptedPrivateKey
            );
            return new JSONResponse(data: $suite->jsonSerialize());
        } catch (Exception $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }
    }//end confirmRepair()

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
