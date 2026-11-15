<?php

/**
 * Contract tests for the application registration lifecycle endpoints.
 *
 * Every endpoint asks the admin area authorizer whether the SESSION user holds
 * the applications area and passes that answer to ApplicationService, which
 * owns the decision. The controller's own contract: an anonymous caller is
 * refused (registration only when the admin opted in), a blank name is a 400,
 * and a refusal reads 403 for a non-admin but 400 for an admin.
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

use InvalidArgumentException;
use OCA\Keepiq\Controller\ApplicationController;
use OCA\Keepiq\Db\Application;
use OCA\Keepiq\Service\AdminAreaAuthorizer;
use OCA\Keepiq\Service\ApplicationService;
use OCA\Keepiq\Settings\ApplicationAdminSettings;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ApplicationControllerLifecycleTest extends TestCase {

	/**
	 * The mocked application service.
	 *
	 * @var ApplicationService&MockObject
	 */
	private ApplicationService&MockObject $service;

	/**
	 * The mocked user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * The mocked admin area authorizer.
	 *
	 * @var AdminAreaAuthorizer&MockObject
	 */
	private AdminAreaAuthorizer&MockObject $areas;

	/**
	 * The mocked app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * The controller under test.
	 *
	 * @var ApplicationController
	 */
	private ApplicationController $controller;

	/**
	 * Build the controller over mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(originalClassName: ApplicationService::class);
		$this->session = $this->createMock(originalClassName: IUserSession::class);
		$this->areas = $this->createMock(originalClassName: AdminAreaAuthorizer::class);
		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->controller = new ApplicationController(
			request: $this->createMock(originalClassName: IRequest::class),
			service: $this->service,
			session: $this->session,
			areas: $this->areas,
			appConfig: $this->appConfig,
		);
	}//end setUp()

	/**
	 * Sign a user in, holding the applications area or not.
	 *
	 * @param bool $isAdmin Whether the user holds the applications area.
	 *
	 * @return void
	 */
	private function signIn(bool $isAdmin): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->session->method('getUser')->willReturn($user);
		$this->areas->method('holds')->with('alice', ApplicationAdminSettings::class)->willReturn($isAdmin);
	}//end signIn()

	/**
	 * An application entity.
	 *
	 * @param string $name The application name.
	 *
	 * @return Application
	 */
	private function application(string $name): Application {
		$application = new Application();
		$application->setId('app-1');
		$application->setName($name);
		return $application;
	}//end application()

	/**
	 * Every session endpoint, called with valid arguments.
	 *
	 * @return array<string, array{0: callable(ApplicationController): mixed}>
	 */
	public static function sessionEndpoints(): array {
		return [
			'index'   => [static fn (ApplicationController $c) => $c->index()],
			'pending' => [static fn (ApplicationController $c) => $c->pending()],
			'show'    => [static fn (ApplicationController $c) => $c->show(id: 'app-1')],
			'approve' => [static fn (ApplicationController $c) => $c->approve(id: 'app-1')],
			'reject'  => [static fn (ApplicationController $c) => $c->reject(id: 'app-1')],
			'destroy' => [static fn (ApplicationController $c) => $c->destroy(id: 'app-1')],
		];
	}//end sessionEndpoints()

	/**
	 * An anonymous caller is refused before the service is asked anything.
	 *
	 * @param callable $call The endpoint call.
	 *
	 * @dataProvider sessionEndpoints
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefused(callable $call): void {
		$this->session->method('getUser')->willReturn(null);
		foreach (['listForUser', 'listPending', 'get', 'approve', 'reject', 'delete'] as $method) {
			$this->service->expects($this->never())->method($method);
		}

		$response = $call($this->controller);

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
	}//end testAnAnonymousCallerIsRefused()

	/**
	 * The index passes the area answer through to the listing.
	 *
	 * @return void
	 */
	public function testIndexListsWithTheCallersAreaAnswer(): void {
		$this->signIn(isAdmin: true);
		$this->service->expects($this->once())->method('listForUser')->with('alice', true)
			->willReturn([$this->application(name: 'CI runner')]);

		$data = $this->controller->index()->getData();

		$this->assertSame(expected: 'CI runner', actual: $data[0]['name']);
	}//end testIndexListsWithTheCallersAreaAnswer()

	/**
	 * The pending queue answers 403 when the service refuses a non-admin.
	 *
	 * @return void
	 */
	public function testPendingIsRefusedForANonAdmin(): void {
		$this->signIn(isAdmin: false);
		$this->service->method('listPending')->with(false)
			->willThrowException(new InvalidArgumentException(message: 'Admin only'));

		$response = $this->controller->pending();

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Admin only'], actual: $response->getData());
	}//end testPendingIsRefusedForANonAdmin()

	/**
	 * The pending queue lists for an admin.
	 *
	 * @return void
	 */
	public function testPendingListsForAnAdmin(): void {
		$this->signIn(isAdmin: true);
		$this->service->method('listPending')->with(true)->willReturn([$this->application(name: 'Waiting')]);

		$this->assertSame(expected: 'Waiting', actual: $this->controller->pending()->getData()[0]['name']);
	}//end testPendingListsForAnAdmin()

	/**
	 * Show reads within the caller's rights; an unknown or foreign id reads 404.
	 *
	 * @return void
	 */
	public function testShowReadsWithinTheCallersRights(): void {
		$this->signIn(isAdmin: false);
		$this->service->method('get')->willReturnCallback(
			function (string $id, string $uid, bool $isAdmin): Application {
				if ($id !== 'app-1' || $uid !== 'alice' || $isAdmin === true) {
					throw new InvalidArgumentException(message: 'Application not found');
				}

				return $this->application(name: 'Mine');
			}
		);

		$this->assertSame(expected: 'Mine', actual: $this->controller->show(id: 'app-1')->getData()['name']);
		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $this->controller->show(id: 'app-2')->getStatus());
	}//end testShowReadsWithinTheCallersRights()

	/**
	 * Blank names, which must answer 400 before anything else.
	 *
	 * @return array<string, array{0: string|null}>
	 */
	public static function blankNames(): array {
		return [
			'missing'    => [null],
			'empty'      => [''],
			'whitespace' => ['   '],
		];
	}//end blankNames()

	/**
	 * A blank name answers 400 without registering.
	 *
	 * @param string|null $name The submitted name.
	 *
	 * @dataProvider blankNames
	 *
	 * @return void
	 */
	public function testCreateWithABlankNameAnswers400(?string $name): void {
		$this->service->expects($this->never())->method('register');

		$response = $this->controller->create(name: $name);

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'name is required'], actual: $response->getData());
	}//end testCreateWithABlankNameAnswers400()

	/**
	 * Anonymous registration is refused unless the admin enabled it.
	 *
	 * @return void
	 */
	public function testAnonymousRegistrationIsRefusedByDefault(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->appConfig->method('getValueString')
			->with('keepiq', 'anonymous_application_registration_enabled', '0')->willReturn('0');
		$this->service->expects($this->never())->method('register');

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $this->controller->create(name: 'Bot')->getStatus());
	}//end testAnonymousRegistrationIsRefusedByDefault()

	/**
	 * An enabled anonymous registration carries no user and is never admin.
	 *
	 * @return void
	 */
	public function testAnEnabledAnonymousRegistrationIsNeverAdmin(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->appConfig->method('getValueString')->willReturn('1');
		$this->areas->expects($this->never())->method('holds');
		$this->service->expects($this->once())->method('register')
			->with('Bot', null, Application::TYPE_EXTERNAL, 'CSR', null, false)
			->willReturn($this->application(name: 'Bot'));

		$response = $this->controller->create(name: 'Bot', csr: 'CSR');

		$this->assertSame(expected: Http::STATUS_CREATED, actual: $response->getStatus());
	}//end testAnEnabledAnonymousRegistrationIsNeverAdmin()

	/**
	 * A signed-in registration carries the user and the area answer; a refusal is 400.
	 *
	 * @return void
	 */
	public function testASignedInRegistrationCarriesTheUserAndAreaAnswer(): void {
		$this->signIn(isAdmin: true);
		$this->service->method('register')->willReturnCallback(
			function (string $name, ?string $description, string $type, ?string $csr, ?string $userId, bool $isAdmin): Application {
				if ($userId !== 'alice' || $isAdmin === false || $type !== Application::TYPE_INTERNAL) {
					throw new InvalidArgumentException(message: 'Invalid CSR');
				}

				return $this->application(name: $name);
			}
		);

		$created = $this->controller->create(name: 'Sync', description: 'Nightly', type: Application::TYPE_INTERNAL);
		$refused = $this->controller->create(name: 'Sync', type: Application::TYPE_EXTERNAL);

		$this->assertSame(expected: Http::STATUS_CREATED, actual: $created->getStatus());
		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $refused->getStatus());
		$this->assertSame(expected: ['message' => 'Invalid CSR'], actual: $refused->getData());
	}//end testASignedInRegistrationCarriesTheUserAndAreaAnswer()

	/**
	 * Approve, reject and destroy: a refusal reads 403 for a non-admin and 400 for an admin.
	 *
	 * @return array<string, array{0: string, 1: bool, 2: int}>
	 */
	public static function refusedWrites(): array {
		return [
			'approve by non-admin' => ['approve', false, Http::STATUS_FORBIDDEN],
			'approve by admin'     => ['approve', true, Http::STATUS_BAD_REQUEST],
			'reject by non-admin'  => ['reject', false, Http::STATUS_FORBIDDEN],
			'reject by admin'      => ['reject', true, Http::STATUS_BAD_REQUEST],
			'destroy by non-admin' => ['destroy', false, Http::STATUS_FORBIDDEN],
			'destroy by admin'     => ['destroy', true, Http::STATUS_BAD_REQUEST],
		];
	}//end refusedWrites()

	/**
	 * A refused write maps to 403 or 400 by the caller's area.
	 *
	 * @param string $endpoint The controller method.
	 * @param bool   $isAdmin  Whether the caller holds the area.
	 * @param int    $status   The expected status.
	 *
	 * @dataProvider refusedWrites
	 *
	 * @return void
	 */
	public function testARefusedWriteMapsByTheCallersArea(string $endpoint, bool $isAdmin, int $status): void {
		$this->signIn(isAdmin: $isAdmin);
		$serviceMethod = $endpoint;
		if ($endpoint === 'destroy') {
			$serviceMethod = 'delete';
		}

		$this->service->method($serviceMethod)->willThrowException(new InvalidArgumentException(message: 'Refused'));

		$response = $this->controller->$endpoint(id: 'app-1');

		$this->assertSame(expected: $status, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Refused'], actual: $response->getData());
	}//end testARefusedWriteMapsByTheCallersArea()

	/**
	 * Reject and destroy name the application they acted on.
	 *
	 * @return void
	 */
	public function testRejectAndDestroyConfirmTheApplication(): void {
		$this->signIn(isAdmin: true);
		$this->service->expects($this->once())->method('reject')->with('app-1', 'alice', true);
		$this->service->expects($this->once())->method('delete')->with('app-1', true);

		$this->assertSame(expected: ['status' => 'rejected', 'id' => 'app-1'], actual: $this->controller->reject(id: 'app-1')->getData());
		$this->assertSame(expected: ['status' => 'deleted', 'id' => 'app-1'], actual: $this->controller->destroy(id: 'app-1')->getData());
	}//end testRejectAndDestroyConfirmTheApplication()
}//end class
