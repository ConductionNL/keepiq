<?php

/**
 * Doriath Metrics Controller
 *
 * Exposes application metrics in Prometheus text exposition format.
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
use OCA\Doriath\Service\EncryptionSuiteService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TextPlainResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for exposing Prometheus metrics.
 */
class MetricsController extends Controller
{
    /**
     * Constructor for MetricsController.
     *
     * @param IRequest               $request      The HTTP request
     * @param IAppManager            $appManager   App manager
     * @param EncryptionSuiteService $suiteService Suite service for suite counts
     * @param IUserSession           $userSession  The user session
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private IAppManager $appManager,
        private EncryptionSuiteService $suiteService,
        private IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Return Prometheus metrics in text exposition format.
     *
     * @NoAdminRequired
     *
     * @return TextPlainResponse|JSONResponse Prometheus-formatted metrics or 401 if unauthenticated
     */
    #[NoAdminRequired]
    public function index(): TextPlainResponse|JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $lines    = $this->collectMetrics();
        $response = new TextPlainResponse(implode("\n", $lines)."\n");
        $response->addHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');

        return $response;
    }//end index()

    /**
     * Collect all metrics and format as Prometheus text.
     *
     * @return string[] Prometheus exposition format lines
     */
    private function collectMetrics(): array
    {
        $version = $this->getAppVersion();

        return [
            '# HELP doriath_info Doriath application information',
            '# TYPE doriath_info gauge',
            "doriath_info{version=\"{$version}\",php_version=\"".PHP_VERSION."\"} 1",
            '# HELP doriath_suites_total Total number of active encryption suites',
            '# TYPE doriath_suites_total gauge',
            'doriath_suites_total '.$this->suiteService->countActiveSuites(),
        ];
    }//end collectMetrics()

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
