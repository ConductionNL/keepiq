<?php

/**
 * Doriath Dashboard Controller
 *
 * Controller for the main Doriath dashboard page.
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
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\IRequest;

/**
 * Controller for the main Doriath dashboard page.
 */
class DashboardController extends Controller
{
    /**
     * Constructor for the DashboardController.
     *
     * @param IRequest      $request      The request object
     * @param IInitialState $initialState The initial state service
     * @param IAppConfig    $appConfig    The app config interface
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private IInitialState $initialState,
        private IAppConfig $appConfig,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Render the main dashboard page.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return TemplateResponse
     *
     * @spec exclude SPA-shell render — returns the index TemplateResponse with no domain logic; framework plumbing.
     */
    public function page(): TemplateResponse
    {
        // Privacy-respecting default: favicons disabled unless an admin opts in.
        $faviconServiceUrl = $this->appConfig->getValueString(Application::APP_ID, 'favicon_service_url', '');
        $this->initialState->provideInitialState(key: 'faviconServiceUrl', data: $faviconServiceUrl);

        $response = new TemplateResponse(appName: Application::APP_ID, templateName: 'index');

        // Link-share encryption derives its AES key client-side via the Argon2id
        // WASM module (argon2-browser). Nextcloud's default CSP forbids
        // WebAssembly compilation, so opt in to `'wasm-unsafe-eval'` for this SPA
        // page. No external script/connect domains are added — the WASM is
        // app-local (served from custom_apps/doriath/js/argon2.wasm).
        $csp = new ContentSecurityPolicy();
        $csp->allowEvalWasm(true);
        $response->setContentSecurityPolicy($csp);

        return $response;
    }//end page()

    /**
     * Serve the SPA for deep links (Vue history mode). Delegates to {@see page()}.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return TemplateResponse
     *
     * @spec exclude Vue history-mode fallback — delegates to page(); pure framework plumbing, no domain logic.
     */
    public function catchAll(): TemplateResponse
    {
        return $this->page();
    }//end catchAll()
}//end class
