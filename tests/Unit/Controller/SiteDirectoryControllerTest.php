<?php

/**
 * Unit tests for SiteDirectoryController.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Controller;

use OCA\Keepiq\Controller\SiteDirectoryController;
use OCA\Keepiq\Service\SiteDirectoryService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * GET /api/v1/site-directory: 404 while off, the copy with an ETag while on.
 */
#[CoversClass(SiteDirectoryController::class)]
class SiteDirectoryControllerTest extends TestCase {

	private const COPY = '{"fetchedAt":"2026-11-14T10:00:00+00:00","totp":{"github.com":""},"passkeys":{}}';

	/** @var IRequest&MockObject */
	private IRequest $request;

	/** @var SiteDirectoryService&MockObject */
	private SiteDirectoryService $directory;

	/** @var IUserSession&MockObject */
	private IUserSession $userSession;

	private SiteDirectoryController $controller;

	/**
	 * Wire the controller over mocks with a signed-in user.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request     = $this->createMock(IRequest::class);
		$this->directory   = $this->createMock(SiteDirectoryService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->controller  = new SiteDirectoryController(
			request: $this->request,
			directory: $this->directory,
			userSession: $this->userSession,
		);
	}//end setUp()

	/**
	 * Sign a user in.
	 *
	 * @return void
	 */
	private function signedIn(): void {
		$this->userSession->method('getUser')->willReturn($this->createMock(IUser::class));
	}//end signedIn()

	/**
	 * Off by default: 404, and the stored copy is not even read.
	 *
	 * @return void
	 */
	public function testSwitchedOffIsNotFound(): void {
		$this->signedIn();
		$this->directory->method('isEnabled')->willReturn(false);
		$this->directory->expects($this->never())->method('current');

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testSwitchedOffIsNotFound()

	/**
	 * Switched on before the first refresh: 404 as well.
	 *
	 * @return void
	 */
	public function testNoCopyYetIsNotFound(): void {
		$this->signedIn();
		$this->directory->method('isEnabled')->willReturn(true);
		$this->directory->method('current')->willReturn(null);

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->index()->getStatus());
	}//end testNoCopyYetIsNotFound()

	/**
	 * The copy is served as JSON with its hash as the ETag.
	 *
	 * @return void
	 */
	public function testTheCopyIsServedWithAnEtag(): void {
		$this->signedIn();
		$this->directory->method('isEnabled')->willReturn(true);
		$this->directory->method('current')->willReturn(self::COPY);
		$this->request->method('getHeader')->with('If-None-Match')->willReturn('');

		$response = $this->controller->index();

		$this->assertInstanceOf(DataDisplayResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(self::COPY, $response->render());
		// getHeaders() adds the server's CSP, which needs a running Nextcloud; read the response's own headers.
		$this->assertSame('application/json', (new ReflectionProperty(Response::class, 'headers'))->getValue($response)['Content-Type']);
		$this->assertSame(hash('sha256', self::COPY), $response->getETag());
	}//end testTheCopyIsServedWithAnEtag()

	/**
	 * A browser that holds the copy gets 304 without a body.
	 *
	 * @return void
	 */
	public function testAMatchingEtagIsNotModified(): void {
		$this->signedIn();
		$this->directory->method('isEnabled')->willReturn(true);
		$this->directory->method('current')->willReturn(self::COPY);
		$this->request->method('getHeader')->with('If-None-Match')->willReturn('"' . hash('sha256', self::COPY) . '"');

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_NOT_MODIFIED, $response->getStatus());
		$this->assertNotInstanceOf(DataDisplayResponse::class, $response);
	}//end testAMatchingEtagIsNotModified()

	/**
	 * Without a user: 401 and nothing is read.
	 *
	 * @return void
	 */
	public function testWithoutAUserIsUnauthorized(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->directory->expects($this->never())->method('isEnabled');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->index()->getStatus());
	}//end testWithoutAUserIsUnauthorized()

	/**
	 * Every signed-in user may read the public list; the route takes no parameter.
	 *
	 * @return void
	 */
	public function testTheRouteIsForEverySignedInUserAndTakesNothing(): void {
		$method = new ReflectionMethod(SiteDirectoryController::class, 'index');

		$this->assertCount(1, $method->getAttributes(NoAdminRequired::class));
		$this->assertSame(0, $method->getNumberOfParameters());
	}//end testTheRouteIsForEverySignedInUserAndTakesNothing()
}//end class
