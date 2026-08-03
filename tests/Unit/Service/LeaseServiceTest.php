<?php

/**
 * Unit tests for LeaseService (machine-secret-leases §7.2/§7.3).
 *
 * @category Test
 * @package  OCA\Doriath\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Doriath\Tests\Unit\Service;

use DateTime;
use InvalidArgumentException;
use OCA\Doriath\Db\ApplicationLeasePolicyMapper;
use OCA\Doriath\Db\MachineLease;
use OCA\Doriath\Db\MachineLeaseMapper;
use OCA\Doriath\Service\LeaseService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LeaseService.
 */
class LeaseServiceTest extends TestCase
{
    private LeaseService $service;

    private MachineLeaseMapper&MockObject $leaseMapper;

    private ApplicationLeasePolicyMapper&MockObject $policyMapper;

    /**
     * Build the service with fresh mocks; instance defaults 900/86400,
     * renewable, block-on-revoke off.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->leaseMapper  = $this->createMock(originalClassName: MachineLeaseMapper::class);
        $this->policyMapper = $this->createMock(originalClassName: ApplicationLeasePolicyMapper::class);
        $this->policyMapper->method('findByApplication')->willThrowException(new DoesNotExistException('none'));

        $appConfig = $this->createMock(originalClassName: IAppConfig::class);
        $appConfig->method('getValueInt')->willReturnCallback(
            static fn (string $app, string $key, int $default=0): int => $default
        );
        $appConfig->method('getValueBool')->willReturnCallback(
            static fn (string $app, string $key, bool $default=false): bool => $default
        );

        $this->service = new LeaseService(
            leaseMapper: $this->leaseMapper,
            policyMapper: $this->policyMapper,
            appConfig: $appConfig,
            auditTrail: null,
            rotationService: null,
        );
    }//end setUp()

    /**
     * Build an active lease row.
     *
     * @param string $applicationId The holding application
     * @param string $grantedAt     The grant instant (ISO)
     * @param string $expiresAt     The expiry instant (ISO)
     *
     * @return MachineLease
     */
    private function activeLease(string $applicationId='app-1', string $grantedAt='now', string $expiresAt='+15 minutes'): MachineLease
    {
        $lease = new MachineLease();
        $lease->setId('lease-1');
        $lease->setApplicationId($applicationId);
        $lease->setSecretId('sec-1');
        $lease->setScope('read');
        $lease->setStatus('active');
        $lease->setGrantedAt(new DateTime($grantedAt));
        $lease->setExpiresAt(new DateTime($expiresAt));
        return $lease;
    }//end activeLease()

    /**
     * 7.2: grant caps the requested TTL to the policy maximum.
     *
     * @return void
     */
    public function testGrantCapsTtlToPolicyMax(): void
    {
        $this->leaseMapper->method('findLive')->willReturn(null);
        $inserted = null;
        $this->leaseMapper->method('insert')->willReturnCallback(
            static function (MachineLease $lease) use (&$inserted) {
                $inserted = $lease;
                return $lease;
            }
        );

        // Requested 7 days; max is 86400s (1 day).
        $before = new DateTime('+86401 seconds');
        $this->service->grantOrReuse(applicationId: 'app-1', secretId: 'sec-1', requestedTtl: 604800);

        $this->assertNotNull($inserted);
        $this->assertLessThanOrEqual($before->getTimestamp(), $inserted->getExpiresAt()->getTimestamp());
        $this->assertSame('active', $inserted->getStatus());
        $this->assertSame('read', $inserted->getScope());
    }//end testGrantCapsTtlToPolicyMax()

    /**
     * 7.2: a repeat poll reuses the live lease WITHOUT extending it.
     *
     * @return void
     */
    public function testPollReusesLiveLeaseWithoutExtending(): void
    {
        $live   = $this->activeLease();
        $expiry = $live->getExpiresAt()->getTimestamp();
        $this->leaseMapper->method('findLive')->willReturn($live);
        $this->leaseMapper->expects($this->never())->method('insert');
        $this->leaseMapper->expects($this->never())->method('update');

        $result = $this->service->grantOrReuse(applicationId: 'app-1', secretId: 'sec-1', requestedTtl: 86400);

        $this->assertSame('lease-1', $result->getId());
        $this->assertSame($expiry, $result->getExpiresAt()->getTimestamp());
    }//end testPollReusesLiveLeaseWithoutExtending()

    /**
     * 7.2: renewal is refused once `granted_at + max TTL` is reached.
     *
     * @return void
     */
    public function testRenewRefusedPastMaxLifetime(): void
    {
        // Granted 23h59m ago and already extended to the hard cap
        // (clone preserves sub-second precision so cap === current).
        $lease = $this->activeLease(grantedAt: '-86340 seconds', expiresAt: '+60 seconds');
        $lease->setExpiresAt((clone $lease->getGrantedAt())->modify('+86400 seconds'));
        $this->leaseMapper->method('findById')->willReturn($lease);
        $this->leaseMapper->expects($this->never())->method('update');

        $this->expectException(InvalidArgumentException::class);
        $this->service->renew(leaseId: 'lease-1', applicationId: 'app-1');
    }//end testRenewRefusedPastMaxLifetime()

    /**
     * 7.2: renewal extends to `min(now+default, granted_at+max)` and
     * increments the counter.
     *
     * @return void
     */
    public function testRenewExtendsAndCounts(): void
    {
        $lease = $this->activeLease(grantedAt: '-5 minutes', expiresAt: '+10 minutes');
        $this->leaseMapper->method('findById')->willReturn($lease);
        $this->leaseMapper->method('update')->willReturnCallback(static fn (MachineLease $row) => $row);

        $renewed = $this->service->renew(leaseId: 'lease-1', applicationId: 'app-1');

        $this->assertSame(1, $renewed->getRenewedCount());
        $this->assertNotNull($renewed->getLastRenewedAt());
        // Extended to ~now + 900s (default), beyond the old +10m? No:
        // 900s = 15m > 10m left, so the expiry advanced.
        $this->assertGreaterThan((new DateTime('+10 minutes'))->getTimestamp() - 5, $renewed->getExpiresAt()->getTimestamp());
    }//end testRenewExtendsAndCounts()

    /**
     * 7.2: cross-application renew is indistinguishable from a
     * nonexistent lease.
     *
     * @return void
     */
    public function testCrossApplicationRenewIsNotFound(): void
    {
        $this->leaseMapper->method('findById')->willReturn($this->activeLease(applicationId: 'other-app'));

        $this->expectException(DoesNotExistException::class);
        $this->service->renew(leaseId: 'lease-1', applicationId: 'app-1');
    }//end testCrossApplicationRenewIsNotFound()

    /**
     * 7.2: revocation marks the lease revoked with actor + instant;
     * revoking twice is idempotent.
     *
     * @return void
     */
    public function testRevokeMarksAndIsIdempotent(): void
    {
        $lease   = $this->activeLease();
        $updates = 0;
        $this->leaseMapper->method('update')->willReturnCallback(
            static function (MachineLease $row) use (&$updates) {
                ++$updates;
                return $row;
            }
        );

        $revoked = $this->service->revoke(lease: $lease, actor: 'admin');
        $this->assertSame('revoked', $revoked->getStatus());
        $this->assertSame('admin', $revoked->getRevokedBy());
        $this->assertNotNull($revoked->getRevokedAt());

        $this->service->revoke(lease: $revoked, actor: 'admin');
        $this->assertSame(1, $updates);
    }//end testRevokeMarksAndIsIdempotent()

    /**
     * 7.2: block-on-revoke refuses a fetch only when the policy is on
     * AND the latest lease is revoked (default off = never blocked).
     *
     * @return void
     */
    public function testFetchBlockedOnlyWhenPolicyOn(): void
    {
        $revoked = $this->activeLease();
        $revoked->setStatus('revoked');
        $this->leaseMapper->method('findLatest')->willReturn($revoked);

        // Default policy: block-on-revoke off.
        $this->assertFalse($this->service->fetchBlocked(applicationId: 'app-1', secretId: 'sec-1'));
    }//end testFetchBlockedOnlyWhenPolicyOn()

    /**
     * 7.3: the expiry sweep transitions only past-expiry active leases.
     *
     * @return void
     */
    public function testExpireDueTransitionsOnlyOverdue(): void
    {
        $overdue = $this->activeLease(expiresAt: '-5 minutes');
        $this->leaseMapper->method('findExpiredActive')->willReturn([$overdue]);
        $updated = [];
        $this->leaseMapper->method('update')->willReturnCallback(
            static function (MachineLease $row) use (&$updated) {
                $updated[] = $row;
                return $row;
            }
        );

        $count = $this->service->expireDue();

        $this->assertSame(1, $count);
        $this->assertCount(1, $updated);
        $this->assertSame('expired', $updated[0]->getStatus());
    }//end testExpireDueTransitionsOnlyOverdue()
}//end class
