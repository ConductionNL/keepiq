<?php

/**
 * Doriath Migration Controller
 *
 * Controller for suite migration tracking.
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
    /**
     * Constructor for MigrationController.
     *
     * @param IRequest         $request          The request object
     * @param MigrationService $migrationService The migration service
     * @param IUserSession     $userSession      The user session
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private MigrationService $migrationService,
        private IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Get in-progress migration status for the current user.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-4
     */
    public function getStatus(): JSONResponse
    {
        $userId = $this->userSession->getUser()->getUID();

        try {
            $migration = $this->migrationService->getInProgressMigration(ownerType: 'user', ownerId: $userId);
            return new JSONResponse(data: $migration->jsonSerialize());
        } catch (DoesNotExistException) {
            return new JSONResponse(data: ['status' => 'none']);
        }
    }//end getStatus()

    /**
     * Complete a migration.
     *
     * @param string $id        The migration ID
     * @param bool   $hasErrors Whether the migration had errors
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     *
     * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-4
     */
    public function complete(string $id, bool $hasErrors=false): JSONResponse
    {
        try {
            $migration = $this->migrationService->completeMigration(migrationId: $id, hasErrors: $hasErrors);
            return new JSONResponse(data: $migration->jsonSerialize());
        } catch (Exception $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }
    }//end complete()
}//end class
