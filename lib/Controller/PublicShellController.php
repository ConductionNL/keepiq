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
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

/**
 * Anonymous SPA shell for recipient pages.
 */
class PublicShellController extends Controller {
	/**
	 * Constructor for PublicShellController.
	 *
	 * @param IRequest $request The HTTP request
	 *
	 * @return void
	 */
	public function __construct(IRequest $request) {
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
	 * Rate-limit rationale: this is the public shell — one of only four rendered
	 * public pages in the fleet (ADR-081). It serves the zero-knowledge
	 * secret-request UI, so the ceiling is generous: a recipient reloading the
	 * page must never be what trips it. The token check lives on the fill/access
	 * endpoints behind it, which are already rate-limited.
	 *
	 * @return TemplateResponse
	 *
	 * @spec openspec/specs/ephemeral-send/spec.md#requirement-anonymous-recipient-access-with-no-account
	 *
	 * @contract exclude renders a TemplateResponse, not an API response. This
	 * endpoint serves the HTML shell that boots the zero-knowledge recipient
	 * UI; it has no request body, no JSON schema and no status contract beyond
	 * "200 with the shell". gate-25 is the API-layer companion to gate-19, and
	 * a Newman assertion here could only restate that a page renders — which
	 * the e2e suite already does through the shell it boots. The endpoints that
	 * DO carry a contract are the fill/access ones behind this shell, and they
	 * are tested there.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function page(): TemplateResponse {
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
