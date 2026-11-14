<?php

/**
 * The middleware registrar registers the API contract middleware on its own
 * and the platform middleware in a fixed order.
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

use OCA\Keepiq\Middleware\ApiContractMiddleware;
use OCA\Keepiq\Middleware\ApiVersionMiddleware;
use OCA\Keepiq\Middleware\JwtAuthMiddleware;
use OCA\Keepiq\Middleware\MiddlewareRegistrar;
use OCA\Keepiq\Middleware\OcsRefusalMiddleware;
use OCA\Keepiq\Middleware\ShareScanPendingMiddleware;
use OCA\Keepiq\Middleware\VaultKeyProofMiddleware;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the middleware registrar.
 */
#[CoversClass(MiddlewareRegistrar::class)]
class MiddlewareRegistrarTest extends TestCase {

	/**
	 * The middleware classes registered, in order.
	 *
	 * @var list<string>
	 */
	private array $registered = [];

	/**
	 * A registration context that records every registered middleware and
	 * refuses any other registration.
	 *
	 * @return IRegistrationContext
	 */
	private function context(): IRegistrationContext {
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerMiddleware')->willReturnCallback(
			function (string $class, bool $global = false): void {
				$this->assertFalse(condition: $global, message: $class . ' must not be registered globally');
				$this->registered[] = $class;
			}
		);
		$context->expects($this->never())->method('registerService');
		$context->expects($this->never())->method('registerEventListener');

		return $context;
	}//end context()

	/**
	 * The API contract middleware is registered alone.
	 *
	 * @return void
	 */
	public function testTheApiContractMiddlewareIsRegisteredAlone(): void {
		(new MiddlewareRegistrar())->registerApiContract(context: $this->context());

		$this->assertSame(expected: [ApiContractMiddleware::class], actual: $this->registered);
	}//end testTheApiContractMiddlewareIsRegisteredAlone()

	/**
	 * The platform middleware is registered in a fixed order, without the
	 * API contract middleware.
	 *
	 * @return void
	 */
	public function testThePlatformMiddlewareIsRegisteredInOrder(): void {
		(new MiddlewareRegistrar())->registerPlatformMiddleware(context: $this->context());

		$this->assertSame(
			expected: [
				JwtAuthMiddleware::class,
				VaultKeyProofMiddleware::class,
				OcsRefusalMiddleware::class,
				ShareScanPendingMiddleware::class,
				ApiVersionMiddleware::class,
			],
			actual: $this->registered
		);
	}//end testThePlatformMiddlewareIsRegisteredInOrder()
}//end class
