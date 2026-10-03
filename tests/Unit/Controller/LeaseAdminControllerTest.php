<?php

/**
 * Unit tests for the LeaseAdminController setPolicy endpoint
 * (PUT /api/v1/applications/{id}/lease-policy).
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
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

use OCA\Keepiq\Tests\Support\AdminAreaFixture;
use OCA\Keepiq\Settings\AuditAdminSettings;
use InvalidArgumentException;
use OCA\Keepiq\Controller\LeaseAdminController;
use OCA\Keepiq\Db\Application;
use OCA\Keepiq\Db\ApplicationMapper;
use OCA\Keepiq\Db\MachineLeaseMapper;
use OCA\Keepiq\Service\LeaseService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * `leaseAdmin#setPolicy` writes the per-application lease-policy override —
 * the TTL and renewability budget every machine lease for that application is
 * issued against. Three things therefore have to hold on the wire: the write
 * reaches the service with the URL's application id and the submitted TTLs
 * (including nulls, which mean "inherit the instance default" and are NOT the
 * same as "unchanged"); the response is the EFFECTIVE policy after the write,
 * so the admin sees what is now in force rather than what they submitted; and
 * every non-admin path — anonymous, ordinary user, unknown application —
 * writes nothing.
 *
 */
class LeaseAdminControllerTest extends TestCase {
	use AdminAreaFixture;


	/**
	 * The mocked lease service.
	 *
	 * @var LeaseService&MockObject
	 */
	private LeaseService&MockObject $leaseService;

	/**
	 * The mocked application mapper (existence guard).
	 *
	 * @var ApplicationMapper&MockObject
	 */
	private ApplicationMapper&MockObject $applicationMapper;

	/**
	 * The mocked user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * The mocked group manager (admin check).
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Set up the mocks shared by every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->leaseService = $this->createMock(LeaseService::class);
		$this->applicationMapper = $this->createMock(ApplicationMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
	}//end setUp()

	/**
	 * Log a user into the mocked session, or leave it anonymous.
	 *
	 * @param string|null $uid The session user id, or null for anonymous.
	 *
	 * @return void
	 */
	private function signIn(?string $uid): void {
		if ($uid === null) {
			$this->userSession->method('getUser')->willReturn(null);
			return;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * Build the controller under test with its collaborators mocked.
	 *
	 * @return LeaseAdminController The controller under test.
	 */
	private function controller(): LeaseAdminController {
		return new LeaseAdminController(
			request: $this->createMock(IRequest::class),
			leaseService: $this->leaseService,
			leaseMapper: $this->createMock(MachineLeaseMapper::class),
			applicationMapper: $this->applicationMapper,
			userSession: $this->userSession,
			areas: $this->areaAuthorizer(groupManager: $this->groupManager)
		);
	}//end controller()

	/**
	 * An admin's override reaches the service with the URL's application id
	 * and the submitted budget, and the response is the policy now in force.
	 *
	 * @return void
	 */
	public function testSetPolicyStoresTheSubmittedOverrideAndReturnsTheEffectivePolicy(): void {
		$this->signIn('root');
		$this->groupManager->expects($this->once())
			->method('isAdmin')
			->with('root')
			->willReturn(true);

		$this->applicationMapper->expects($this->once())
			->method('findById')
			->with('app-0000-4000-8000-000000000001')
			->willReturn($this->createMock(Application::class));

		$this->leaseService->expects($this->once())
			->method('setPolicyOverride')
			->with('app-0000-4000-8000-000000000001', 3600, 7200, false);

		$this->leaseService->expects($this->once())
			->method('effectivePolicy')
			->with('app-0000-4000-8000-000000000001')
			->willReturn(
				[
					'defaultTtlSeconds' => 3600,
					'maxTtlSeconds' => 7200,
					'renewable' => false,
				]
			);

		$response = $this->controller()->setPolicy(
			id: 'app-0000-4000-8000-000000000001',
			defaultTtl: 3600,
			maxTtl: 7200,
			renewable: false
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[
				'defaultTtlSeconds' => 3600,
				'maxTtlSeconds' => 7200,
				'renewable' => false,
			],
			$response->getData(),
			'the admin must be shown the policy now in force, not their own submission echoed back'
		);
	}//end testSetPolicyStoresTheSubmittedOverrideAndReturnsTheEffectivePolicy()

	/**
	 * Omitted values are forwarded as nulls — "inherit the instance default" —
	 * and the response reports the inherited numbers, not the nulls.
	 *
	 * @return void
	 */
	public function testSetPolicyForwardsOmittedValuesAsInheritAndReportsTheInheritedNumbers(): void {
		$this->signIn('root');
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->applicationMapper->expects($this->once())
			->method('findById')
			->with('app-0000-4000-8000-000000000002')
			->willReturn($this->createMock(Application::class));

		$this->leaseService->expects($this->once())
			->method('setPolicyOverride')
			->with('app-0000-4000-8000-000000000002', null, null, null);

		$this->leaseService->expects($this->once())
			->method('effectivePolicy')
			->with('app-0000-4000-8000-000000000002')
			->willReturn(
				[
					'defaultTtlSeconds' => 900,
					'maxTtlSeconds' => 86400,
					'renewable' => true,
				]
			);

		$response = $this->controller()->setPolicy(id: 'app-0000-4000-8000-000000000002');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[
				'defaultTtlSeconds' => 900,
				'maxTtlSeconds' => 86400,
				'renewable' => true,
			],
			$response->getData(),
			'clearing an override must report the inherited instance defaults'
		);
	}//end testSetPolicyForwardsOmittedValuesAsInheritAndReportsTheInheritedNumbers()

	/**
	 * An anonymous caller gets 401 and writes nothing.
	 *
	 * @return void
	 */
	public function testSetPolicyByAnAnonymousCallerIs401AndWritesNothing(): void {
		$this->signIn(null);

		$this->leaseService->expects($this->never())->method('setPolicyOverride');
		$this->applicationMapper->expects($this->never())->method('findById');

		$response = $this->controller()->setPolicy(id: 'app-0000-4000-8000-000000000001', defaultTtl: 60);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Unauthorized'], $response->getData());
	}//end testSetPolicyByAnAnonymousCallerIs401AndWritesNothing()

	/**
	 * A non-admin gets the same 404 as a nonexistent application — the
	 * endpoint must not confirm that the application exists — and writes
	 * nothing.
	 *
	 * @return void
	 */
	public function testSetPolicyByANonAdminIs404AndWritesNothing(): void {
		$this->signIn('alice');
		$this->groupManager->expects($this->once())
			->method('isAdmin')
			->with('alice')
			->willReturn(false);

		$this->applicationMapper->expects($this->never())->method('findById');
		$this->leaseService->expects($this->never())->method('setPolicyOverride');
		$this->leaseService->expects($this->never())->method('effectivePolicy');

		$response = $this->controller()->setPolicy(
			id: 'app-0000-4000-8000-000000000001',
			defaultTtl: 3600
		);

		$this->assertSame(
			Http::STATUS_NOT_FOUND,
			$response->getStatus(),
			'a non-admin must get the indistinguishable 404, never a 403 that confirms the object'
		);
		$this->assertSame(['message' => 'Not found'], $response->getData());
	}//end testSetPolicyByANonAdminIs404AndWritesNothing()

	/**
	 * A policy for an application that does not exist is a 404 and no write.
	 *
	 * @return void
	 */
	public function testSetPolicyForAnUnknownApplicationIs404AndWritesNothing(): void {
		$this->signIn('root');
		$this->groupManager->method('isAdmin')->willReturn(true);

		$this->applicationMapper->expects($this->once())
			->method('findById')
			->with('app-does-not-exist')
			->willThrowException(new DoesNotExistException('gone'));

		$this->leaseService->expects($this->never())->method('setPolicyOverride');
		$this->leaseService->expects($this->never())->method('effectivePolicy');

		$response = $this->controller()->setPolicy(id: 'app-does-not-exist', defaultTtl: 3600);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'Not found'], $response->getData());
	}//end testSetPolicyForAnUnknownApplicationIs404AndWritesNothing()

	/**
	 * A TTL the service refuses answers 400 with the reason, and no effective
	 * policy is reported — a refused write must not look like a stored one.
	 *
	 * @return void
	 */
	public function testSetPolicyWithARefusedTtlAnswers400AndReportsNoPolicy(): void {
		$this->signIn('root');
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->applicationMapper->method('findById')->willReturn($this->createMock(Application::class));

		$this->leaseService->expects($this->once())
			->method('setPolicyOverride')
			->with('app-0000-4000-8000-000000000001', 30, null, null)
			->willThrowException(new InvalidArgumentException('Lease TTLs must be at least 60 seconds'));

		$this->leaseService->expects($this->never())->method('effectivePolicy');

		$response = $this->controller()->setPolicy(
			id: 'app-0000-4000-8000-000000000001',
			defaultTtl: 30
		);

		$this->assertSame(
			Http::STATUS_BAD_REQUEST,
			$response->getStatus(),
			'a refused TTL must not be reported as a stored policy'
		);
		$this->assertSame(
			['message' => 'Lease TTLs must be at least 60 seconds'],
			$response->getData(),
			'the admin must be told which bound was violated'
		);
	}//end testSetPolicyWithARefusedTtlAnswers400AndReportsNoPolicy()

	/**
	 * The lease policy view the per-application form reads.
	 *
	 * @return array<string,array<string,int|bool|null>>
	 */
	private function policyView(): array {
		return [
			'effective' => ['defaultTtl' => 600, 'maxTtl' => 3600, 'renewable' => true, 'blockOnRevoke' => false],
			'override' => ['defaultTtl' => 600, 'maxTtl' => null, 'renewable' => null],
			'instance' => ['defaultTtl' => 900, 'maxTtl' => 3600, 'renewable' => true],
		];
	}//end policyView()

	/**
	 * A real application entity registered by the given user.
	 *
	 * @param string $registeredBy The registrant uid.
	 *
	 * @return Application
	 */
	private function applicationOf(string $registeredBy): Application {
		$application = new Application();
		$application->setRegisteredBy($registeredBy);
		return $application;
	}//end applicationOf()

	/**
	 * An admin reads the policy view and may edit it (keepiq#753).
	 *
	 * @return void
	 */
	public function testGetPolicyByAnAdminReturnsTheViewAndMayEdit(): void {
		$this->signIn('root');
		$this->groupManager->method('isAdmin')->with('root')->willReturn(true);
		$this->applicationMapper->expects($this->once())
			->method('findById')
			->with('app-1')
			->willReturn($this->applicationOf('bob'));
		$this->leaseService->expects($this->once())
			->method('policyView')
			->with('app-1')
			->willReturn($this->policyView());

		$response = $this->controller()->getPolicy(id: 'app-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(array_merge($this->policyView(), ['canEdit' => true]), $response->getData());
	}//end testGetPolicyByAnAdminReturnsTheViewAndMayEdit()

	/**
	 * The registrant reads the same view but may not edit: setPolicy() is
	 * admin-only, so the form must not offer a save it would refuse.
	 *
	 * @return void
	 */
	public function testGetPolicyByTheRegistrantIsReadOnly(): void {
		$this->signIn('bob');
		$this->groupManager->method('isAdmin')->with('bob')->willReturn(false);
		$this->applicationMapper->method('findById')->with('app-1')->willReturn($this->applicationOf('bob'));
		$this->leaseService->method('policyView')->willReturn($this->policyView());

		$response = $this->controller()->getPolicy(id: 'app-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertFalse($response->getData()['canEdit']);
	}//end testGetPolicyByTheRegistrantIsReadOnly()

	/**
	 * Another user gets the indistinguishable 404 and learns nothing.
	 *
	 * @return void
	 */
	public function testGetPolicyByAnotherUserIs404AndRevealsNothing(): void {
		$this->signIn('mallory');
		$this->groupManager->method('isAdmin')->with('mallory')->willReturn(false);
		$this->applicationMapper->method('findById')->with('app-1')->willReturn($this->applicationOf('bob'));
		$this->leaseService->expects($this->never())->method('policyView');

		$response = $this->controller()->getPolicy(id: 'app-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'Not found'], $response->getData());
	}//end testGetPolicyByAnotherUserIs404AndRevealsNothing()

	/**
	 * An anonymous caller gets 401.
	 *
	 * @return void
	 */
	public function testGetPolicyByAnAnonymousCallerIs401(): void {
		$this->signIn(null);
		$this->leaseService->expects($this->never())->method('policyView');

		$response = $this->controller()->getPolicy(id: 'app-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testGetPolicyByAnAnonymousCallerIs401()

	/**
	 * An admin asking for an application that does not exist gets a 404.
	 *
	 * @return void
	 */
	public function testGetPolicyForAnUnknownApplicationIs404(): void {
		$this->signIn('root');
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->applicationMapper->method('findById')
			->willThrowException(new DoesNotExistException('gone'));
		$this->leaseService->expects($this->never())->method('policyView');

		$response = $this->controller()->getPolicy(id: 'app-does-not-exist');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testGetPolicyForAnUnknownApplicationIs404()


	/**
	 * A holder of only the Audit area is refused like any non-admin
	 * (admin-scoped-roles §2.2).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-scoped-roles/tasks.md#2.2
	 */
	public function testSetPolicyByAnAuditAreaHolderIs404AndWritesNothing(): void {
		$this->delegatedAreas = [AuditAdminSettings::class];
		$this->signIn('auditor');
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->leaseService->expects($this->never())->method('setPolicyOverride');

		$response = $this->controller()->setPolicy(id: 'app-0000-4000-8000-000000000001', defaultTtl: 3600);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testSetPolicyByAnAuditAreaHolderIs404AndWritesNothing()
}//end class
