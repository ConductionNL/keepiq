<?php

/**
 * Doriath Application Token Controller
 *
 * Public RFC 7523 token endpoint: exchanges a JWT Bearer assertion (signed
 * by an application's own RSA private key) for a short-lived opaque access
 * token.
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

use InvalidArgumentException;
use OCA\Doriath\AppInfo\Application as AppInfo;
use OCA\Doriath\Service\JwtAuthService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * Public OAuth2 JWT-Bearer token exchange endpoint.
 */
class ApplicationTokenController extends OCSController
{
    /**
     * The RFC 7523 JWT Bearer grant type.
     *
     * @var string
     */
    private const GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:jwt-bearer';

    /**
     * Constructor for ApplicationTokenController.
     *
     * @param IRequest       $request        The request object
     * @param JwtAuthService $jwtAuthService The JWT auth service
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private JwtAuthService $jwtAuthService,
    ) {
        parent::__construct(appName: AppInfo::APP_ID, request: $request);
    }//end __construct()

    /**
     * Exchange a JWT assertion for an access token.
     *
     * The endpoint is public (no Nextcloud session): authentication is
     * established solely by the signed JWT assertion, verified against the
     * application's stored public certificate.
     *
     * @param string $assertion The compact-serialised signed JWT
     *
     * @PublicPage
     * @NoCSRFRequired
     *
     * @return JSONResponse
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function exchange(string $assertion=''): JSONResponse
    {
        // The OAuth2 grant_type field is snake_case by spec; read it from the
        // request directly so the controller signature stays camelCase-clean.
        $grantType = (string) $this->request->getParam('grant_type', '');

        if ($grantType !== self::GRANT_TYPE) {
            return new JSONResponse(
                data: ['error' => 'unsupported_grant_type'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        if ($assertion === '') {
            return new JSONResponse(
                data: ['error' => 'invalid_request', 'error_description' => 'Missing assertion'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $token = $this->jwtAuthService->exchangeAssertion($assertion);
        } catch (InvalidArgumentException) {
            // Uniform 401 to avoid leaking which validation step failed.
            return new JSONResponse(
                data: ['error' => 'invalid_grant'],
                statusCode: Http::STATUS_UNAUTHORIZED
            );
        }

        return new JSONResponse(data: $token);
    }//end exchange()
}//end class
