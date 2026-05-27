<?php

/**
 * Doriath Health Controller
 *
 * Exposes a health check endpoint for container orchestration and monitoring.
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
 *
 * @psalm-suppress UnusedClass
 */

declare(strict_types=1);

namespace OCA\Doriath\Controller;

use OCA\Doriath\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for health check endpoints.
 */
class HealthController extends Controller
{
    /**
     * Constructor for HealthController.
     *
     * @param IRequest        $request     The HTTP request
     * @param IDBConnection   $db          Database connection
     * @param IAppManager     $appManager  App manager
     * @param IUserSession    $userSession The user session
     * @param LoggerInterface $logger      Logger
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private IDBConnection $db,
        private IAppManager $appManager,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Health check endpoint.
     *
     * Returns the overall health status of the Doriath app, including
     * database connectivity and filesystem access checks.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse Health status
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $checks = [];
        $status = 'ok';

        // Check database connectivity.
        $checks['database'] = $this->checkDatabase();
        if ($checks['database'] !== 'ok') {
            $status = 'error';
        }

        // Check filesystem.
        $checks['filesystem'] = $this->checkFilesystem();
        if ($checks['filesystem'] !== 'ok' && $status !== 'error') {
            $status = 'degraded';
        }

        $httpStatus = Http::STATUS_SERVICE_UNAVAILABLE;
        if ($status === 'ok') {
            $httpStatus = Http::STATUS_OK;
        }

        return new JSONResponse(
            data: [
                'status'  => $status,
                'version' => $this->getAppVersion(),
                'checks'  => $checks,
            ],
            statusCode: $httpStatus
        );
    }//end index()

    /**
     * Check database connectivity.
     *
     * @return string 'ok' or error message
     */
    private function checkDatabase(): string
    {
        try {
            $qb     = $this->db->getQueryBuilder();
            $result = $qb->select($qb->createFunction('1'))->executeQuery();
            $result->closeCursor();
            return 'ok';
        } catch (\Exception $e) {
            $this->logger->error('[HealthController] Database check failed', ['error' => $e->getMessage()]);
            return 'failed: '.$e->getMessage();
        }
    }//end checkDatabase()

    /**
     * Check filesystem access.
     *
     * @return string 'ok' or error message
     */
    private function checkFilesystem(): string
    {
        try {
            $tmpFile = sys_get_temp_dir().'/doriath_health_'.getmypid();
            $written = file_put_contents($tmpFile, 'health');
            if ($written === false) {
                return 'failed: cannot write to temp directory';
            }

            unlink($tmpFile);
            return 'ok';
        } catch (\Exception $e) {
            return 'failed: '.$e->getMessage();
        }
    }//end checkFilesystem()

    /**
     * Get the app version.
     *
     * @return string The app version
     */
    private function getAppVersion(): string
    {
        try {
            return $this->appManager->getAppVersion(Application::APP_ID);
        } catch (\Exception $e) {
            return 'unknown';
        }
    }//end getAppVersion()
}//end class
