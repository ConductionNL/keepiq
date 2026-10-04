<?php

/**
 * Refusals keep their status and their code through Nextcloud's OCS layer.
 *
 * Nextcloud's OCSMiddleware (lib/private/AppFramework/Middleware/OCSMiddleware.php)
 * rewrites every 401 and 403 response of an OCSController that is not already
 * an OCS response into an OCS v1 envelope, which on an /index.php/apps route
 * is HTTP 200 without the body's `error`. Measured live on Nextcloud 35.0.1
 * (keepiq lane O, 4 Oct 2026): 400, 409, 422 and 428 pass untouched, with or
 * without the OCS-APIRequest header. So a refused key proof, share or policy
 * write read as a success in the browser.
 *
 * The private OC classes are not on this test's autoload path, so the
 * dispatcher's order (afterException then afterController, both from the last
 * registered middleware to the first; app middlewares after the framework's)
 * and OCSMiddleware's rewrite rule are reproduced here, and the keepiq
 * middlewares themselves are the real classes. The live run that measured the
 * rule is recorded in the change's tasks (harden-vault-key-material-guards 6.5).
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

use OCA\Keepiq\Attribute\VaultKeyProofRequired;
use OCA\Keepiq\Db\SuiteMigrationMapper;
use OCA\Keepiq\Middleware\OcsRefusalMiddleware;
use OCA\Keepiq\Middleware\VaultKeyProofMiddleware;
use OCA\Keepiq\Service\EncryptionSuiteService;
use OCA\Keepiq\Service\VaultKeyProofService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * An OCSController fixture, the base class of most keepiq API controllers.
 */
class OcsRefusalFixtureController extends OCSController {
	#[VaultKeyProofRequired(purpose: 'share-new-recipient')]
	public function guarded(): JSONResponse {
		return new JSONResponse(data: ['ran' => true]);
	}

	public function plain(): JSONResponse {
		return new JSONResponse(data: ['ran' => true]);
	}
}//end class

/**
 * A plain Controller fixture.
 */
class PlainRefusalFixtureController extends Controller {
	public function plain(): JSONResponse {
		return new JSONResponse(data: ['ran' => true]);
	}
}//end class

/**
 * Stands in for a framework middleware (SecurityMiddleware and friends) that
 * turns its own exception into a 403 before any app code runs.
 */
class FrameworkRefusalFixtureMiddleware extends Middleware {
	public function afterException($controller, $methodName, Throwable $exception): Response {
		return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
	}
}//end class

/**
 * Tests for the refusal status keepiq hands to the OCS layer.
 */
class OcsRefusalPipelineTest extends TestCase {
	private IRequest $request;
	private VaultKeyProofService $proofService;
	private EncryptionSuiteService $suiteService;
	private IUserSession $userSession;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->proofService = $this->createMock(VaultKeyProofService::class);
		$this->suiteService = $this->createMock(EncryptionSuiteService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
	}//end setUp()

	/**
	 * Run one request through the middlewares the way Nextcloud's Dispatcher
	 * does, then apply OCSMiddleware's 401/403 rewrite (it is registered
	 * before every app middleware, so its afterController runs last).
	 *
	 * @param Controller $controller The controller
	 * @param string $method The method
	 * @param list<Middleware> $middlewares The app middlewares in registration order
	 * @param Throwable|null $frameworkException An exception a framework middleware raises before the controller
	 *
	 * @return Response What the browser receives
	 */
	private function dispatch(
		Controller $controller,
		string $method,
		array $middlewares,
		?Throwable $frameworkException = null,
		?Response $controllerResponse = null,
	): Response {
		$chain = $middlewares;
		if ($frameworkException !== null) {
			array_unshift($chain, new FrameworkRefusalFixtureMiddleware());
		}

		try {
			if ($frameworkException !== null) {
				throw $frameworkException;
			}

			foreach ($chain as $middleware) {
				$middleware->beforeController($controller, $method);
			}

			$response = $controllerResponse ?? $controller->$method();
		} catch (Throwable $exception) {
			$response = null;
			for ($i = count($chain) - 1; $i >= 0; $i--) {
				try {
					$response = $chain[$i]->afterException($controller, $method, $exception);
					break;
				} catch (Throwable $next) {
					$exception = $next;
				}
			}

			if ($response === null) {
				throw $exception;
			}
		}//end try

		for ($i = count($chain) - 1; $i >= 0; $i--) {
			$response = $chain[$i]->afterController($controller, $method, $response);
		}

		// OCSMiddleware::afterController, as measured live.
		if ($controller instanceof OCSController
			&& in_array($response->getStatus(), [Http::STATUS_UNAUTHORIZED, Http::STATUS_FORBIDDEN], true) === true
		) {
			$envelope = new JSONResponse(data: ['ocs' => ['meta' => ['statuscode' => $response->getStatus()]]]);
			$envelope->setStatus(Http::STATUS_OK);
			return $envelope;
		}

		return $response;
	}//end dispatch()

	/**
	 * The keepiq middlewares in the order PlatformIntegrationRegistrar registers them.
	 *
	 * @return list<Middleware>
	 */
	private function keepiqMiddlewares(): array {
		return [
			new VaultKeyProofMiddleware(
				request: $this->request,
				userSession: $this->userSession,
				suiteService: $this->suiteService,
				proofService: $this->proofService,
				migrationMapper: $this->createMock(SuiteMigrationMapper::class),
				logger: $this->createMock(LoggerInterface::class),
			),
			new OcsRefusalMiddleware(),
		];
	}//end keepiqMiddlewares()

	/**
	 * A request without a proof on an OCSController route reaches the browser
	 * as 428 with `error: key_proof_required`, and the controller never runs.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/harden-vault-key-material-guards/tasks.md#task-6.5
	 */
	public function testAMissingProofReachesTheBrowserAs428(): void {
		$this->suiteService->method('getActiveSuite')->willThrowException(new RuntimeException('no suite'));

		$response = $this->dispatch(
			controller: new OcsRefusalFixtureController('keepiq', $this->request),
			method: 'guarded',
			middlewares: $this->keepiqMiddlewares(),
		);

		$this->assertSame(428, $response->getStatus());
		$this->assertSame('key_proof_required', $response->getData()['error']);
		$this->assertArrayNotHasKey('ran', $response->getData());
	}//end testAMissingProofReachesTheBrowserAs428()

	/**
	 * A controller's own 403 with a policy code reaches the browser as 428
	 * with that code as `error`, the `code` and the message kept.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/vault-policies/spec.md#requirement-work-logins-are-kept-in-team-folders
	 */
	public function testAControllerRefusalWithACodeReachesTheBrowserAs428(): void {
		$refusal = new JSONResponse(
			data: ['message' => 'Save work logins in a team folder', 'code' => 'org_ownership_required'],
			statusCode: Http::STATUS_FORBIDDEN
		);

		$response = $this->dispatch(
			controller: new OcsRefusalFixtureController('keepiq', $this->request),
			method: 'plain',
			middlewares: $this->keepiqMiddlewares(),
			controllerResponse: $refusal,
		);

		$this->assertSame(428, $response->getStatus());
		$this->assertSame(
			[
				'message' => 'Save work logins in a team folder',
				'code' => 'org_ownership_required',
				'error' => 'org_ownership_required',
			],
			$response->getData()
		);
	}//end testAControllerRefusalWithACodeReachesTheBrowserAs428()

	/**
	 * A 403 without a code still reaches the browser as a refusal, `error: forbidden`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-only-the-owner-governs-managers-and-the-folder-itself
	 */
	public function testAPlainControllerRefusalReachesTheBrowserAs428(): void {
		$response = $this->dispatch(
			controller: new OcsRefusalFixtureController('keepiq', $this->request),
			method: 'plain',
			middlewares: $this->keepiqMiddlewares(),
			controllerResponse: new JSONResponse(data: ['message' => 'Not yours'], statusCode: Http::STATUS_FORBIDDEN),
		);

		$this->assertSame(428, $response->getStatus());
		$this->assertSame('forbidden', $response->getData()['error']);
		$this->assertSame('Not yours', $response->getData()['message']);
	}//end testAPlainControllerRefusalReachesTheBrowserAs428()

	/**
	 * A refusal Nextcloud itself raises (not an admin, password confirmation)
	 * is Nextcloud's contract and is left alone.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/harden-vault-key-material-guards/tasks.md#task-6.5
	 */
	public function testAFrameworkRefusalIsLeftToNextcloud(): void {
		$middlewares = $this->keepiqMiddlewares();
		$response = $this->dispatch(
			controller: new OcsRefusalFixtureController('keepiq', $this->request),
			method: 'plain',
			middlewares: $middlewares,
			frameworkException: new RuntimeException('Logged in account must be an admin'),
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertArrayHasKey('ocs', $response->getData());
	}//end testAFrameworkRefusalIsLeftToNextcloud()

	/**
	 * A plain Controller already delivers its 403; it is not touched.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/harden-vault-key-material-guards/tasks.md#task-6.5
	 */
	public function testAPlainControllerKeepsItsOwn403(): void {
		$response = $this->dispatch(
			controller: new PlainRefusalFixtureController('keepiq', $this->request),
			method: 'plain',
			middlewares: $this->keepiqMiddlewares(),
			controllerResponse: new JSONResponse(data: ['message' => 'Not yours'], statusCode: Http::STATUS_FORBIDDEN),
		);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertArrayNotHasKey('error', $response->getData());
	}//end testAPlainControllerKeepsItsOwn403()

	/**
	 * Other statuses pass through unchanged.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/harden-vault-key-material-guards/tasks.md#task-6.5
	 */
	public function testOtherStatusesAreUnchanged(): void {
		foreach ([Http::STATUS_OK, Http::STATUS_BAD_REQUEST, Http::STATUS_NOT_FOUND, Http::STATUS_CONFLICT] as $status) {
			$response = $this->dispatch(
				controller: new OcsRefusalFixtureController('keepiq', $this->request),
				method: 'plain',
				middlewares: $this->keepiqMiddlewares(),
				controllerResponse: new JSONResponse(data: ['message' => 'x'], statusCode: $status),
			);
			$this->assertSame($status, $response->getStatus());
			$this->assertSame(['message' => 'x'], $response->getData());
		}
	}//end testOtherStatusesAreUnchanged()
}//end class
