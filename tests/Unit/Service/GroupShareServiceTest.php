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

use InvalidArgumentException;
use OCA\Doriath\Db\EncryptionSuite;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\GroupShare;
use OCA\Doriath\Db\GroupShareMapper;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretDelegation;
use OCA\Doriath\Db\SecretDelegationMapper;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\ShareTarget;
use OCA\Doriath\Db\ShareTargetMapper;
use OCA\Doriath\Service\GroupShareService;
use OCA\Doriath\Service\NotificationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for GroupShareService.
 */
class GroupShareServiceTest extends TestCase
{

    /**
     * Service under test.
     *
     * @var GroupShareService
     */
    private GroupShareService $service;

    /**
     * @var GroupShareMapper&MockObject
     */
    private GroupShareMapper $mapper;

    /**
     * @var ShareTargetMapper&MockObject
     */
    private ShareTargetMapper $shareTargetMapper;

    /**
     * @var SecretMapper&MockObject
     */
    private SecretMapper $secretMapper;

    /**
     * @var EncryptionSuiteMapper&MockObject
     */
    private EncryptionSuiteMapper $suiteMapper;

    /**
     * @var SecretDelegationMapper&MockObject
     */
    private SecretDelegationMapper $delegationMapper;

    /**
     * @var IGroupManager&MockObject
     */
    private IGroupManager $groupManager;

    /**
     * @var NotificationService&MockObject
     */
    private NotificationService $notificationService;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->mapper            = $this->createMock(originalClassName: GroupShareMapper::class);
        $this->shareTargetMapper = $this->createMock(originalClassName: ShareTargetMapper::class);
        $this->secretMapper      = $this->createMock(originalClassName: SecretMapper::class);
        $this->suiteMapper       = $this->createMock(originalClassName: EncryptionSuiteMapper::class);
        $this->delegationMapper  = $this->createMock(originalClassName: SecretDelegationMapper::class);
        $this->groupManager      = $this->createMock(originalClassName: IGroupManager::class);
        $this->notificationService = $this->createMock(originalClassName: NotificationService::class);
        $logger = $this->createMock(originalClassName: LoggerInterface::class);

        $this->service = new GroupShareService(
            mapper: $this->mapper,
            shareTargetMapper: $this->shareTargetMapper,
            secretMapper: $this->secretMapper,
            suiteMapper: $this->suiteMapper,
            delegationMapper: $this->delegationMapper,
            groupManager: $this->groupManager,
            notificationService: $this->notificationService,
            logger: $logger
        );
    }//end setUp()

    /**
     * Helper: build an owner Secret.
     *
     * @param string $id      Secret ID
     * @param string $ownerId Owner Nextcloud user
     *
     * @return Secret
     */
    private function makeOwnerSecret(string $id, string $ownerId): Secret
    {
        $secret = new Secret();
        $secret->setId($id);
        $secret->setOwnerType('user');
        $secret->setOwnerId($ownerId);
        $secret->setName('demo');
        return $secret;
    }//end makeOwnerSecret()

    /**
     * Helper: build a mock IUser with a UID.
     *
     * @param string $uid The user ID
     *
     * @return IUser&MockObject
     */
    private function mockUser(string $uid): IUser
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        return $user;
    }//end mockUser()

    /**
     * Test createGroupShare returns the member fan-out, filtering owner +
     * members without a suite.
     *
     * @return void
     */
    public function testCreateGroupShareReturnsEligibleMembers(): void
    {
        $secret = $this->makeOwnerSecret('src-1', 'alice');
        $this->secretMapper->method('findById')->willReturn($secret);

        $group = $this->createMock(IGroup::class);
        $group->method('getUsers')->willReturn(
                [
                    $this->mockUser('alice'),
        // owner — excluded
                    $this->mockUser('bob'),
        // has suite
                    $this->mockUser('carol'),
        // no suite
                ]
                );
        $this->groupManager->method('get')->with('engineering')->willReturn($group);

        $this->mapper->method('findBySecretAndGroup')
            ->willThrowException(new DoesNotExistException('no'));
        $this->mapper->expects($this->once())->method('insert')
            ->willReturnArgument(0);

        $bobSuite = new EncryptionSuite();
        $bobSuite->setCertificate('PEM-BOB');
        $this->suiteMapper->method('findActiveByOwner')->willReturnCallback(
            static function (string $ownerType, string $ownerId) use ($bobSuite) {
                if ($ownerId === 'bob') {
                    return $bobSuite;
                }

                throw new DoesNotExistException('no');
            }
        );

        $result = $this->service->createGroupShare(
            secretId: 'src-1',
            groupId: 'engineering',
            userId: 'alice'
        );

        $this->assertInstanceOf(GroupShare::class, $result['groupShare']);
        $this->assertCount(1, $result['members']);
        $this->assertSame('bob', $result['members'][0]['userId']);
        $this->assertSame('PEM-BOB', $result['members'][0]['certificate']);
    }//end testCreateGroupShareReturnsEligibleMembers()

    /**
     * Test createGroupShare rejects unauthorized callers.
     *
     * @return void
     */
    public function testCreateGroupShareRejectsNonOwner(): void
    {
        $secret = $this->makeOwnerSecret('src-1', 'alice');
        $this->secretMapper->method('findById')->willReturn($secret);
        $this->delegationMapper->method('findActiveBySecretAndUser')
            ->willThrowException(new DoesNotExistException('no'));

        $this->mapper->expects($this->never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Not authorized');

        $this->service->createGroupShare(
            secretId: 'src-1',
            groupId: 'engineering',
            userId: 'mallory'
        );
    }//end testCreateGroupShareRejectsNonOwner()

    /**
     * Test createGroupShare rejects when the group is missing.
     *
     * @return void
     */
    public function testCreateGroupShareRejectsMissingGroup(): void
    {
        $secret = $this->makeOwnerSecret('src-1', 'alice');
        $this->secretMapper->method('findById')->willReturn($secret);
        $this->groupManager->method('get')->willReturn(null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Group not found');

        $this->service->createGroupShare(
            secretId: 'src-1',
            groupId: 'ghost',
            userId: 'alice'
        );
    }//end testCreateGroupShareRejectsMissingGroup()

    /**
     * Test createGroupShare is idempotent — reuses the existing GroupShare row.
     *
     * @return void
     */
    public function testCreateGroupShareIsIdempotent(): void
    {
        $secret = $this->makeOwnerSecret('src-1', 'alice');
        $this->secretMapper->method('findById')->willReturn($secret);

        $existing = new GroupShare();
        $existing->setId('gs-1');
        $this->mapper->method('findBySecretAndGroup')->willReturn($existing);
        $this->mapper->expects($this->never())->method('insert');

        $group = $this->createMock(IGroup::class);
        $group->method('getUsers')->willReturn([]);
        $this->groupManager->method('get')->willReturn($group);

        $result = $this->service->createGroupShare(
            secretId: 'src-1',
            groupId: 'engineering',
            userId: 'alice'
        );

        $this->assertSame($existing, $result['groupShare']);
    }//end testCreateGroupShareIsIdempotent()

    /**
     * Test revokeGroupShare cascade-deletes the ShareTargets and the row.
     *
     * @return void
     */
    public function testRevokeGroupShareCascades(): void
    {
        $secret = $this->makeOwnerSecret('src-1', 'alice');
        $this->secretMapper->method('findById')->willReturn($secret);

        $entity = new GroupShare();
        $entity->setId('gs-1');
        $entity->setSecretId('src-1');
        $this->mapper->method('findById')->willReturn($entity);

        $this->shareTargetMapper->expects($this->once())
            ->method('deleteByGroupShare')
            ->with('gs-1');
        $this->mapper->expects($this->once())->method('delete')->with($entity);

        $this->service->revokeGroupShare(groupShareId: 'gs-1', userId: 'alice');
    }//end testRevokeGroupShareCascades()

    /**
     * Test getGroupSharesForSecret returns rows to the owner.
     *
     * @return void
     */
    public function testGetGroupSharesForSecretOwnerSeesRows(): void
    {
        $secret = $this->makeOwnerSecret('src-1', 'alice');
        $this->secretMapper->method('findById')->willReturn($secret);

        $gs = new GroupShare();
        $gs->setId('gs-1');
        $this->mapper->method('findBySecret')->willReturn([$gs]);

        $result = $this->service->getGroupSharesForSecret('src-1', 'alice');

        $this->assertCount(1, $result);
    }//end testGetGroupSharesForSecretOwnerSeesRows()

    /**
     * Test getGroupSharesForSecret returns empty array to recipients.
     *
     * @return void
     */
    public function testGetGroupSharesForSecretRecipientSeesEmpty(): void
    {
        $secret = $this->makeOwnerSecret('src-1', 'alice');
        $this->secretMapper->method('findById')->willReturn($secret);
        $this->delegationMapper->method('findActiveBySecretAndUser')
            ->willThrowException(new DoesNotExistException('no'));

        $this->mapper->expects($this->never())->method('findBySecret');

        $result = $this->service->getGroupSharesForSecret('src-1', 'bob');

        $this->assertSame([], $result);
    }//end testGetGroupSharesForSecretRecipientSeesEmpty()

    /**
     * Test handleNewGroupMember dispatches a notification per group share
     * to the secret owner.
     *
     * @return void
     */
    public function testHandleNewGroupMemberNotifiesEachOwner(): void
    {
        $gs1 = new GroupShare();
        $gs1->setId('gs-1');
        $gs1->setSecretId('src-1');
        $gs2 = new GroupShare();
        $gs2->setId('gs-2');
        $gs2->setSecretId('src-2');
        $this->mapper->method('findByGroup')->willReturn([$gs1, $gs2]);

        $secret1 = $this->makeOwnerSecret('src-1', 'alice');
        $secret2 = $this->makeOwnerSecret('src-2', 'eve');
        $this->secretMapper->method('findById')->willReturnMap(
                [
                    ['src-1', $secret1],
                    ['src-2', $secret2],
                ]
                );

        $this->notificationService->expects($this->exactly(2))
            ->method('notify')
            ->with('group_member_added');

        $count = $this->service->handleNewGroupMember(userId: 'bob', groupId: 'engineering');
        $this->assertSame(2, $count);
    }//end testHandleNewGroupMemberNotifiesEachOwner()

    /**
     * Test approveGroupMemberShare persists a ShareTarget for the new member.
     *
     * @return void
     */
    public function testApproveGroupMemberShareCreatesShareTarget(): void
    {
        $secret = $this->makeOwnerSecret('src-1', 'alice');
        $this->secretMapper->method('findById')->willReturn($secret);

        $gs = new GroupShare();
        $gs->setId('gs-1');
        $gs->setSecretId('src-1');
        $this->mapper->method('findById')->willReturn($gs);

        $this->shareTargetMapper->method('findBySourceSecretAndTargetUser')
            ->willThrowException(new DoesNotExistException('no'));
        $this->shareTargetMapper->expects($this->once())->method('insert');
        $this->notificationService->expects($this->once())
            ->method('notify')
            ->with('secret_shared', 'bob');

        $this->service->approveGroupMemberShare(
            groupShareId: 'gs-1',
            newMemberId: 'bob',
            recipientSecretId: 'copy-bob',
            userId: 'alice'
        );
    }//end testApproveGroupMemberShareCreatesShareTarget()

    /**
     * Test handleMemberLeave revokes only group-derived shares for the
     * departing user.
     *
     * @return void
     */
    public function testHandleMemberLeaveRevokesGroupDerivedOnly(): void
    {
        $gs = new GroupShare();
        $gs->setId('gs-1');
        $gs->setSecretId('src-1');
        $this->mapper->method('findByGroup')->willReturn([$gs]);

        $bobTarget = new ShareTarget();
        $bobTarget->setId('st-1');
        $bobTarget->setTargetUserId('bob');
        $bobTarget->setSecretId('copy-bob');
        $bobTarget->setGroupShareId('gs-1');
        $aliceTarget = new ShareTarget();
        $aliceTarget->setId('st-2');
        $aliceTarget->setTargetUserId('alice');
        $aliceTarget->setSecretId('copy-alice');
        $aliceTarget->setGroupShareId('gs-1');

        $this->shareTargetMapper->method('findByGroupShare')
            ->willReturn([$bobTarget, $aliceTarget]);

        $copy = new Secret();
        $copy->setId('copy-bob');
        $this->secretMapper->method('findById')->willReturn($copy);
        $this->secretMapper->expects($this->once())->method('delete');
        $this->shareTargetMapper->expects($this->once())
            ->method('delete')
            ->with($bobTarget);

        $count = $this->service->handleMemberLeave(userId: 'bob', groupId: 'engineering');
        $this->assertSame(1, $count);
    }//end testHandleMemberLeaveRevokesGroupDerivedOnly()

    /**
     * Test getGroupMembers returns the UID list.
     *
     * @return void
     */
    public function testGetGroupMembersReturnsUids(): void
    {
        $group = $this->createMock(IGroup::class);
        $group->method('getUsers')->willReturn(
                [
                    $this->mockUser('alice'),
                    $this->mockUser('bob'),
                ]
                );
        $this->groupManager->method('get')->willReturn($group);

        $this->assertSame(['alice', 'bob'], $this->service->getGroupMembers('eng'));
    }//end testGetGroupMembersReturnsUids()

    /**
     * Test getGroupMembers returns empty array for unknown groups.
     *
     * @return void
     */
    public function testGetGroupMembersEmptyForUnknownGroup(): void
    {
        $this->groupManager->method('get')->willReturn(null);

        $this->assertSame([], $this->service->getGroupMembers('ghost'));
    }//end testGetGroupMembersEmptyForUnknownGroup()
}//end class
