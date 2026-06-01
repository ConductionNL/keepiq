<?php

/**
 * Unit tests for DelegationService.
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

use OCA\Doriath\Db\SecretDelegation;
use OCA\Doriath\Db\SecretDelegationMapper;
use OCA\Doriath\Db\SecretShare;
use OCA\Doriath\Db\SecretShareMapper;
use OCA\Doriath\Service\DelegationService;
use OCA\Doriath\Service\SecretCopyGateway;
use OCA\Doriath\Service\SecretOwnershipResolver;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for DelegationService.
 */
class DelegationServiceTest extends TestCase
{
    private DelegationService $service;

    private SecretDelegationMapper $delegationMapper;

    private SecretShareMapper $shareMapper;

    private SecretOwnershipResolver $ownership;

    private SecretCopyGateway $copyGateway;

    private IGroupManager $groupManager;

    /**
     * Set up the service under test with mocked collaborators.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->delegationMapper = $this->createMock(SecretDelegationMapper::class);
        $this->shareMapper      = $this->createMock(SecretShareMapper::class);
        $this->ownership        = $this->createMock(SecretOwnershipResolver::class);
        $this->copyGateway      = $this->createMock(SecretCopyGateway::class);
        $this->groupManager     = $this->createMock(IGroupManager::class);

        $this->service = new DelegationService(
            $this->delegationMapper,
            $this->shareMapper,
            $this->ownership,
            $this->copyGateway,
            $this->groupManager,
        );
    }//end setUp()

    /**
     * createDelegation succeeds for the owner when the delegate holds a share.
     *
     * @return void
     */
    public function testCreateDelegationByOwner(): void
    {
        $this->ownership->method('getOwnerId')->willReturn('alice');
        $this->shareMapper->method('findBySourceSecretAndTargetUser')->willReturn(new SecretShare());
        $this->delegationMapper->method('findActiveBySecretAndUser')->willReturn(null);
        $this->delegationMapper->expects($this->once())->method('insert')->willReturnArgument(0);

        $delegation = $this->service->createDelegation('secret-1', 'bob', 'alice');

        $this->assertSame('bob', $delegation->getDelegatedTo());
        $this->assertFalse($delegation->getIsPermanent());
    }//end testCreateDelegationByOwner()

    /**
     * createDelegation succeeds for a vault admin (power grab).
     *
     * @return void
     */
    public function testCreateDelegationByAdmin(): void
    {
        $this->ownership->method('getOwnerId')->willReturn('alice');
        $this->groupManager->method('isInGroup')->willReturn(true);
        $this->shareMapper->method('findBySourceSecretAndTargetUser')->willReturn(new SecretShare());
        $this->delegationMapper->method('findActiveBySecretAndUser')->willReturn(null);
        $this->delegationMapper->expects($this->once())->method('insert')->willReturnArgument(0);

        $delegation = $this->service->createDelegation('secret-1', 'admin', 'admin');
        $this->assertSame('admin', $delegation->getDelegatedTo());
    }//end testCreateDelegationByAdmin()

    /**
     * createDelegation rejects a delegate who holds no share.
     *
     * @return void
     */
    public function testCreateDelegationNoShareThrows(): void
    {
        $this->ownership->method('getOwnerId')->willReturn('alice');
        $this->shareMapper->method('findBySourceSecretAndTargetUser')->willReturn(null);

        $this->expectException(RuntimeException::class);
        $this->service->createDelegation('secret-1', 'bob', 'alice');
    }//end testCreateDelegationNoShareThrows()

    /**
     * reclaimDelegation deletes only temporary delegations.
     *
     * @return void
     */
    public function testReclaimDeletesTemporaryOnly(): void
    {
        $this->ownership->method('isOwner')->willReturn(true);

        $temp = new SecretDelegation();
        $temp->setIsPermanent(false);
        $perm = new SecretDelegation();
        $perm->setIsPermanent(true);
        $this->delegationMapper->method('findBySecret')->willReturn([$temp, $perm]);

        $this->delegationMapper->expects($this->once())->method('delete')->with($temp);

        $count = $this->service->reclaimDelegation('secret-1', 'alice');
        $this->assertSame(1, $count);
    }//end testReclaimDeletesTemporaryOnly()

    /**
     * reclaimDelegation rejects a non-owner caller.
     *
     * @return void
     */
    public function testReclaimNonOwnerThrows(): void
    {
        $this->ownership->method('isOwner')->willReturn(false);

        $this->expectException(RuntimeException::class);
        $this->service->reclaimDelegation('secret-1', 'mallory');
    }//end testReclaimNonOwnerThrows()

    /**
     * makePermanent sets the flag and removes the owner's inaccessible copies.
     *
     * @return void
     */
    public function testMakePermanentRemovesOwnerCopies(): void
    {
        $delegation = new SecretDelegation();
        $delegation->setSecretId('secret-1');
        $this->delegationMapper->method('makePermanentByOriginalOwner')->willReturn([$delegation]);

        $ownerShare = new SecretShare();
        $ownerShare->setSecretId('owner-copy');
        $this->shareMapper->method('findBySourceSecretAndTargetUser')->willReturn($ownerShare);

        $this->copyGateway->expects($this->once())->method('deleteCopy')->with('owner-copy');
        $this->shareMapper->expects($this->once())->method('delete')->with($ownerShare);

        $result = $this->service->makePermanent('alice');
        $this->assertCount(1, $result);
    }//end testMakePermanentRemovesOwnerCopies()
}//end class
