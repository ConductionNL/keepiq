<?php

/**
 * Contract tests for the Applications admin endpoints (admin-public-api §1.4).
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
use OCA\Keepiq\Db\Application;
use OCA\Keepiq\Db\ApplicationMapper;
use OCA\Keepiq\Service\ApplicationService;
use OCA\Keepiq\Service\LeaseService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Each endpoint runs the screen's service as an administrator.
 */
class AdminApplicationControllerTest extends TestCase {
	/** @var ApplicationService&MockObject */
	private ApplicationService $applications;

	/** @var LeaseService&MockObject */
	private LeaseService $leases;

	/** @var ApplicationMapper&MockObject */
	private ApplicationMapper $mapper;

	/**
	 * Fresh doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->applications = $this->createMock(ApplicationService::class);
		$this->leases = $this->createMock(LeaseService::class);
		$this->mapper = $this->createMock(ApplicationMapper::class);
	}//end setUp()

	/**
	 * The controller, signed in as service account `svc-apps`.
	 *
	 * @return AdminApplicationController
	 */
	private function controller(): AdminApplicationController {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('svc-apps');
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new AdminApplicationController(
			request: $this->createStub(IRequest::class),
			applications: $this->applications,
			leases: $this->leases,
			applicationMapper: $this->mapper,
			userSession: $session,
		);
	}//end controller()

	/**
	 * An application row.
	 *
	 * @param string $id The id
	 *
	 * @return Application
	 */
	private function application(string $id): Application {
		$application = new Application();
		$application->setId($id);
		$application->setName('ci-runner');

		return $application;
	}//end application()

	/**
	 * The list is the administrator's list of every application.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-public-api/tasks.md#1.4
	 */
	public function testIndexListsEveryApplication(): void {
		$this->applications->expects($this->once())->method('listForUser')->with('svc-apps', true)->willReturn([$this->application(id: 'app-1')]);

		$data = $this->controller()->index()->getData();

		$this->assertSame('app-1', $data[0]['id']);
	}//end testIndexListsEveryApplication()

	/**
	 * Registration runs as an administrator and answers 201; a blank name is 400.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-public-api/tasks.md#1.4
	 */
	public function testCreateRegistersAsAnAdministrator(): void {
		$this->applications->expects($this->once())->method('register')
			->with('ci-runner', null, 'external', 'CSR', 'svc-apps', true)
			->willReturn($this->application(id: 'app-2'));

		$this->assertSame(201, $this->controller()->create(name: 'ci-runner', csr: 'CSR')->getStatus());
		$this->assertSame(400, $this->controller()->create(name: '  ')->getStatus());
	}//end testCreateRegistersAsAnAdministrator()

	/**
	 * Scenario "Script approves a pending application": the caller is recorded
	 * as approver; a refusal is 400.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-public-api/tasks.md#1.4
	 */
	public function testApproveRecordsTheCallerAsApprover(): void {
		$this->applications->expects($this->exactly(2))->method('approve')
			->with('app-1', 'svc-apps', true)
			->willReturnOnConsecutiveCalls($this->application(id: 'app-1'), $this->throwException(new InvalidArgumentException('Application is not pending')));

		$this->assertSame(200, $this->controller()->approve(id: 'app-1')->getStatus());
		$this->assertSame(400, $this->controller()->approve(id: 'app-1')->getStatus());
	}//end testApproveRecordsTheCallerAsApprover()

	/**
	 * Reject runs the service as the caller.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-public-api/tasks.md#1.4
	 */
	public function testRejectRunsTheService(): void {
		$this->applications->expects($this->once())->method('reject')->with('app-1', 'svc-apps', true);

		$this->assertSame(['status' => 'rejected', 'id' => 'app-1'], $this->controller()->reject(id: 'app-1')->getData());
	}//end testRejectRunsTheService()

	/**
	 * Show and delete answer 404 for an unknown application.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-public-api/tasks.md#1.4
	 */
	public function testUnknownApplicationsAre404(): void {
		$this->applications->method('get')->willThrowException(new InvalidArgumentException('Application not found'));
		$this->applications->method('delete')->willThrowException(new InvalidArgumentException('Application not found'));

		$this->assertSame(404, $this->controller()->show(id: 'nope')->getStatus());
		$this->assertSame(404, $this->controller()->destroy(id: 'nope')->getStatus());
	}//end testUnknownApplicationsAre404()

	/**
	 * Delete removes the application through the service.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-public-api/tasks.md#1.4
	 */
	public function testDestroyDeletesThroughTheService(): void {
		$this->applications->expects($this->once())->method('delete')->with('app-1', true);

		$this->assertSame(['status' => 'deleted', 'id' => 'app-1'], $this->controller()->destroy(id: 'app-1')->getData());
	}//end testDestroyDeletesThroughTheService()

	/**
	 * The lease policy: 404 for an unknown application, the view for a known
	 * one, and a write that stores the override and returns the new view.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-public-api/tasks.md#1.4
	 */
	public function testLeasePolicyReadAndWrite(): void {
		$this->mapper->method('findById')->willReturnCallback(function (string $id): Application {
			if ($id !== 'app-1') {
				throw new DoesNotExistException('no');
			}

			return $this->application(id: $id);
		});
		$view = ['override' => ['defaultTtl' => 600], 'effective' => ['defaultTtl' => 600]];
		$this->leases->method('policyView')->with('app-1')->willReturn($view);
		$this->leases->expects($this->once())->method('setPolicyOverride')->with('app-1', 600, null, null);

		$this->assertSame(404, $this->controller()->getLeasePolicy(id: 'nope')->getStatus());
		$this->assertSame($view, $this->controller()->getLeasePolicy(id: 'app-1')->getData());
		$this->assertSame($view, $this->controller()->setLeasePolicy(id: 'app-1', defaultTtl: 600)->getData());
		$this->assertSame(404, $this->controller()->setLeasePolicy(id: 'nope', defaultTtl: 600)->getStatus());
	}//end testLeasePolicyReadAndWrite()

	/**
	 * A refused lease policy answers 400.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-public-api/tasks.md#1.4
	 */
	public function testARefusedLeasePolicyIs400(): void {
		$this->mapper->method('findById')->willReturn($this->application(id: 'app-1'));
		$this->leases->method('setPolicyOverride')->willThrowException(new InvalidArgumentException('defaultTtl must be at least 60 seconds'));

		$this->assertSame(400, $this->controller()->setLeasePolicy(id: 'app-1', defaultTtl: 5)->getStatus());
	}//end testARefusedLeasePolicyIs400()

	/**
	 * Show carries the public certificate of an active application, and
	 * null for a pending one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-public-api/tasks.md#1.4
	 */
	public function testShowCarriesTheCertificateOfAnActiveApplication(): void {
		$active = $this->application(id: 'app-1');
		$active->setStatus('active');
		$pending = $this->application(id: 'app-2');
		$pending->setStatus('pending');
		$this->applications->method('get')->willReturnCallback(
			static fn (string $id): Application => ($id === 'app-1' ? $active : $pending)
		);
		$this->applications->expects($this->once())->method('getCertificate')->with('app-1')->willReturn('-----BEGIN CERTIFICATE-----');

		$this->assertSame('-----BEGIN CERTIFICATE-----', $this->controller()->show(id: 'app-1')->getData()['certificate']);
		$this->assertNull($this->controller()->show(id: 'app-2')->getData()['certificate']);
	}//end testShowCarriesTheCertificateOfAnActiveApplication()
}//end class
