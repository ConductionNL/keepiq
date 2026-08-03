<?php

/**
 * Doriath Service Worker Controller
 *
 * Serves the offline app-shell service worker (offline-readonly-cache §3)
 * from the app ROOT path (`/serviceworker.js`) with the correct
 * `Content-Type: application/javascript` — Nextcloud's static `js/`
 * asset route mislabels the script, which the browser rejects with
 * "unsupported MIME type". Serving from the app root also gives the
 * worker the whole-SPA scope by default (its scope is the directory it
 * is served from), so no `Service-Worker-Allowed` header is required.
 * Public + CSRF-free because a service worker script is fetched by the
 * browser's SW machinery without app cookies/token.
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

use OCA\Doriath\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IRequest;

/**
 * Serves the offline service-worker script with the correct headers.
 */
class ServiceWorkerController extends Controller
{
    /**
     * Constructor for ServiceWorkerController.
     *
     * @param IRequest    $request    The HTTP request
     * @param IAppManager $appManager Resolves the app install path
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private IAppManager $appManager,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Return the built service-worker script as JavaScript with the
     * scope-widening header.
     *
     * @PublicPage
     * @NoCSRFRequired
     *
     * @return DataDisplayResponse
     *
     * @spec openspec/changes/offline-readonly-cache/specs/offline-readonly-cache/spec.md#requirement-service-worker-shell
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function script(): DataDisplayResponse
    {
        $path   = $this->appManager->getAppPath(Application::APP_ID).'/js/'.Application::APP_ID.'-service-worker.js';
        $script = false;
        if (is_file($path) === true && is_readable($path) === true) {
            $script = file_get_contents($path);
        }

        if ($script === false) {
            return new DataDisplayResponse(
                data: '/* service worker unavailable */',
                statusCode: Http::STATUS_NOT_FOUND,
                headers: ['Content-Type' => 'application/javascript']
            );
        }

        $response = new DataDisplayResponse(
            data: $script,
            statusCode: Http::STATUS_OK,
            headers: ['Content-Type' => 'application/javascript']
        );
        $response->cacheFor(0);

        return $response;
    }//end script()
}//end class
