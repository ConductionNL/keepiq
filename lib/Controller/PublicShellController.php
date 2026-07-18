<?php

/**
 * Doriath Public Shell Controller
 *
 * Serves the SPA shell WITHOUT a Nextcloud session for the anonymous
 * recipient pages (ephemeral-send §5.3, link-share access). The SPA
 * routes stay guarded client-side: only PUBLIC_ROUTE_NAMES render
 * without a vault session, and every data call from those pages hits
 * #[PublicPage] API endpoints. A dedicated controller (not a method on
 * DashboardController) because Bootstrap aliases the dashboard name to
 * the AppHost generic controller, which must not grow public methods.
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
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

/**
 * Anonymous SPA shell for recipient pages.
 */
class PublicShellController extends Controller
{
    /**
     * Constructor for PublicShellController.
     *
     * @param IRequest $request The HTTP request
     *
     * @return void
     */
    public function __construct(IRequest $request)
    {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Render the SPA shell for account-less recipients (guest layout).
     *
     * Mirrors DashboardController::page()'s CSP relaxations: the
     * Argon2id WASM module needs 'wasm-unsafe-eval' for the
     * password-protected flows, and the health worker allowance is
     * harmless here.
     *
     * @return TemplateResponse
     *
     * @spec openspec/changes/ephemeral-send/specs/ephemeral-send/spec.md#requirement-anonymous-access
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function page(): TemplateResponse
    {
        $response = new TemplateResponse(
            appName: Application::APP_ID,
            templateName: 'index',
            params: [],
            renderAs: TemplateResponse::RENDER_AS_BASE
        );

        $csp = new ContentSecurityPolicy();
        $csp->allowEvalWasm(true);
        $csp->addAllowedWorkerSrcDomain("'self'");
        $response->setContentSecurityPolicy($csp);

        return $response;
    }//end page()
}//end class
