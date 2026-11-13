<?php

/**
 * Unit tests for OcsRefusalMiddleware.
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

use OCA\Keepiq\Middleware\OcsRefusalMiddleware;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * afterController in isolation: which responses are rewritten from 403
 * to 428 and how their body is normalised to carry an `error`.
 */
#[CoversClass(OcsRefusalMiddleware::class)]
class OcsRefusalMiddlewareTest extends TestCase {
	private OcsRefusalMiddleware $middleware;
	private OCSController $ocs;

	protected function setUp(): void {
		$this->middleware = new OcsRefusalMiddleware();
		$this->ocs = $this->createMock(OCSController::class);
	}

	public function testTheRefusalStatusIs428(): void {
		$this->assertSame(Http::STATUS_PRECONDITION_REQUIRED, OcsRefusalMiddleware::REFUSAL_STATUS);
	}

	public function testAForbiddenBodyWithoutErrorGetsForbidden(): void {
		$response = new JSONResponse(['message' => 'No'], Http::STATUS_FORBIDDEN);

		$result = $this->middleware->afterController($this->ocs, 'm', $response);

		$this->assertSame($response, $result);
		$this->assertSame(428, $result->getStatus());
		$this->assertSame(['message' => 'No', 'error' => 'forbidden'], $result->getData());
	}

	public function testACodeBecomesTheError(): void {
		$response = new JSONResponse(['code' => 'not_owner', 'message' => 'No'], Http::STATUS_FORBIDDEN);

		$result = $this->middleware->afterController($this->ocs, 'm', $response);

		$this->assertSame(['code' => 'not_owner', 'message' => 'No', 'error' => 'not_owner'], $result->getData());
	}

	public function testAnExistingErrorIsKept(): void {
		$response = new JSONResponse(['error' => 'locked', 'code' => 'x'], Http::STATUS_FORBIDDEN);

		$result = $this->middleware->afterController($this->ocs, 'm', $response);

		$this->assertSame(['error' => 'locked', 'code' => 'x'], $result->getData());
		$this->assertSame(428, $result->getStatus());
	}

	public function testAScalarBodyBecomesTheMessage(): void {
		$response = new JSONResponse('Denied', Http::STATUS_FORBIDDEN);

		$result = $this->middleware->afterController($this->ocs, 'm', $response);

		$this->assertSame(['message' => 'Denied', 'error' => 'forbidden'], $result->getData());
	}

	public function testOtherStatusesPassUntouched(): void {
		$response = new JSONResponse(['message' => 'gone'], Http::STATUS_NOT_FOUND);

		$this->middleware->afterController($this->ocs, 'm', $response);

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['message' => 'gone'], $response->getData());
	}

	public function testAPlainControllerKeepsIts403(): void {
		$response = new JSONResponse(['message' => 'No'], Http::STATUS_FORBIDDEN);

		$this->middleware->afterController($this->createMock(Controller::class), 'm', $response);

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['message' => 'No'], $response->getData());
	}

	public function testANonJsonResponseKeepsIts403(): void {
		$response = new DataResponse(['message' => 'No'], Http::STATUS_FORBIDDEN);

		$this->middleware->afterController($this->ocs, 'm', $response);

		$this->assertSame(403, $response->getStatus());
	}
}
