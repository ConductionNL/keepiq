<?php

declare(strict_types=1);

namespace OCA\Doriath\Controller;

use OCA\Doriath\AppInfo\Application;
use OCA\Doriath\Service\EncryptionSuiteService;
use OCA\Doriath\Service\MigrationService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * API controller for EncryptionSuite CRUD operations.
 */
class EncryptionSuiteController extends OCSController
{
    public function __construct(
        IRequest $request,
        private EncryptionSuiteService $suiteService,
        private MigrationService $migrationService,
        private IUserSession $userSession,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }//end __construct()

    /**
     * List suites for the current user.
     *
     * @NoAdminRequired
     */
    public function index(): JSONResponse
    {
        $userId = $this->userSession->getUser()->getUID();
        $suites = $this->suiteService->getSuitesByOwner('user', $userId);

        return new JSONResponse(array_map(
            static fn ($s) => $s->jsonSerialize(),
            $suites
        ));
    }//end index()

    /**
     * Get a specific suite.
     *
     * @NoAdminRequired
     */
    public function show(string $id): JSONResponse
    {
        try {
            $suite = $this->suiteService->getSuite($id);
            $this->validateOwnership($suite);
            return new JSONResponse($suite->jsonSerialize());
        } catch (\Exception $e) {
            return new JSONResponse(
                ['message' => $e->getMessage()],
                Http::STATUS_NOT_FOUND
            );
        }
    }//end show()

    /**
     * Create a new EncryptionSuite for the current user.
     *
     * @NoAdminRequired
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
        } catch (\RuntimeException $e) {
            return new JSONResponse(
                ['message' => $e->getMessage()],
                Http::STATUS_SERVICE_UNAVAILABLE
            );
        }
    }//end create()

    /**
     * Update the encrypted private key (routine password change).
     *
     * @NoAdminRequired
     */
    public function updatePrivateKey(string $id, string $encryptedPrivateKey): JSONResponse
    {
        try {
            $suite = $this->suiteService->getSuite($id);
            $this->validateOwnership($suite);

            $suite->setPrivateKey($encryptedPrivateKey);
            return new JSONResponse($suite->jsonSerialize());
        } catch (\Exception $e) {
            return new JSONResponse(
                ['message' => $e->getMessage()],
                Http::STATUS_FORBIDDEN
            );
        }
    }//end updatePrivateKey()

    /**
     * Revoke an EncryptionSuite.
     *
     * @NoAdminRequired
     */
    public function revoke(string $id, string $reason): JSONResponse
    {
        $userId = $this->userSession->getUser()->getUID();

        try {
            $suite = $this->suiteService->revokeSuite($id, $reason, $userId);
            return new JSONResponse($suite->jsonSerialize());
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(
                ['message' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST
            );
        }
    }//end revoke()

    /**
     * Reinstate a revoked EncryptionSuite (admin only).
     */
    public function reinstate(string $id): JSONResponse
    {
        $userId = $this->userSession->getUser()->getUID();

        try {
            $suite = $this->suiteService->reinstateSuite($id, $userId);
            return new JSONResponse($suite->jsonSerialize());
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(
                ['message' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST
            );
        }
    }//end reinstate()

    /**
     * Initiate compromise recovery: create new suite and migration record.
     *
     * @NoAdminRequired
     */
    public function compromiseRecovery(
        string $publicKey,
        string $encryptedPrivateKey,
    ): JSONResponse {
        $userId = $this->userSession->getUser()->getUID();

        try {
            $oldSuite = $this->suiteService->getActiveSuite('user', $userId);
            $newSuite = $this->suiteService->createSuite('user', $userId, $publicKey, $encryptedPrivateKey);
            $migration = $this->migrationService->initiateCompromiseRecovery(
                $oldSuite->getId(),
                $newSuite->getId()
            );

            return new JSONResponse([
                'newSuite'          => $newSuite->jsonSerialize(),
                'migration'         => $migration->jsonSerialize(),
                'oldEncryptedPrivateKey' => $oldSuite->getPrivateKey(),
            ], Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['message' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }//end compromiseRecovery()

    /**
     * Validate that the current user owns the suite (or is admin).
     */
    private function validateOwnership($suite): void
    {
        $userId = $this->userSession->getUser()->getUID();
        if ($suite->getOwnerType() === 'user' && $suite->getOwnerId() !== $userId) {
            throw new \RuntimeException('Access denied: suite belongs to another user');
        }
    }//end validateOwnership()
}//end class
