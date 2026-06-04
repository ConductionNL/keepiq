<?php

/**
 * Unit tests for JwtAuthMiddleware.
 *
 * @category Test
 * @package  OCA\Doriath\Tests\Unit\Middleware
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

namespace OCA\Doriath\Tests\Unit\Middleware;

use OCA\Doriath\Controller\ApplicationApiController;
use OCA\Doriath\Db\Application;
use OCA\Doriath\Middleware\JwtAuthMiddleware;
use OCA\Doriath\Service\JwtAuthService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for JwtAuthMiddleware.
 */
class JwtAuthMiddlewareTest extends TestCase
{
    /**
     * The mocked request.
     *
     * @var IRequest
     */
    private IRequest $request;

    /**
     * The mocked JWT auth service.
     *
     * @var JwtAuthService
     */
    private JwtAuthService $jwtAuthService;

    /**
     * The middleware under test.
     *
     * @var JwtAuthMiddleware
     */
    private JwtAuthMiddleware $middleware;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request        = $this->createMock(originalClassName: IRequest::class);
        $this->jwtAuthService = $this->createMock(originalClassName: JwtAuthService::class);
        $this->middleware     = new JwtAuthMiddleware(
            request: $this->request,
            jwtAuthService: $this->jwtAuthService
        );
    }

    /**
     * A non-application controller passes straight through.
     *
     * @return void
     */
    public function testNonApiControllerPassesThrough(): void
    {
        $controller = $this->createMock(originalClassName: Controller::class);
        $this->jwtAuthService->expects($this->never())->method('validateAccessToken');

        $this->middleware->beforeController($controller, 'index');
        $this->addToAssertionCount(1);
    }

    /**
     * A missing Authorization header throws the auth error.
     *
     * @return void
     */
    public function testMissingHeaderThrows(): void
    {
        $controller = $this->makeApiController();
        $this->request->method('getHeader')->with('Authorization')->willReturn('');

        $this->expectException(RuntimeException::class);
        $this->middleware->beforeController($controller, 'index');
    }

    /**
     * An invalid Bearer token throws the auth error.
     *
     * @return void
     */
    public function testInvalidTokenThrows(): void
    {
        $controller = $this->makeApiController();
        $this->request->method('getHeader')->with('Authorization')->willReturn('Bearer bad-token');
        $this->jwtAuthService->method('validateAccessToken')->with('bad-token')->willReturn(null);

        $this->expectException(RuntimeException::class);
        $this->middleware->beforeController($controller, 'index');
    }

    /**
     * A valid token injects the application onto the controller.
     *
     * @return void
     */
    public function testValidTokenInjectsApplication(): void
    {
        $controller = $this->makeApiController();
        $app        = new Application();
        $app->setId('app-1');
        $app->setStatus('active');

        $this->request->method('getHeader')->with('Authorization')->willReturn('Bearer good-token');
        $this->jwtAuthService->method('validateAccessToken')->with('good-token')->willReturn($app);

        $this->middleware->beforeController($controller, 'index');
        $this->assertSame($app, $controller->getAuthenticatedApplication());
    }

    /**
     * The auth error is mapped to a 401 JSON response.
     *
     * @return void
     */
    public function testAfterExceptionMapsAuthErrorTo401(): void
    {
        $controller = $this->makeApiController();
        $exception  = new RuntimeException('Doriath:JwtAuthMiddleware:unauthenticated');

        $response = $this->middleware->afterException($controller, 'index', $exception);
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }

    /**
     * Unrelated exceptions are re-thrown.
     *
     * @return void
     */
    public function testAfterExceptionRethrowsOther(): void
    {
        $controller = $this->makeApiController();
        $this->expectException(RuntimeException::class);
        $this->middleware->afterException($controller, 'index', new RuntimeException('other'));
    }

    /**
     * Build a concrete ApplicationApiController for injection assertions.
     *
     * @return ApplicationApiController
     */
    private function makeApiController(): ApplicationApiController
    {
        $request = $this->createMock(originalClassName: IRequest::class);

        return new class($request) extends ApplicationApiController {
            /**
             * Constructor.
             *
             * @param IRequest $request The request
             */
            public function __construct(IRequest $request)
            {
                parent::__construct('doriath', $request);
            }
        };
    }
}
