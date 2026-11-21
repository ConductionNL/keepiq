<?php

/**
 * Unit tests for the refusal paths of AdminApplicationController.
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
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Keepiq\Controller\AdminApplicationController;
use OCA\Keepiq\Db\ApplicationMapper;
use OCA\Keepiq\Service\ApplicationService;
use OCA\Keepiq\Service\LeaseService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A service refusal on create and reject becomes a 400 carrying the service's message.
 */
#[CoversClass(AdminApplicationController::class)]
class AdminApplicationControllerRefusalTest extends TestCase {
	/** @var ApplicationService&MockObject */
	private ApplicationService $applications;

	/**
	 * Fresh doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->applications = $this->createMock(ApplicationService::class);
	}//end setUp()

	/**
	 * The controller, signed in as `admin`.
	 *
	 * @return AdminApplicationController
	 */
	private function controller(): AdminApplicationController {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new AdminApplicationController(
			request: $this->createStub(IRequest::class),
			applications: $this->applications,
			leases: $this->createStub(LeaseService::class),
			applicationMapper: $this->createStub(ApplicationMapper::class),
			userSession: $session,
		);
	}//end controller()

	/**
	 * A register refusal (bad type, bad CSR) is a 400 with the reason.
	 *
	 * @return void
	 */
	public function testCreateReturnsBadRequestWhenRegisterRefuses(): void {
		$this->applications->expects($this->once())
			->method('register')
			->with('ci-runner', 'builds', 'bogus', null, 'admin', true)
			->willThrowException(new InvalidArgumentException('Unknown application type'));

		$response = $this->controller()->create(name: 'ci-runner', description: 'builds', type: 'bogus');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'Unknown application type'], $response->getData());
	}//end testCreateReturnsBadRequestWhenRegisterRefuses()

	/**
	 * A reject refusal (not pending) is a 400 with the reason, not the rejected status.
	 *
	 * @return void
	 */
	public function testRejectReturnsBadRequestWhenTheServiceRefuses(): void {
		$this->applications->expects($this->once())
			->method('reject')
			->with('app-9', 'admin', true)
			->willThrowException(new InvalidArgumentException('Application is not pending'));

		$response = $this->controller()->reject(id: 'app-9');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'Application is not pending'], $response->getData());
	}//end testRejectReturnsBadRequestWhenTheServiceRefuses()
}//end class
