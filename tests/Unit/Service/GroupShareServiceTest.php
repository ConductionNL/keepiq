<?php

/**
 * Unit tests for GroupShareService.
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

use OCA\Doriath\Db\EncryptionSuite;
use OCA\Doriath\Db\GroupShare;
use OCA\Doriath\Db\GroupShareMapper;
use OCA\Doriath\Db\SecretShare;
use OCA\Doriath\Db\SecretShareMapper;
use OCA\Doriath\Service\GroupShareService;
use OCA\Doriath\Service\NotificationService;
use OCA\Doriath\Service\SecretCopyGateway;
use OCA\Doriath\Service\SecretOwnershipResolver;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for GroupShareService.
 */
class GroupShareServiceTest extends TestCase
{
    private GroupShareService $service;

    private GroupShareMapper $groupShareMapper;

    private SecretShareMapper $shareMapper;

    private SecretOwnershipResolver $ownership;

    private SecretCopyGateway $copyGateway;

    private NotificationService $notificationService;

    private IGroupManager $groupManager;

    /**
     * Set up the service under test with mocked collaborators.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->groupShareMapper    = $this->createMock(GroupShareMapper::class);
        $this->shareMapper         = $this->createMock(SecretShareMapper::class);
        $this->ownership           = $this->createMock(SecretOwnershipResolver::class);
        $this->copyGateway         = $this->createMock(SecretCopyGateway::class);
        $this->notificationService = $this->createMock(NotificationService::class);
        $this->groupManager        = $this->createMock(IGroupManager::class);

        $this->service = new GroupShareService(
            $this->groupShareMapper,
            $this->shareMapper,
            $this->ownership,
            $this->copyGateway,
            $this->notificationService,
            $this->groupManager,
        );
    }//end setUp()

    /**
     * Build a mock IUser with the given UID.
     *
     * @param string $uid The user ID
     *
     * @return IUser
     */
    private function mockUser(string $uid): IUser
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        return $user;
    }//end mockUser()

    /**
     * createGroupShare expands to eligible members, excluding the owner and
     * members without an active suite.
     *
     * @return void
     */
    public function testCreateGroupShareExpansion(): void
    {
        $this->ownership->method('canManageShares')->willReturn(true);

        $group = $this->createMock(IGroup::class);
        $group->method('getUsers')->willReturn(
            [$this->mockUser('alice'), $this->mockUser('bob'), $this->mockUser('eve')]
        );
        $this->groupManager->method('get')->willReturn($group);

        $suite = new EncryptionSuite();
        $suite->setId('suite-1');
        // Bob has a suite, eve does not, alice is the owner (excluded).
        $this->ownership->method('getActiveSuiteForUser')->willReturnMap(
            [
                ['bob', $suite],
                ['eve', null],
            ]
        );

        $this->groupShareMapper->expects($this->once())->method('insert')->willReturnArgument(0);

        $result = $this->service->createGroupShare('secret-1', 'devs', 'alice');

        $this->assertSame(['bob'], $result['eligibleMembers']);
        $this->assertInstanceOf(GroupShare::class, $result['groupShare']);
    }//end testCreateGroupShareExpansion()

    /**
     * createGroupShare rejects an unauthorized caller.
     *
     * @return void
     */
    public function testCreateGroupShareUnauthorizedThrows(): void
    {
        $this->ownership->method('canManageShares')->willReturn(false);

        $this->expectException(RuntimeException::class);
        $this->service->createGroupShare('secret-1', 'devs', 'mallory');
    }//end testCreateGroupShareUnauthorizedThrows()

    /**
     * revokeGroupShare cascade-deletes derived shares and their copies.
     *
     * @return void
     */
    public function testRevokeGroupShareCascades(): void
    {
        $groupShare = new GroupShare();
        $groupShare->setId('gs-1');
        $groupShare->setSecretId('secret-1');
        $this->groupShareMapper->method('findById')->willReturn($groupShare);
        $this->ownership->method('canManageShares')->willReturn(true);

        $derived = new SecretShare();
        $derived->setSecretId('copy-1');
        $this->shareMapper->method('deleteByGroupShare')->willReturn([$derived]);

        $this->copyGateway->expects($this->once())->method('deleteCopy')->with('copy-1');
        $this->groupShareMapper->expects($this->once())->method('delete')->with($groupShare);

        $this->service->revokeGroupShare('gs-1', 'alice');
    }//end testRevokeGroupShareCascades()

    /**
     * handleMemberLeave deletes group-derived shares but not direct shares.
     *
     * @return void
     */
    public function testHandleMemberLeaveOnlyGroupDerived(): void
    {
        $groupShare = new GroupShare();
        $groupShare->setId('gs-1');
        $this->groupShareMapper->method('findByGroup')->willReturn([$groupShare]);

        $directShare = new SecretShare();
        $directShare->setSecretId('direct-copy');
        $directShare->setGroupShareId(null);

        $groupDerived = new SecretShare();
        $groupDerived->setSecretId('group-copy');
        $groupDerived->setGroupShareId('gs-1');

        $this->shareMapper->method('findByTargetUser')->willReturn([$directShare, $groupDerived]);

        $this->copyGateway->expects($this->once())->method('deleteCopy')->with('group-copy');
        $this->shareMapper->expects($this->once())->method('delete')->with($groupDerived);

        $this->service->handleMemberLeave('bob', 'devs');
    }//end testHandleMemberLeaveOnlyGroupDerived()

    /**
     * handleNewGroupMember sends one batched notification per owner.
     *
     * @return void
     */
    public function testHandleNewGroupMemberNotifiesOwners(): void
    {
        $gs1 = new GroupShare();
        $gs1->setSecretId('secret-1');
        $gs2 = new GroupShare();
        $gs2->setSecretId('secret-2');
        $this->groupShareMapper->method('findByGroup')->willReturn([$gs1, $gs2]);

        // Both secrets owned by alice -> a single batched notification.
        $this->ownership->method('getOwnerId')->willReturn('alice');

        $this->notificationService->expects($this->once())
            ->method('notify')
            ->with('group_member_added', 'alice');

        $this->service->handleNewGroupMember('newbie', 'devs');
    }//end testHandleNewGroupMemberNotifiesOwners()
}//end class
