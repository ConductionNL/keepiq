<?php

/**
 * Unit tests for JwtAuthMiddleware.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Middleware
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

namespace OCA\Keepiq\Tests\Unit\Middleware;

use OCA\Keepiq\Controller\ApplicationApiController;
use OCA\Keepiq\Db\Application;
use OCA\Keepiq\Middleware\JwtAuthMiddleware;
use OCA\Keepiq\Service\JwtAuthService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Concrete subclass for testing — defines no extra behaviour.
 */
class ConcreteAppController extends ApplicationApiController {
}//end class

/**
 * Tests for JwtAuthMiddleware.
 */
#[CoversClass(JwtAuthMiddleware::class)]
#[UsesClass(ApplicationApiController::class)]
#[UsesClass(Application::class)]
class JwtAuthMiddlewareTest extends TestCase {
	/**
	 * Session controllers (non-ApplicationApiController) pass through
	 * untouched.
	 *
	 * @return void
	 */
	public function testSessionControllerPassesThrough(): void {
		$request = $this->createMock(IRequest::class);
		$service = $this->createMock(JwtAuthService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$middleware = new JwtAuthMiddleware($request, $service, $logger);

		$controller = $this->createMock(Controller::class);
		$request->expects($this->never())->method('getHeader');

		$middleware->beforeController($controller, 'foo');
		$this->addToAssertionCount(1);
	}//end testSessionControllerPassesThrough()

	/**
	 * Missing Authorization header → exception.
	 *
	 * @return void
	 */
	public function testMissingAuthHeaderThrows(): void {
		$request = $this->createMock(IRequest::class);
		$service = $this->createMock(JwtAuthService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$middleware = new JwtAuthMiddleware($request, $service, $logger);

		$controller = new ConcreteAppController('keepiq', $request);
		$request->method('getHeader')->with('Authorization')->willReturn('');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Missing or malformed');
		$middleware->beforeController($controller, 'index');
	}//end testMissingAuthHeaderThrows()

	/**
	 * Malformed (non-Bearer) Authorization header → exception.
	 *
	 * @return void
	 */
	public function testNonBearerHeaderThrows(): void {
		$request = $this->createMock(IRequest::class);
		$service = $this->createMock(JwtAuthService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$middleware = new JwtAuthMiddleware($request, $service, $logger);

		$controller = new ConcreteAppController('keepiq', $request);
		$request->method('getHeader')->with('Authorization')->willReturn('Basic abc');

		$this->expectException(RuntimeException::class);
		$middleware->beforeController($controller, 'index');
	}//end testNonBearerHeaderThrows()

	/**
	 * Invalid Bearer token → exception.
	 *
	 * @return void
	 */
	public function testInvalidTokenThrows(): void {
		$request = $this->createMock(IRequest::class);
		$service = $this->createMock(JwtAuthService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$request->method('getHeader')->with('Authorization')->willReturn('Bearer bad-token');
		$service->method('validateAccessToken')->with('bad-token')->willReturn(null);

		$middleware = new JwtAuthMiddleware($request, $service, $logger);
		$controller = new ConcreteAppController('keepiq', $request);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Invalid or expired');
		$middleware->beforeController($controller, 'index');
	}//end testInvalidTokenThrows()

	/**
	 * A Bearer header with only whitespace after the scheme → exception,
	 * and the token service is never asked.
	 *
	 * @return void
	 */
	public function testBlankBearerTokenThrowsWithoutValidating(): void {
		$request = $this->createMock(originalClassName: IRequest::class);
		$service = $this->createMock(originalClassName: JwtAuthService::class);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$request->method('getHeader')->with('Authorization')->willReturn("Bearer  \t ");
		$service->expects($this->never())->method('validateAccessToken');

		$middleware = new JwtAuthMiddleware(request: $request, service: $service, logger: $logger);
		$controller = new ConcreteAppController('keepiq', $request);

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'Empty Bearer token');
		$middleware->beforeController($controller, 'index');
	}//end testBlankBearerTokenThrowsWithoutValidating()

	/**
	 * Valid token → application injected.
	 *
	 * @return void
	 */
	public function testValidTokenInjectsApplication(): void {
		$request = $this->createMock(IRequest::class);
		$service = $this->createMock(JwtAuthService::class);
		$logger = $this->createMock(LoggerInterface::class);

		$app = new Application();
		$app->setId('app-x');

		$request->method('getHeader')->with('Authorization')->willReturn('Bearer good-token');
		$service->method('validateAccessToken')->with('good-token')->willReturn($app);

		$middleware = new JwtAuthMiddleware($request, $service, $logger);
		$controller = new ConcreteAppController('keepiq', $request);

		$middleware->beforeController($controller, 'index');
		$this->assertSame($app, $controller->getApplication());
	}//end testValidTokenInjectsApplication()

	/**
	 * Exceptions raised on Bearer routes are translated to 401 JSON.
	 *
	 * @return void
	 */
	public function testAfterExceptionReturns401(): void {
		$request = $this->createMock(IRequest::class);
		$service = $this->createMock(JwtAuthService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$middleware = new JwtAuthMiddleware($request, $service, $logger);

		$controller = new ConcreteAppController('keepiq', $request);
		$response = $middleware->afterException($controller, 'index', new RuntimeException('nope'));

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('nope', $data['message']);
	}//end testAfterExceptionReturns401()

	/**
	 * Exceptions on non-Bearer controllers are re-thrown.
	 *
	 * @return void
	 */
	public function testAfterExceptionRethrowsForSessionController(): void {
		$request = $this->createMock(IRequest::class);
		$service = $this->createMock(JwtAuthService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$middleware = new JwtAuthMiddleware($request, $service, $logger);

		$controller = $this->createMock(Controller::class);
		$exception = new RuntimeException('original');

		$this->expectExceptionObject($exception);
		$middleware->afterException($controller, 'foo', $exception);
	}//end testAfterExceptionRethrowsForSessionController()

	/**
	 * A missing header is logged with the method it was refused for, and
	 * the token service is never asked.
	 *
	 * @return void
	 */
	public function testMissingHeaderIsLoggedWithTheMethod(): void {
		$request = $this->createMock(originalClassName: IRequest::class);
		$service = $this->createMock(originalClassName: JwtAuthService::class);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$request->method('getHeader')->with('Authorization')->willReturn('');
		$service->expects($this->never())->method('validateAccessToken');
		$logger->expects($this->once())
			->method('info')
			->with(
				'JwtAuthMiddleware: missing Bearer header',
				['app' => 'keepiq', 'method' => 'listSecrets']
			);

		$middleware = new JwtAuthMiddleware(request: $request, service: $service, logger: $logger);
		$controller = new ConcreteAppController('keepiq', $request);

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'Missing or malformed Authorization header');
		$middleware->beforeController($controller, 'listSecrets');
	}//end testMissingHeaderIsLoggedWithTheMethod()

	/**
	 * The scheme is matched case-insensitively and the token is passed on
	 * trimmed of surrounding whitespace.
	 *
	 * @return void
	 */
	public function testSchemeIsCaseInsensitiveAndTokenIsTrimmed(): void {
		$request = $this->createMock(originalClassName: IRequest::class);
		$service = $this->createMock(originalClassName: JwtAuthService::class);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);

		$app = new Application();
		$app->setId('app-y');

		$request->method('getHeader')->with('Authorization')->willReturn("bEaReR  tok.en  \t");
		$service->expects($this->once())
			->method('validateAccessToken')
			->with('tok.en')
			->willReturn($app);
		$logger->expects($this->never())->method('info');

		$middleware = new JwtAuthMiddleware(request: $request, service: $service, logger: $logger);
		$controller = new ConcreteAppController('keepiq', $request);

		$middleware->beforeController($controller, 'index');
		$this->assertSame(expected: $app, actual: $controller->getApplication());
	}//end testSchemeIsCaseInsensitiveAndTokenIsTrimmed()

	/**
	 * A header that only contains the word Bearer somewhere later is no
	 * Bearer header: refused before the token service is asked.
	 *
	 * @return void
	 */
	public function testBearerNotAtTheStartIsRefused(): void {
		$request = $this->createMock(originalClassName: IRequest::class);
		$service = $this->createMock(originalClassName: JwtAuthService::class);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$request->method('getHeader')->with('Authorization')->willReturn('Token Bearer abc');
		$service->expects($this->never())->method('validateAccessToken');
		$logger->expects($this->once())->method('info');

		$middleware = new JwtAuthMiddleware(request: $request, service: $service, logger: $logger);
		$controller = new ConcreteAppController('keepiq', $request);

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'Missing or malformed Authorization header');
		$middleware->beforeController($controller, 'index');
	}//end testBearerNotAtTheStartIsRefused()

	/**
	 * A refused token leaves no application on the controller, and the
	 * refusal turns into a 401 carrying the refusal's message.
	 *
	 * @return void
	 */
	public function testRefusedTokenBecomes401WithItsMessage(): void {
		$request = $this->createMock(originalClassName: IRequest::class);
		$service = $this->createMock(originalClassName: JwtAuthService::class);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$request->method('getHeader')->with('Authorization')->willReturn('Bearer expired');
		$service->method('validateAccessToken')->with('expired')->willReturn(null);

		$middleware = new JwtAuthMiddleware(request: $request, service: $service, logger: $logger);
		$controller = new ConcreteAppController('keepiq', $request);

		try {
			$middleware->beforeController($controller, 'index');
			$this->fail(message: 'A refused token must throw.');
		} catch (RuntimeException $exception) {
			$response = $middleware->afterException($controller, 'index', $exception);
		}

		$this->assertNull(actual: $controller->getApplication());
		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Invalid or expired access token'], actual: $response->getData());
	}//end testRefusedTokenBecomes401WithItsMessage()
}//end class
