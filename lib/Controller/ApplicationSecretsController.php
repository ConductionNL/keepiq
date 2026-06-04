<?php

/**
 * Doriath Application Secrets Controller
 *
 * Bearer-authenticated API for an application to retrieve its own
 * encrypted secrets. The Bearer access token is validated and the
 * authenticated Application is injected by JwtAuthMiddleware.
 *
 * The encrypted secret store (a Secret entity owned by owner_type=application)
 * is not yet part of this app; until it lands, these endpoints return the
 * authenticated application's identity and an empty secret set. The auth
 * plumbing is complete and the response contract is stable.
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

use OCA\Doriath\AppInfo\Application as AppInfo;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Bearer-authenticated application secret retrieval.
 */
class ApplicationSecretsController extends ApplicationApiController
{
    /**
     * Constructor for ApplicationSecretsController.
     *
     * @param IRequest $request The request object
     *
     * @return void
     */
    public function __construct(IRequest $request)
    {
        parent::__construct(appName: AppInfo::APP_ID, request: $request);
    }//end __construct()

    /**
     * List the authenticated application's secrets (encrypted blobs only).
     *
     * Authentication is performed by JwtAuthMiddleware, which injects the
     * Application; the #[PublicPage] attribute disables the Nextcloud session
     * requirement so the Bearer flow is the sole authority.
     *
     * @PublicPage
     * @NoCSRFRequired
     *
     * @return JSONResponse
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function index(): JSONResponse
    {
        $application = $this->getAuthenticatedApplication();
        if ($application === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        return new JSONResponse(
            data: [
                'application' => $application->getId(),
                'secrets'     => [],
            ]
        );
    }//end index()

    /**
     * Get a specific secret for the authenticated application.
     *
     * @param string $id The secret ID
     *
     * @PublicPage
     * @NoCSRFRequired
     *
     * @return JSONResponse
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) The id is part of the stable route contract; secret lookup lands with the Secret entity.
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function show(string $id): JSONResponse
    {
        $application = $this->getAuthenticatedApplication();
        if ($application === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        // No Secret entity exists in this app yet (see class docblock).
        return new JSONResponse(data: ['message' => 'Not found'], statusCode: Http::STATUS_NOT_FOUND);
    }//end show()
}//end class
