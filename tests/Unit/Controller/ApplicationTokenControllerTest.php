<?php

/**
 * Unit tests for ApplicationTokenController.
 *
 * @category Test
 * @package  OCA\Doriath\Tests\Unit\Controller
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

namespace OCA\Doriath\Tests\Unit\Controller;

use OCA\Doriath\Controller\ApplicationTokenController;
use OCA\Doriath\Service\JwtAuthService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for the public token-exchange endpoint.
 *
 * The regression under test: Nextcloud's dispatcher binds request parameters to
 * method arguments by EXACT name, so the camelCase argument `$grantType` never
 * received the snake_case `grant_type` that RFC 7523 §2.1 and this app's own
 * `.well-known/doriath` document tell every client to send. `$grantType` was
 * always the empty-string default and the endpoint answered
 * `400 unsupported_grant_type` to every request, including well-formed ones —
 * no standards-compliant client could obtain a token at all.
 *
 * The bug survived because the one negative test that existed asserted
 * `unsupported_grant_type`, which is what a wholly broken endpoint returns for
 * ANY input. These tests pin the discriminating cases instead: the snake_case
 * spelling must be ACCEPTED, and a request that is well-formed apart from a
 * missing or malformed assertion must fall through to `invalid_request` /
 * `invalid_grant` rather than being rejected as an unsupported grant.
 */
class ApplicationTokenControllerTest extends TestCase {

	private const JWT_BEARER = 'urn:ietf:params:oauth:grant-type:jwt-bearer';

	/**
	 * Build a controller whose request reports the given wire parameters.
	 *
	 * @param array<string,mixed> $params The request parameters as they arrive
	 *                                    on the wire (canonical snake_case).
	 * @param JwtAuthService|null $service Optional pre-configured auth service.
	 *
	 * @return ApplicationTokenController
	 */
	private function controllerFor(array $params, ?JwtAuthService $service = null): ApplicationTokenController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) use ($params) {
				return ($params[$key] ?? $default);
			}
		);

		return new ApplicationTokenController(
			request: $request,
			service: ($service ?? $this->createMock(JwtAuthService::class))
		);
	}//end controllerFor()

	/**
	 * The canonical snake_case `grant_type` is accepted.
	 *
	 * The dispatcher cannot bind it to `$grantType`, so the argument arrives
	 * empty; the controller must still recognise the grant and fall through to
	 * the missing-assertion branch.
	 *
	 * @return void
	 */
	public function testSnakeCaseGrantTypeIsAccepted(): void {
		$controller = $this->controllerFor(['grant_type' => self::JWT_BEARER]);

		$response = $controller->exchange();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('invalid_request', $response->getData()['error']);
	}//end testSnakeCaseGrantTypeIsAccepted()

	/**
	 * A malformed assertion under the canonical spelling reaches the service
	 * and surfaces as `401 invalid_grant`, not `400 unsupported_grant_type`.
	 *
	 * @return void
	 */
	public function testSnakeCaseGrantTypeReachesTheAssertionPath(): void {
		$service = $this->createMock(JwtAuthService::class);
		$service->method('exchangeAssertion')->willThrowException(
			new RuntimeException('Invalid assertion format')
		);

		$controller = $this->controllerFor(
			['grant_type' => self::JWT_BEARER, 'assertion' => 'not.a.jwt'],
			$service
		);

		$response = $controller->exchange(assertion: 'not.a.jwt');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('invalid_grant', $response->getData()['error']);
	}//end testSnakeCaseGrantTypeReachesTheAssertionPath()

	/**
	 * The camelCase spelling keeps working for callers already written against
	 * it, including when no `grant_type` is present on the request at all.
	 *
	 * @return void
	 */
	public function testCamelCaseGrantTypeStillWorks(): void {
		$controller = $this->controllerFor([]);

		$response = $controller->exchange(grantType: self::JWT_BEARER);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('invalid_request', $response->getData()['error']);
	}//end testCamelCaseGrantTypeStillWorks()

	/**
	 * The canonical spelling wins over a camelCase fallback that disagrees.
	 *
	 * @return void
	 */
	public function testCanonicalSpellingTakesPrecedenceOverTheFallback(): void {
		$controller = $this->controllerFor(['grant_type' => 'password']);

		$response = $controller->exchange(grantType: self::JWT_BEARER);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('unsupported_grant_type', $response->getData()['error']);
	}//end testCanonicalSpellingTakesPrecedenceOverTheFallback()

	/**
	 * An unsupported grant is still rejected — the negative control that proves
	 * these tests can fail.
	 *
	 * @return void
	 */
	public function testUnsupportedGrantTypeIsRejected(): void {
		$controller = $this->controllerFor(['grant_type' => 'password']);

		$response = $controller->exchange();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('unsupported_grant_type', $response->getData()['error']);
	}//end testUnsupportedGrantTypeIsRejected()

	/**
	 * A request with no grant type at all is rejected as unsupported.
	 *
	 * @return void
	 */
	public function testAbsentGrantTypeIsRejected(): void {
		$controller = $this->controllerFor([]);

		$response = $controller->exchange();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('unsupported_grant_type', $response->getData()['error']);
	}//end testAbsentGrantTypeIsRejected()

	/**
	 * A valid assertion is exchanged for the service's token payload.
	 *
	 * @return void
	 */
	public function testValidAssertionReturnsTheTokenPayload(): void {
		$service = $this->createMock(JwtAuthService::class);
		$service->method('exchangeAssertion')->willReturn(
			[
				'access_token' => 'opaque-token',
				'token_type' => 'Bearer',
				'expires_in' => 300,
			]
		);

		$controller = $this->controllerFor(
			['grant_type' => self::JWT_BEARER, 'assertion' => 'a.b.c'],
			$service
		);

		$response = $controller->exchange(assertion: 'a.b.c');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('opaque-token', $response->getData()['access_token']);
	}//end testValidAssertionReturnsTheTokenPayload()
}//end class
