<?php
/**
 * Every response of a Keepiq API route carries the app version in an
 * API-Version header; other routes and the response itself are untouched.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Middleware
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Middleware;

use OCA\Keepiq\Middleware\ApiVersionMiddleware;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests the API version middleware.
 *
 * @covers \OCA\Keepiq\Middleware\ApiVersionMiddleware
 */
class ApiVersionMiddlewareTest extends TestCase {

	/**
	 * The headers as set; getHeaders() resolves server services.
	 *
	 * @param \OCP\AppFramework\Http\Response $response The response
	 *
	 * @return array<string,string>
	 */
	private function headersOf(\OCP\AppFramework\Http\Response $response): array {
		return (new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers'))->getValue($response);
	}//end headersOf()
	/**
	 * The middleware for a request on the given path.
	 *
	 * @param string|false $path The request's path info
	 *
	 * @return ApiVersionMiddleware
	 */
	private function middleware(string|false $path): ApiVersionMiddleware {
		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn($path);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->with('keepiq')->willReturn('1.4.2');

		return new ApiVersionMiddleware(request: $request, appManager: $appManager);
	}//end middleware()

	/**
	 * An API response gets the version; status and body stay as they were.
	 *
	 * @return void
	 */
	public function testAnApiResponseCarriesTheVersion(): void {
		$response = new JSONResponse(data: ['message' => 'Not found'], statusCode: Http::STATUS_NOT_FOUND);

		$result = $this->middleware('/apps/keepiq/api/v1/secrets/7')->afterController(
			controller: new \stdClass(),
			methodName: 'show',
			response: $response
		);

		$this->assertSame($response, $result);
		$this->assertSame('1.4.2', $this->headersOf($result)['API-Version']);
		$this->assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());
		$this->assertSame(['message' => 'Not found'], $result->getData());
	}//end testAnApiResponseCarriesTheVersion()

	/**
	 * A page outside the API, or a request without a path, gets no header.
	 *
	 * @return void
	 */
	public function testOtherRoutesAreUntouched(): void {
		foreach (['/apps/keepiq/secrets', false] as $path) {
			$response = new TemplateResponse(appName: 'keepiq', templateName: 'index');
			$result = $this->middleware($path)->afterController(
				controller: new \stdClass(),
				methodName: 'page',
				response: $response
			);

			$this->assertArrayNotHasKey('API-Version', $this->headersOf($result));
		}
	}//end testOtherRoutesAreUntouched()
}//end class
