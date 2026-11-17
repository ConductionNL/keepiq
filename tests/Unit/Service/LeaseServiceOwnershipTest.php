<?php

/**
 * Unit tests for LeaseService's ownership, revoke-block and delegation paths.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
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

namespace OCA\Keepiq\Tests\Unit\Service;

use OCA\Keepiq\Db\MachineLease;
use OCA\Keepiq\Db\MachineLeaseMapper;
use OCA\Keepiq\Service\LeasePolicyService;
use OCA\Keepiq\Service\LeaseService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A lease is only loaded for its own application, a revoked latest lease blocks
 * fetches only under the block-on-revoke policy, and policy writes go to the
 * policy service unchanged.
 */
class LeaseServiceOwnershipTest extends TestCase {
	/** @var MachineLeaseMapper&MockObject */
	private MachineLeaseMapper $leases;

	/** @var LeasePolicyService&MockObject */
	private LeasePolicyService $policies;

	/**
	 * Fresh doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->leases = $this->createMock(MachineLeaseMapper::class);
		$this->policies = $this->createMock(LeasePolicyService::class);
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return LeaseService
	 */
	private function service(): LeaseService {
		return new LeaseService(leaseMapper: $this->leases, policyService: $this->policies);
	}//end service()

	/**
	 * A lease of the given application and status.
	 *
	 * @param string $applicationId The application
	 * @param string $status        The status
	 *
	 * @return MachineLease
	 */
	private function lease(string $applicationId, string $status = 'active'): MachineLease {
		$lease = new MachineLease();
		$lease->setId('lease-1');
		$lease->setApplicationId($applicationId);
		$lease->setStatus($status);
		return $lease;
	}//end lease()

	/**
	 * The owning application gets its lease back.
	 *
	 * @return void
	 */
	public function testLoadOwnedReturnsTheApplicationsOwnLease(): void {
		$lease = $this->lease(applicationId: 'app-1');
		$this->leases->expects($this->once())->method('findById')->with('lease-1')->willReturn($lease);

		$this->assertSame($lease, $this->service()->loadOwned(leaseId: 'lease-1', applicationId: 'app-1'));
	}//end testLoadOwnedReturnsTheApplicationsOwnLease()

	/**
	 * Another application's lease reads as not found, not as forbidden.
	 *
	 * @return void
	 */
	public function testLoadOwnedHidesAnotherApplicationsLease(): void {
		$this->leases->method('findById')->willReturn($this->lease(applicationId: 'app-2'));

		$this->expectException(DoesNotExistException::class);
		$this->expectExceptionMessage('Lease not found');
		$this->service()->loadOwned(leaseId: 'lease-1', applicationId: 'app-1');
	}//end testLoadOwnedHidesAnotherApplicationsLease()

	/**
	 * Without block-on-revoke nothing blocks, and the leases are not even read.
	 *
	 * @return void
	 */
	public function testNoBlockWithoutTheBlockOnRevokePolicy(): void {
		$this->policies->method('effectivePolicy')->with('app-1')->willReturn(['blockOnRevoke' => false]);
		$this->leases->expects($this->never())->method('findLatest');

		$this->assertFalse($this->service()->fetchBlocked(applicationId: 'app-1', secretId: 's1'));
	}//end testNoBlockWithoutTheBlockOnRevokePolicy()

	/**
	 * Under block-on-revoke only a revoked latest lease blocks.
	 *
	 * @return void
	 */
	public function testOnlyARevokedLatestLeaseBlocks(): void {
		$this->policies->method('effectivePolicy')->willReturn(['blockOnRevoke' => true]);
		$this->leases->expects($this->exactly(3))
			->method('findLatest')
			->with('app-1', 's1')
			->willReturnOnConsecutiveCalls(
				$this->lease(applicationId: 'app-1', status: 'revoked'),
				$this->lease(applicationId: 'app-1', status: 'active'),
				null
			);

		$service = $this->service();
		$this->assertTrue($service->fetchBlocked(applicationId: 'app-1', secretId: 's1'));
		$this->assertFalse($service->fetchBlocked(applicationId: 'app-1', secretId: 's1'));
		$this->assertFalse($service->fetchBlocked(applicationId: 'app-1', secretId: 's1'));
	}//end testOnlyARevokedLatestLeaseBlocks()

	/**
	 * Listing and policy overrides pass straight through.
	 *
	 * @return void
	 */
	public function testListAndPolicyOverrideDelegate(): void {
		$lease = $this->lease(applicationId: 'app-1');
		$this->leases->expects($this->once())->method('findByApplication')->with('app-1')->willReturn([$lease]);
		$this->policies->expects($this->once())->method('setPolicyOverride')->with('app-1', 60, null, false);

		$service = $this->service();
		$this->assertSame([$lease], $service->listForApplication(applicationId: 'app-1'));
		$service->setPolicyOverride(applicationId: 'app-1', defaultTtl: 60, maxTtl: null, renewable: false);
	}//end testListAndPolicyOverrideDelegate()
}//end class
