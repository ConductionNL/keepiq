<?php

declare(strict_types=1);

namespace OCA\Doriath\Controller;

use OCA\Doriath\AppInfo\Application;
use OCA\Doriath\Service\MigrationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for suite migration tracking.
 */
class MigrationController extends OCSController
{
    public function __construct(
        IRequest $request,
        private MigrationService $migrationService,
        private IUserSession $userSession,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }//end __construct()

    /**
     * Get in-progress migration status for the current user.
     *
     * @NoAdminRequired
     */
    public function getStatus(): JSONResponse
    {
        $userId = $this->userSession->getUser()->getUID();

        try {
            $migration = $this->migrationService->getInProgressMigration('user', $userId);
            return new JSONResponse($migration->jsonSerialize());
        } catch (DoesNotExistException) {
            return new JSONResponse(['status' => 'none']);
        }
    }//end getStatus()

    /**
     * Complete a migration.
     *
     * @NoAdminRequired
     */
    public function complete(string $id, bool $hasErrors = false): JSONResponse
    {
        try {
            $migration = $this->migrationService->completeMigration($id, $hasErrors);
            return new JSONResponse($migration->jsonSerialize());
        } catch (\Exception $e) {
            return new JSONResponse(
                ['message' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST
            );
        }
    }//end complete()
}//end class
