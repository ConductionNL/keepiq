<?php

/**
 * Contract tests for MachineLeaseController.
 *
 * The lease endpoints are public pages: an application authenticates with
 * its bearer token, which the middleware resolves into the controller's
 * application. Without one the caller is refused, a lease is only read or
 * revoked within the calling application, and a revoke by the application
 * itself is recorded as `self`.
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

use OCA\Keepiq\Controller\MachineLeaseController;
use OCA\Keepiq\Db\Application;
use OCA\Keepiq\Db\MachineLease;
use OCA\Keepiq\Service\LeaseService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MachineLeaseControllerTest extends TestCase {

	/**
	 * The mocked lease service.
	 *
	 * @var LeaseService&MockObject
	 */
	private LeaseService&MockObject $leaseService;

	/**
	 * The controller under test.
	 *
	 * @var MachineLeaseController
	 */
	private MachineLeaseController $controller;

	/**
	 * Build the controller over mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->leaseService = $this->createMock(originalClassName: LeaseService::class);
		$this->controller = new MachineLeaseController(
			request: $this->createMock(originalClassName: IRequest::class),
			leaseService: $this->leaseService,
		);
	}//end setUp()

	/**
	 * Resolve the calling application, as the bearer middleware does.
	 *
	 * @return void
	 */
	private function authenticateApplication(): void {
		$application = new Application();
		$application->setId('app-1');
		$this->controller->setApplication($application);
	}//end authenticateApplication()

	/**
	 * A lease of the calling application.
	 *
	 * @param string $status The lease status.
	 *
	 * @return MachineLease
	 */
	private function lease(string $status): MachineLease {
		$lease = new MachineLease();
		$lease->setId('lease-1');
		$lease->setApplicationId('app-1');
		$lease->setStatus($status);
		return $lease;
	}//end lease()

	/**
	 * Without a bearer token both endpoints refuse before the service runs.
	 *
	 * @return void
	 */
	public function testWithoutABearerTokenTheCallerIsRefused(): void {
		$this->leaseService->expects($this->never())->method('listForApplication');
		$this->leaseService->expects($this->never())->method('loadOwned');

		foreach ([$this->controller->index(), $this->controller->revoke(id: 'lease-1')] as $response) {
			$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
			$this->assertSame(expected: ['message' => 'Bearer token required'], actual: $response->getData());
		}
	}//end testWithoutABearerTokenTheCallerIsRefused()

	/**
	 * The index lists only the calling application's leases.
	 *
	 * @return void
	 */
	public function testIndexListsTheCallingApplicationsLeases(): void {
		$this->authenticateApplication();
		$this->leaseService->expects($this->once())->method('listForApplication')->with('app-1')
			->willReturn([$this->lease(status: 'active')]);

		$data = $this->controller->index()->getData();

		$this->assertCount(expectedCount: 1, haystack: $data);
		$this->assertSame(expected: 'lease-1', actual: $data[0]['id']);
	}//end testIndexListsTheCallingApplicationsLeases()

	/**
	 * A revoke loads the lease within the calling application and records `self`.
	 *
	 * @return void
	 */
	public function testRevokeIsScopedToTheCallerAndRecordedAsSelf(): void {
		$this->authenticateApplication();
		$lease = $this->lease(status: 'active');
		$this->leaseService->expects($this->once())->method('loadOwned')->with('lease-1', 'app-1')->willReturn($lease);
		$this->leaseService->expects($this->once())->method('revoke')->with($lease, 'self')
			->willReturn($this->lease(status: 'revoked'));

		$response = $this->controller->revoke(id: 'lease-1');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: 'revoked', actual: $response->getData()['status']);
	}//end testRevokeIsScopedToTheCallerAndRecordedAsSelf()

	/**
	 * A lease outside the calling application reads as not found and is left alone.
	 *
	 * @return void
	 */
	public function testAnotherApplicationsLeaseIsNotFound(): void {
		$this->authenticateApplication();
		$this->leaseService->method('loadOwned')->willThrowException(new DoesNotExistException(msg: 'not yours'));
		$this->leaseService->expects($this->never())->method('revoke');

		$response = $this->controller->revoke(id: 'lease-9');

		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Lease not found'], actual: $response->getData());
	}//end testAnotherApplicationsLeaseIsNotFound()
}//end class
