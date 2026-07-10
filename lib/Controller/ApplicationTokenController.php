<?php

/**
 * Doriath Application Token Controller
 *
 * Public OAuth-style token endpoint that exchanges a signed JWT
 * assertion (private_key_jwt grant) for a short-lived opaque Bearer
 * access token. The endpoint is anonymous (#[PublicPage]) — security
 * is enforced via signature verification against the registered
 * application's certificate public key.
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

use OCA\Doriath\AppInfo\Application as DoriathApp;
use OCA\Doriath\Service\JwtAuthService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use RuntimeException;

/**
 * Public token-exchange endpoint (`POST /api/v1/token`).
 */
class ApplicationTokenController extends Controller
{
    /**
     * Constructor for ApplicationTokenController.
     *
     * @param IRequest       $request The HTTP request
     * @param JwtAuthService $service The JWT auth service
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private JwtAuthService $service,
    ) {
        parent::__construct(appName: DoriathApp::APP_ID, request: $request);
    }//end __construct()

    /**
     * Exchange a JWT-Bearer assertion for an opaque access token.
     *
     * Accepts an OAuth-style form-or-JSON request body with two
     * parameters:
     *
     * - `grant_type` — must be `urn:ietf:params:oauth:grant-type:jwt-bearer`.
     * - `assertion`  — the Compact-Serialized JWS signed with the
     *   application's registered private key.
     *
     * @param string $grantType The grant type
     * @param string $assertion The JWS compact serialization
     *
     * @return JSONResponse
     */
    #[PublicPage]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 10, period: 60)]
    public function exchange(string $grantType='', string $assertion=''): JSONResponse
    {
        if ($grantType !== 'urn:ietf:params:oauth:grant-type:jwt-bearer') {
            return new JSONResponse(
                data: [
                    'error'             => 'unsupported_grant_type',
                    'error_description' => 'Only urn:ietf:params:oauth:grant-type:jwt-bearer is supported',
                ],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        if ($assertion === '') {
            return new JSONResponse(
                data: [
                    'error'             => 'invalid_request',
                    'error_description' => 'assertion parameter is required',
                ],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $result = $this->service->exchangeAssertion($assertion);
        } catch (RuntimeException $e) {
            return new JSONResponse(
                data: [
                    'error'             => 'invalid_grant',
                    'error_description' => $e->getMessage(),
                ],
                statusCode: Http::STATUS_UNAUTHORIZED
            );
        }

        return new JSONResponse(data: $result);
    }//end exchange()
}//end class
