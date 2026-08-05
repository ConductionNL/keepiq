<?php

/**
 * Doriath JWT Authentication Middleware
 *
 * Validates the `Authorization: Bearer <token>` header on controllers
 * that extend ApplicationApiController, resolving the bound Application
 * entity via JwtAuthService and rejecting unauthenticated requests with
 * a 401 JSON response.
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
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Bearer-token middleware for application-authenticated routes.
 *
 * The middleware only fires for controllers extending
 * ApplicationApiController — session-authenticated controllers are
 * passed through untouched. On unauthenticated/invalid tokens it
 * raises NotAuthenticatedException, which `afterException` then
 * translates to a 401 JSON response.
 */
class JwtAuthMiddleware extends Middleware
{
    /**
     * Constructor for JwtAuthMiddleware.
     *
     * @param IRequest        $request The HTTP request
     * @param JwtAuthService  $service The JWT auth service
     * @param LoggerInterface $logger  The logger
     *
     * @return void
     */
    public function __construct(
        private IRequest $request,
        private JwtAuthService $service,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Run before each controller method. For ApplicationApiController
     * subclasses, extract the Bearer token, validate it, and inject the
     * resolved Application entity on the controller.
     *
     * @param Controller $controller The controller about to run
     * @param string     $methodName The method about to run
     *
     * @return void
     *
     * @throws RuntimeException When authentication fails.
     */
    public function beforeController($controller, $methodName): void
    {
        if (($controller instanceof ApplicationApiController) === false) {
            return;
        }

        $header = $this->request->getHeader('Authorization');
        if ($header === '' || stripos($header, 'Bearer ') !== 0) {
            $this->logger->info(
                'JwtAuthMiddleware: missing Bearer header',
                ['app' => 'doriath', 'method' => $methodName]
            );
            throw new RuntimeException(
                message: 'Missing or malformed Authorization header'
            );
        }

        $token = trim(substr($header, 7));
        if ($token === '') {
            throw new RuntimeException(message: 'Empty Bearer token');
        }

        $application = $this->service->validateAccessToken($token);
        if ($application === null) {
            throw new RuntimeException(message: 'Invalid or expired access token');
        }

        $controller->setApplication($application);
    }//end beforeController()

    /**
     * Translate auth errors raised by beforeController into a 401 JSON
     * response on application-authenticated routes. Other exceptions
     * are re-thrown for the framework to handle.
     *
     * @param Controller $controller The controller
     * @param string     $methodName The method
     * @param Throwable  $exception  The exception
     *
     * @return JSONResponse
     *
     * @throws Throwable When the controller is not an
     *                   ApplicationApiController (re-thrown).
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $methodName is mandated by
     *   OCP\AppFramework\Middleware::afterException(), which this method overrides
     *   and which the framework calls positionally. Narrowing the signature would
     *   be a fatal incompatible-signature error at class-load time.
     */
    public function afterException($controller, $methodName, Throwable $exception): JSONResponse
    {
        if (($controller instanceof ApplicationApiController) === false) {
            throw $exception;
        }

        return new JSONResponse(
            data: ['message' => $exception->getMessage()],
            statusCode: Http::STATUS_UNAUTHORIZED
        );
    }//end afterException()
}//end class
