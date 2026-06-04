<?php

/**
 * Doriath JWT Auth Middleware
 *
 * Authenticates application API requests that target an
 * ApplicationApiController by validating the Bearer access token and
 * injecting the authenticated Application onto the controller.
 *
 * @category Middleware
 * @package  OCA\Doriath\Middleware
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

namespace OCA\Doriath\Middleware;

use OCA\Doriath\Controller\ApplicationApiController;
use OCA\Doriath\Service\JwtAuthService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use RuntimeException;

/**
 * Middleware enforcing Bearer-token auth on application API controllers.
 */
class JwtAuthMiddleware extends Middleware
{
    /**
     * Raised when an application API request fails authentication.
     *
     * @var string
     */
    private const ERROR = 'Doriath:JwtAuthMiddleware:unauthenticated';

    /**
     * Constructor for JwtAuthMiddleware.
     *
     * @param IRequest       $request        The request object
     * @param JwtAuthService $jwtAuthService The JWT auth service
     *
     * @return void
     */
    public function __construct(
        private IRequest $request,
        private JwtAuthService $jwtAuthService,
    ) {
    }//end __construct()

    /**
     * Authenticate the request before the controller runs.
     *
     * Only application API controllers are guarded; all other controllers
     * pass through untouched.
     *
     * @param object $controller The controller instance
     * @param string $methodName The method about to be invoked
     *
     * @return void
     *
     * @throws RuntimeException When authentication fails (mapped to 401 in afterException)
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeController($controller, $methodName): void
    {
        if (($controller instanceof ApplicationApiController) === false) {
            return;
        }

        $token       = $this->extractBearerToken();
        $application = null;
        if ($token !== null) {
            $application = $this->jwtAuthService->validateAccessToken(token: $token);
        }

        if ($application === null) {
            throw new RuntimeException(self::ERROR);
        }

        $controller->setAuthenticatedApplication(application: $application);
    }//end beforeController()

    /**
     * Map authentication failures to a 401 JSON response.
     *
     * @param object     $controller The controller instance
     * @param string     $methodName The method that was invoked
     * @param \Exception $exception  The thrown exception
     *
     * @return JSONResponse
     *
     * @throws \Exception When the exception is not ours to handle
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterException($controller, $methodName, \Exception $exception): JSONResponse
    {
        if ($exception->getMessage() === self::ERROR) {
            return new JSONResponse(
                data: ['message' => 'Unauthorized'],
                statusCode: Http::STATUS_UNAUTHORIZED
            );
        }

        throw $exception;
    }//end afterException()

    /**
     * Extract the Bearer token from the Authorization header.
     *
     * @return string|null The token, or null when absent/malformed
     */
    private function extractBearerToken(): ?string
    {
        $header = $this->request->getHeader('Authorization');
        if ($header === '' || stripos($header, 'Bearer ') !== 0) {
            return null;
        }

        $token = trim(substr($header, 7));

        if ($token === '') {
            return null;
        }

        return $token;
    }//end extractBearerToken()
}//end class
