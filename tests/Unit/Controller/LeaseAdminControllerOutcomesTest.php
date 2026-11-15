<?php

/**
 * Outcome tests for LeaseAdminController.
 *
 * An application's leases and policy are visible to an applications admin
 * and to the user who registered the application; everyone else, and every
 * unknown id, gets the same 404. Only an applications admin may change the
 * policy.
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
use OCA\Keepiq\Controller\LeaseAdminController;
use OCA\Keepiq\Db\Application;
use OCA\Keepiq\Db\ApplicationMapper;
use OCA\Keepiq\Db\MachineLease;
use OCA\Keepiq\Db\MachineLeaseMapper;
use OCA\Keepiq\Service\AdminAreaAuthorizer;
use OCA\Keepiq\Service\LeaseService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Keepiq\Controller\LeaseAdminController
 * @uses   \OCA\Keepiq\Db\Application
 * @uses   \OCA\Keepiq\Db\MachineLease
 */
class LeaseAdminControllerOutcomesTest extends TestCase {

	/**
	 * The mocked lease service.
	 *
	 * @var LeaseService&MockObject
	 */
	private LeaseService&MockObject $leases;

	/**
	 * The mocked lease mapper.
	 *
	 * @var MachineLeaseMapper&MockObject
	 */
	private MachineLeaseMapper&MockObject $leaseMapper;

	/**
	 * The mocked application mapper.
	 *
	 * @var ApplicationMapper&MockObject
	 */
	private ApplicationMapper&MockObject $applications;

	/**
	 * The mocked user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * The mocked admin-area authorizer.
	 *
	 * @var AdminAreaAuthorizer&MockObject
	 */
	private AdminAreaAuthorizer&MockObject $areas;

	/**
	 * Build fresh mocks for every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->leases = $this->createMock(LeaseService::class);
		$this->leaseMapper = $this->createMock(MachineLeaseMapper::class);
		$this->applications = $this->createMock(ApplicationMapper::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->areas = $this->createMock(AdminAreaAuthorizer::class);
	}//end setUp()

	/**
	 * The controller under test.
	 *
	 * @return LeaseAdminController
	 */
	private function controller(): LeaseAdminController {
		return new LeaseAdminController(
			request: $this->createMock(IRequest::class),
			leaseService: $this->leases,
			leaseMapper: $this->leaseMapper,
			applicationMapper: $this->applications,
			userSession: $this->session,
			areas: $this->areas,
		);
	}//end controller()

	/**
	 * Sign in as the given user, an applications admin or not.
	 *
	 * @param string $uid   The user id
	 * @param bool   $admin Whether the user holds the applications area
	 *
	 * @return void
	 */
	private function signIn(string $uid, bool $admin): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->session->method('getUser')->willReturn($user);
		$this->areas->method('holds')->with($uid, AdminAreaAuthorizer::APPLICATIONS)->willReturn($admin);
	}//end signIn()

	/**
	 * An application registered by the given user.
	 *
	 * @param string|null $registeredBy The registering user
	 *
	 * @return Application
	 */
	private function application(?string $registeredBy): Application {
		$application = new Application();
		$application->setRegisteredBy($registeredBy);
		return $application;
	}//end application()

	/**
	 * A lease of the given application.
	 *
	 * @param string $applicationId The application id
	 *
	 * @return MachineLease
	 */
	private function lease(string $applicationId): MachineLease {
		$lease = new MachineLease();
		$lease->setApplicationId($applicationId);
		return $lease;
	}//end lease()

	/**
	 * Every action refuses an anonymous caller with 401.
	 *
	 * @return void
	 */
	public function testEveryActionRefusesAnAnonymousCaller(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->leases->expects($this->never())->method($this->anything());

		foreach ([
			$this->controller()->index(id: 'app-1'),
			$this->controller()->revoke(leaseId: 'l-1'),
			$this->controller()->getPolicy(id: 'app-1'),
			$this->controller()->setPolicy(id: 'app-1', defaultTtl: 60),
		] as $response) {
			$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
			$this->assertSame(['message' => 'Unauthorized'], $response->getData());
		}
	}//end testEveryActionRefusesAnAnonymousCaller()

	/**
	 * The registering user sees the application's leases.
	 *
	 * @return void
	 */
	public function testTheRegisteringUserSeesTheLeases(): void {
		$this->signIn('alice', false);
		$this->applications->method('findById')->with('app-1')->willReturn($this->application('alice'));
		$this->leases->expects($this->once())->method('listForApplication')->with('app-1')
			->willReturn([$this->lease('app-1'), $this->lease('app-1')]);

		$response = $this->controller()->index(id: 'app-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(2, $response->getData());
		$this->assertSame('app-1', $response->getData()[0]['applicationId']);
	}//end testTheRegisteringUserSeesTheLeases()

	/**
	 * Another user's application and an unknown one answer the same 404.
	 *
	 * @return void
	 */
	public function testAnotherUsersOrAnUnknownApplicationAnswers404(): void {
		$this->signIn('mallory', false);
		$this->applications->method('findById')->willReturnOnConsecutiveCalls(
			$this->application('alice'),
			$this->throwException(new DoesNotExistException('gone')),
		);
		$this->leases->expects($this->never())->method('listForApplication');

		$foreign = $this->controller()->index(id: 'app-1');
		$unknown = $this->controller()->index(id: 'app-2');

		$this->assertSame(Http::STATUS_NOT_FOUND, $foreign->getStatus());
		$this->assertSame(['message' => 'Not found'], $foreign->getData());
		$this->assertSame(Http::STATUS_NOT_FOUND, $unknown->getStatus());
	}//end testAnotherUsersOrAnUnknownApplicationAnswers404()

	/**
	 * An admin revokes any lease, as the actor.
	 *
	 * @return void
	 */
	public function testAnAdminRevokesALeaseAsTheActor(): void {
		$this->signIn('admin', true);
		$lease = $this->lease('app-1');
		$revoked = $this->lease('app-1');
		$revoked->setRevokedBy('admin');
		$this->leaseMapper->method('findById')->with('l-1')->willReturn($lease);
		$this->applications->expects($this->never())->method('findById');
		$this->leases->expects($this->once())->method('revoke')->with($lease, 'admin')->willReturn($revoked);

		$response = $this->controller()->revoke(leaseId: 'l-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($revoked->jsonSerialize(), $response->getData());
	}//end testAnAdminRevokesALeaseAsTheActor()

	/**
	 * An unknown lease, or one of another user's application, is not revoked.
	 *
	 * @return void
	 */
	public function testAnUnknownOrForeignLeaseIsNotRevoked(): void {
		$this->signIn('mallory', false);
		$this->leaseMapper->method('findById')->willReturnOnConsecutiveCalls(
			$this->throwException(new DoesNotExistException('gone')),
			$this->lease('app-1'),
		);
		$this->applications->method('findById')->willReturn($this->application('alice'));
		$this->leases->expects($this->never())->method('revoke');

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->revoke(leaseId: 'l-1')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->revoke(leaseId: 'l-2')->getStatus());
	}//end testAnUnknownOrForeignLeaseIsNotRevoked()

	/**
	 * The registering user sees the policy but may not edit it.
	 *
	 * @return void
	 */
	public function testThePolicyViewTellsTheOwnerItMayNotEdit(): void {
		$this->signIn('alice', false);
		$this->applications->method('findById')->with('app-1')->willReturn($this->application('alice'));
		$this->leases->method('policyView')->with('app-1')->willReturn(['defaultTtl' => 60]);

		$response = $this->controller()->getPolicy(id: 'app-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['defaultTtl' => 60, 'canEdit' => false], $response->getData());
	}//end testThePolicyViewTellsTheOwnerItMayNotEdit()

	/**
	 * An admin asking for an unknown application's policy gets a 404.
	 *
	 * @return void
	 */
	public function testAnAdminGetsA404ForAnUnknownApplicationsPolicy(): void {
		$this->signIn('admin', true);
		$this->applications->method('findById')->willThrowException(new DoesNotExistException('gone'));
		$this->leases->expects($this->never())->method('policyView');

		$response = $this->controller()->getPolicy(id: 'app-9');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testAnAdminGetsA404ForAnUnknownApplicationsPolicy()

	/**
	 * A user who is not an applications admin may not set the policy, even
	 * for their own application.
	 *
	 * @return void
	 */
	public function testANonAdminMayNotSetThePolicy(): void {
		$this->signIn('alice', false);
		$this->applications->method('findById')->willReturn($this->application('alice'));
		$this->leases->expects($this->never())->method('setPolicyOverride');

		$response = $this->controller()->setPolicy(id: 'app-1', defaultTtl: 60);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testANonAdminMayNotSetThePolicy()

	/**
	 * An admin sets the override and gets the effective policy back.
	 *
	 * @return void
	 */
	public function testAnAdminSetsTheOverrideAndGetsTheEffectivePolicy(): void {
		$this->signIn('admin', true);
		$this->applications->method('findById')->with('app-1')->willReturn($this->application('alice'));
		$this->leases->expects($this->once())->method('setPolicyOverride')->with('app-1', 60, 3600, false);
		$this->leases->method('effectivePolicy')->with('app-1')->willReturn(['defaultTtl' => 60, 'maxTtl' => 3600]);

		$response = $this->controller()->setPolicy(id: 'app-1', defaultTtl: 60, maxTtl: 3600, renewable: false);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['defaultTtl' => 60, 'maxTtl' => 3600], $response->getData());
	}//end testAnAdminSetsTheOverrideAndGetsTheEffectivePolicy()

	/**
	 * An unknown application answers 404 and an invalid override 400.
	 *
	 * @return void
	 */
	public function testSetPolicyMapsUnknownTo404AndInvalidTo400(): void {
		$this->signIn('admin', true);
		$this->applications->method('findById')->willReturnOnConsecutiveCalls(
			$this->throwException(new DoesNotExistException('gone')),
			$this->application('alice'),
		);
		$this->leases->method('setPolicyOverride')->willThrowException(new InvalidArgumentException('maxTtl below defaultTtl'));

		$unknown = $this->controller()->setPolicy(id: 'app-9', defaultTtl: 60);
		$invalid = $this->controller()->setPolicy(id: 'app-1', defaultTtl: 60, maxTtl: 1);

		$this->assertSame(Http::STATUS_NOT_FOUND, $unknown->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $invalid->getStatus());
		$this->assertSame(['message' => 'maxTtl below defaultTtl'], $invalid->getData());
	}//end testSetPolicyMapsUnknownTo404AndInvalidTo400()
}//end class
