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
        $suites = $this->suiteService->getSuitesByOwner('user', $userId);

        return new JSONResponse(
                array_map(
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
     */
    public function show(string $id): JSONResponse
    {
        try {
            $suite = $this->suiteService->getSuite($id);
            $this->validateOwnership(suite: $suite);
            return new JSONResponse($suite->jsonSerialize());
        } catch (Exception $e) {
            return new JSONResponse(
                ['message' => $e->getMessage()],
                Http::STATUS_NOT_FOUND
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
     */
    public function create(
        string $publicKey,
        string $encryptedPrivateKey,
    ): JSONResponse {
        $userId = $this->userSession->getUser()->getUID();

        try {
            $suite = $this->suiteService->createSuite(
                'user',
                $userId,
                $publicKey,
                $encryptedPrivateKey
            );
            return new JSONResponse($suite->jsonSerialize(), Http::STATUS_CREATED);
        } catch (RuntimeException $e) {
            return new JSONResponse(
                ['message' => $e->getMessage()],
                Http::STATUS_SERVICE_UNAVAILABLE
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
            return new JSONResponse($suite->jsonSerialize());
        } catch (Exception $e) {
            return new JSONResponse(
                ['message' => $e->getMessage()],
                Http::STATUS_FORBIDDEN
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
            $suite = $this->suiteService->revokeSuite($id, $reason, $userId);
            return new JSONResponse($suite->jsonSerialize());
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                ['message' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST
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
            $suite = $this->suiteService->reinstateSuite($id, $userId);
            return new JSONResponse($suite->jsonSerialize());
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                ['message' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST
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
     */
    public function compromiseRecovery(
        string $publicKey,
        string $encryptedPrivateKey,
    ): JSONResponse {
        $userId = $this->userSession->getUser()->getUID();

        try {
            $oldSuite  = $this->suiteService->getActiveSuite('user', $userId);
            $newSuite  = $this->suiteService->createSuite('user', $userId, $publicKey, $encryptedPrivateKey);
            $migration = $this->migrationService->initiateCompromiseRecovery(
                $oldSuite->getId(),
                $newSuite->getId()
            );

            return new JSONResponse(
                    [
                        'newSuite'               => $newSuite->jsonSerialize(),
                        'migration'              => $migration->jsonSerialize(),
                        'oldEncryptedPrivateKey' => $oldSuite->getPrivateKey(),
                    ],
                    Http::STATUS_CREATED
                    );
        } catch (Exception $e) {
            return new JSONResponse(
                ['message' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
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
