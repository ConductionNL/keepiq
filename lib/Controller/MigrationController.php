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
use OCA\Doriath\Service\EncryptionSuiteService;
use OCA\Doriath\Service\MigrationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for suite migration tracking.
 */
class MigrationController extends OCSController {
	/**
	 * Constructor for MigrationController.
	 *
	 * @param IRequest $request The request object
	 * @param MigrationService $migrationService The migration service
	 * @param EncryptionSuiteService $suiteService The suite service (ownership check)
	 * @param IUserSession $userSession The user session
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private MigrationService $migrationService,
		private EncryptionSuiteService $suiteService,
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
	#[NoAdminRequired]
	public function getStatus(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$userId = $user->getUID();

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
	 * @param string $id The migration ID
	 * @param bool $hasErrors Whether the migration had errors
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $hasErrors is outcome DATA carried
	 *   in the POST body and bound by name by the Nextcloud router, not a mode switch
	 *   the caller picks: it only selects which terminal status string is recorded.
	 *   Splitting the method would split the route and change the HTTP contract.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-4
	 */
	#[NoAdminRequired]
	public function complete(string $id, bool $hasErrors = false): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			// Enforce ownership: verify the migration's old suite belongs to the
			// current user before allowing them to mark it complete.
			$migration = $this->migrationService->getMigration(migrationId: $id);
			$oldSuite = $this->suiteService->getSuite($migration->getOldSuiteId());
			if ($oldSuite->getOwnerType() !== 'user' || $oldSuite->getOwnerId() !== $user->getUID()) {
				return new JSONResponse(
					data: ['message' => 'Forbidden: migration does not belong to you'],
					statusCode: Http::STATUS_FORBIDDEN
				);
			}

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
