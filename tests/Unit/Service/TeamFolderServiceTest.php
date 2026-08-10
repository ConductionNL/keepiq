<?php

/**
 * Unit tests for TeamFolderService (team-folder-sharing §6.1).
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
use OCA\Doriath\Db\EncryptionSuite;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\Folder;
use OCA\Doriath\Db\FolderMapper;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretDelegation;
use OCA\Doriath\Db\SecretDelegationMapper;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\ShareTarget;
use OCA\Doriath\Db\ShareTargetMapper;
use OCA\Doriath\Db\TeamFolder;
use OCA\Doriath\Db\TeamFolderMapper;
use OCA\Doriath\Db\TeamFolderMember;
use OCA\Doriath\Db\TeamFolderMemberMapper;
use OCA\Doriath\Service\NotificationService;
use OCA\Doriath\Service\RecipientSecretCopyService;
use OCA\Doriath\Service\SecretTypeService;
use OCA\Doriath\Service\TeamFolderAuditor;
use OCA\Doriath\Service\TeamFolderMembershipResolver;
use OCA\Doriath\Service\TeamFolderOffboardingService;
use OCA\Doriath\Service\TeamFolderQueryService;
use OCA\Doriath\Service\TeamFolderService;
use OCA\Doriath\Service\TeamFolderShareService;
use OCA\Doriath\Service\TeamSecretTransferService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for TeamFolderService.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The service under test
 *   threads through many mappers; the test mirrors its constructor.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)   One test per behaviour.
 */
class TeamFolderServiceTest extends TestCase
{

    private TeamFolderService $service;

    private TeamFolderMapper&MockObject $mapper;

    private TeamFolderMemberMapper&MockObject $memberMapper;

    private FolderMapper&MockObject $folderMapper;

    private SecretMapper&MockObject $secretMapper;

    private ShareTargetMapper&MockObject $shareTargetMapper;

    private EncryptionSuiteMapper&MockObject $suiteMapper;

    private SecretDelegationMapper&MockObject $delegationMapper;

    private SecretTypeService&MockObject $typeService;

    private IGroupManager&MockObject $groupManager;

    private IUserManager&MockObject $userManager;

    private NotificationService&MockObject $notificationService;

    /**
     * Build the service with fresh mocks.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper            = $this->createMock(originalClassName: TeamFolderMapper::class);
        $this->memberMapper      = $this->createMock(originalClassName: TeamFolderMemberMapper::class);
        $this->folderMapper      = $this->createMock(originalClassName: FolderMapper::class);
        $this->secretMapper      = $this->createMock(originalClassName: SecretMapper::class);
        $this->shareTargetMapper = $this->createMock(originalClassName: ShareTargetMapper::class);
        $this->suiteMapper       = $this->createMock(originalClassName: EncryptionSuiteMapper::class);
        $this->delegationMapper  = $this->createMock(originalClassName: SecretDelegationMapper::class);
        $this->typeService       = $this->createMock(originalClassName: SecretTypeService::class);
        $this->groupManager      = $this->createMock(originalClassName: IGroupManager::class);
        $this->userManager       = $this->createMock(originalClassName: IUserManager::class);
        $this->notificationService = $this->createMock(originalClassName: NotificationService::class);

        $memberships = new TeamFolderMembershipResolver(
            memberMapper: $this->memberMapper,
            folderMapper: $this->folderMapper,
            secretMapper: $this->secretMapper,
            suiteMapper: $this->suiteMapper,
            groupManager: $this->groupManager,
            userManager: $this->userManager,
        );

        $shares = new TeamFolderShareService(
            shareTargetMapper: $this->shareTargetMapper,
            copies: new RecipientSecretCopyService(
                secretMapper: $this->secretMapper,
                suiteMapper: $this->suiteMapper,
                typeService: $this->typeService,
            ),
            notificationService: $this->notificationService,
            db: $this->createMock(originalClassName: IDBConnection::class),
        );

        $this->service = new TeamFolderService(
            mapper: $this->mapper,
            memberMapper: $this->memberMapper,
            queries: new TeamFolderQueryService(
                mapper: $this->mapper,
                memberMapper: $this->memberMapper,
                folderMapper: $this->folderMapper,
                secretMapper: $this->secretMapper,
                groupManager: $this->groupManager,
                memberships: $memberships,
            ),
            memberships: $memberships,
            shares: $shares,
            offboarding: new TeamFolderOffboardingService(
                shares: $shares,
                transfers: new TeamSecretTransferService(
                    mapper: $this->mapper,
                    memberships: $memberships,
                    shareTargetMapper: $this->shareTargetMapper,
                    delegationMapper: $this->delegationMapper,
                    secretMapper: $this->secretMapper,
                ),
                groupManager: $this->groupManager,
                logger: $this->createMock(originalClassName: LoggerInterface::class),
                audit: new TeamFolderAuditor(eventDispatcher: null),
            ),
            audit: new TeamFolderAuditor(eventDispatcher: null),
            notificationService: $this->notificationService,
            db: $this->createMock(originalClassName: IDBConnection::class),
        );
    }//end setUp()

    /**
     * Build a Folder entity.
     *
     * @param string      $id       The folder id
     * @param string      $ownerId  The owner
     * @param string|null $parentId The parent folder id
     *
     * @return Folder
     */
    private function buildFolder(string $id, string $ownerId='alice', ?string $parentId=null): Folder
    {
        $folder = new Folder();
        $folder->setId($id);
        $folder->setName('Folder '.$id);
        $folder->setOwnerType('user');
        $folder->setOwnerId($ownerId);
        $folder->setParentId($parentId);
        return $folder;
    }//end buildFolder()

    /**
     * Build a TeamFolder entity.
     *
     * @param string $id       The team-folder id
     * @param string $folderId The attached folder id
     * @param string $ownerId  The owner
     *
     * @return TeamFolder
     */
    private function buildTeamFolder(string $id='tf-1', string $folderId='folder-1', string $ownerId='alice'): TeamFolder
    {
        $teamFolder = new TeamFolder();
        $teamFolder->setId($id);
        $teamFolder->setFolderId($folderId);
        $teamFolder->setOwnerId($ownerId);
        $teamFolder->setCreatedAt(new DateTime());
        return $teamFolder;
    }//end buildTeamFolder()

    /**
     * Build a membership row.
     *
     * @param string $type The member type
     * @param string $id   The member id
     * @param string $tfId The team-folder id
     *
     * @return TeamFolderMember
     */
    private function buildMember(string $type, string $id, string $tfId='tf-1'): TeamFolderMember
    {
        $member = new TeamFolderMember();
        $member->setId('m-'.$type.'-'.$id);
        $member->setTeamFolderId($tfId);
        $member->setMemberType($type);
        $member->setMemberId($id);
        return $member;
    }//end buildMember()

    /**
     * Build an IGroup mock returning the given user ids.
     *
     * @param string[] $userIds The member user ids
     *
     * @return IGroup
     */
    private function buildGroup(array $userIds): IGroup
    {
        $users = [];
        foreach ($userIds as $userId) {
            $user = $this->createMock(originalClassName: IUser::class);
            $user->method('getUID')->willReturn($userId);
            $users[] = $user;
        }

        $group = $this->createMock(originalClassName: IGroup::class);
        $group->method('getUsers')->willReturn($users);
        return $group;
    }//end buildGroup()

    /**
     * shareFolder rejects a caller who does not own the folder.
     *
     * @return void
     */
    public function testShareFolderRejectsNonOwner(): void
    {
        $this->folderMapper->method('findById')->willReturn($this->buildFolder(id: 'folder-1', ownerId: 'alice'));

        $this->expectException(InvalidArgumentException::class);
        $this->service->shareFolder(folderId: 'folder-1', userId: 'mallory');
    }//end testShareFolderRejectsNonOwner()

    /**
     * shareFolder is idempotent: an already-shared folder returns the
     * existing TeamFolder without inserting a duplicate.
     *
     * @return void
     */
    public function testShareFolderIdempotent(): void
    {
        $this->folderMapper->method('findById')->willReturn($this->buildFolder(id: 'folder-1'));
        $existing = $this->buildTeamFolder();
        $this->mapper->method('findByFolder')->willReturn($existing);
        $this->mapper->expects($this->never())->method('insert');

        $result = $this->service->shareFolder(folderId: 'folder-1', userId: 'alice');
        $this->assertSame($existing, $result);
    }//end testShareFolderIdempotent()

    /**
     * addMember expands a group and skips members without an active
     * EncryptionSuite silently (they appear in no recipient list).
     *
     * @return void
     */
    public function testAddMemberSkipsSuitelessGroupMembers(): void
    {
        $this->mapper->method('findById')->willReturn($this->buildTeamFolder());
        $this->groupManager->method('get')->willReturn($this->buildGroup(userIds: ['bob', 'carol']));
        $this->memberMapper->method('findByTeamFolder')->willReturn([]);
        $this->memberMapper->method('findMembership')
            ->willThrowException(new DoesNotExistException(''));
        $this->memberMapper->method('insert')->willReturnArgument(0);
        $this->folderMapper->method('getSubtreeIds')->willReturn(['folder-1']);
        $this->secretMapper->method('findByOwner')->willReturn([]);

        // bob has a suite; carol does not.
        $suite = new EncryptionSuite();
        $suite->setId('suite-bob');
        $suite->setCertificate('-----BEGIN CERTIFICATE-----BOB');
        $this->suiteMapper->method('findActiveByOwner')
            ->willReturnCallback(
                static function (string $ownerType, string $ownerId) use ($suite) {
                    if ($ownerId === 'bob') {
                        return $suite;
                    }

                    throw new DoesNotExistException('');
                }
            );

        $payload = $this->service->addMember(
            teamFolderId: 'tf-1',
            memberType: 'group',
            memberId: 'devops',
            userId: 'alice'
        );

        $this->assertCount(1, $payload['recipients']);
        $this->assertSame('bob', $payload['recipients'][0]['userId']);
    }//end testAddMemberSkipsSuitelessGroupMembers()

    /**
     * resolveRecipients unions memberships along the folder ancestor
     * chain (nested subfolders inherit, union-only).
     *
     * @return void
     */
    public function testResolveRecipientsUnionsAncestorChain(): void
    {
        $secret = new Secret();
        $secret->setId('sec-1');
        $secret->setOwnerType('user');
        $secret->setOwnerId('alice');
        $secret->setFolderId('child');
        $this->secretMapper->method('findById')->willReturn($secret);

        $child  = $this->buildFolder(id: 'child', parentId: 'parent');
        $parent = $this->buildFolder(id: 'parent');
        $this->folderMapper->method('findById')->willReturnCallback(
            static fn (string $id) => $id === 'child' ? $child : $parent
        );

        $childTf  = $this->buildTeamFolder(id: 'tf-child', folderId: 'child');
        $parentTf = $this->buildTeamFolder(id: 'tf-parent', folderId: 'parent');
        $this->mapper->method('findByFolder')->willReturnCallback(
            static function (string $folderId) use ($childTf, $parentTf) {
                if ($folderId === 'child') {
                    return $childTf;
                }

                if ($folderId === 'parent') {
                    return $parentTf;
                }

                throw new DoesNotExistException('');
            }
        );

        $this->memberMapper->method('findByTeamFolder')->willReturnCallback(
            fn (string $teamFolderId) => $teamFolderId === 'tf-child' ? [$this->buildMember(type: 'user', id: 'bob', tfId: 'tf-child')] : [$this->buildMember(type: 'user', id: 'carol', tfId: 'tf-parent')]
        );

        $recipients = $this->service->resolveRecipients(secretId: 'sec-1');
        sort($recipients);
        $this->assertSame(['bob', 'carol'], $recipients);
    }//end testResolveRecipientsUnionsAncestorChain()

    /**
     * registerFanOutShares creates the recipient copy + ShareTarget with
     * team_folder_id provenance, and a retried row is a no-op (idempotent
     * upsert — never a double share).
     *
     * @return void
     */
    public function testRegisterFanOutSharesIdempotent(): void
    {
        $this->mapper->method('findById')->willReturn($this->buildTeamFolder());
        $this->folderMapper->method('getSubtreeIds')->willReturn(['folder-1']);

        $source = new Secret();
        $source->setId('sec-1');
        $source->setName('Wiki admin');
        $source->setOwnerType('user');
        $source->setOwnerId('alice');
        $source->setTypeId('type-login');
        $this->secretMapper->method('findByOwner')->willReturn([$source]);
        $this->secretMapper->method('findById')->willReturn($source);

        $suite = new EncryptionSuite();
        $suite->setId('suite-bob');
        $suite->setCertificate('CERT');
        $this->suiteMapper->method('findActiveByOwner')->willReturn($suite);
        $this->typeService->method('resolveTypeForSecret')->willReturn('type-login');

        // First call: no existing pair → creates. Second call: pair exists.
        $firstRun = true;
        $this->shareTargetMapper->method('findBySourceSecretAndTargetUser')
            ->willReturnCallback(
                static function () use (&$firstRun) {
                    if ($firstRun === true) {
                        $firstRun = false;
                        throw new DoesNotExistException('');
                    }

                    return new ShareTarget();
                }
            );

        $insertedTargets = [];
        $this->shareTargetMapper->method('insert')->willReturnCallback(
            static function (ShareTarget $row) use (&$insertedTargets) {
                $insertedTargets[] = $row;
                return $row;
            }
        );
        $insertedCopies = [];
        $this->secretMapper->method('insert')->willReturnCallback(
            static function (Secret $row) use (&$insertedCopies) {
                $insertedCopies[] = $row;
                return $row;
            }
        );

        $row = [
            'sourceSecretId' => 'sec-1',
            'targetUserId'   => 'bob',
            'encryptedKey'   => 'CIPHERTEXT_PLACEHOLDER',
        ];

        $result = $this->service->registerFanOutShares(teamFolderId: 'tf-1', shares: [$row], userId: 'alice');
        $this->assertSame(1, $result['created']);
        // The created descriptors carry the copy id so the client can
        // re-wrap attachment file keys for the new recipient.
        $this->assertCount(1, $result['rows']);
        $this->assertSame('bob', $result['rows'][0]['targetUserId']);
        $this->assertSame($insertedCopies[0]->getId(), $result['rows'][0]['recipientSecretId']);
        $this->assertCount(1, $insertedTargets);
        $this->assertSame('tf-1', $insertedTargets[0]->getTeamFolderId());
        $this->assertCount(1, $insertedCopies);
        $this->assertSame('bob', $insertedCopies[0]->getOwnerId());

        // Retry: the pair now exists → no new rows, count 0.
        $resultRetry = $this->service->registerFanOutShares(teamFolderId: 'tf-1', shares: [$row], userId: 'alice');
        $this->assertSame(0, $resultRetry['created']);
        $this->assertSame([], $resultRetry['rows']);
        $this->assertCount(1, $insertedTargets);
    }//end testRegisterFanOutSharesIdempotent()

    /**
     * removeMember revokes only the derived copies of users no longer
     * covered by any remaining membership.
     *
     * @return void
     */
    public function testRemoveMemberRevokesOnlyUncoveredUsers(): void
    {
        $this->mapper->method('findById')->willReturn($this->buildTeamFolder());

        $groupMembership = $this->buildMember(type: 'group', id: 'devops');
        $this->memberMapper->method('findById')->willReturn($groupMembership);
        // After deletion, bob is STILL covered by a direct user membership;
        // carol is not.
        $this->memberMapper->method('findByTeamFolder')
            ->willReturn([$this->buildMember(type: 'user', id: 'bob')]);
        $this->groupManager->method('get')->willReturn($this->buildGroup(userIds: ['bob', 'carol']));

        $carolShare = new ShareTarget();
        $carolShare->setId('st-carol');
        $carolShare->setSecretId('copy-carol');
        $this->shareTargetMapper->method('findByTeamFolderAndTargetUser')
            ->willReturnCallback(
                static fn (string $teamFolderId, string $targetUserId) => $targetUserId === 'carol' ? [$carolShare] : []
            );
        $this->secretMapper->method('findById')->willThrowException(new DoesNotExistException(''));

        $deleted = [];
        $this->shareTargetMapper->method('delete')->willReturnCallback(
            static function (ShareTarget $row) use (&$deleted) {
                $deleted[] = $row->getId();
                return $row;
            }
        );

        $revoked = $this->service->removeMember(teamFolderId: 'tf-1', membershipId: 'm-group-devops', userId: 'alice');
        $this->assertSame(1, $revoked);
        $this->assertSame(['st-carol'], $deleted);
    }//end testRemoveMemberRevokesOnlyUncoveredUsers()

    /**
     * handleGroupMemberJoin notifies the folder owner (approval required
     * before any share is created).
     *
     * @return void
     */
    public function testGroupJoinNotifiesOwnerWithoutSharing(): void
    {
        $this->memberMapper->method('findGroupMemberships')
            ->willReturn([$this->buildMember(type: 'group', id: 'devops')]);
        $this->mapper->method('findById')->willReturn($this->buildTeamFolder());
        $this->shareTargetMapper->expects($this->never())->method('insert');
        $this->notificationService->expects($this->once())->method('notify')
            ->with(
                subject: 'team_folder_join_request',
                recipientId: 'alice',
                params: $this->anything(),
                objectType: 'team_folder',
                objectId: 'tf-1',
            );

        $count = $this->service->handleGroupMemberJoin(userId: 'dave', groupId: 'devops');
        $this->assertSame(1, $count);
    }//end testGroupJoinNotifiesOwnerWithoutSharing()

    /**
     * handleGroupMemberLeave auto-revokes derived shares, but keeps them
     * when another membership still covers the user.
     *
     * @return void
     */
    public function testGroupLeaveRevokesUnlessStillCovered(): void
    {
        $this->memberMapper->method('findGroupMemberships')
            ->willReturn([$this->buildMember(type: 'group', id: 'devops')]);

        // Remaining membership does NOT cover dave.
        $this->memberMapper->method('findByTeamFolder')
            ->willReturn([$this->buildMember(type: 'user', id: 'bob')]);

        $daveShare = new ShareTarget();
        $daveShare->setId('st-dave');
        $daveShare->setSecretId('copy-dave');
        $this->shareTargetMapper->method('findByTeamFolderAndTargetUser')->willReturn([$daveShare]);
        $this->secretMapper->method('findById')->willThrowException(new DoesNotExistException(''));

        $revoked = $this->service->handleGroupMemberLeave(userId: 'dave', groupId: 'devops');
        $this->assertSame(1, $revoked);
    }//end testGroupLeaveRevokesUnlessStillCovered()

    /**
     * handleGroupMemberLeave keeps shares when a direct membership still
     * covers the departing user.
     *
     * @return void
     */
    public function testGroupLeaveKeepsCoveredUser(): void
    {
        $this->memberMapper->method('findGroupMemberships')
            ->willReturn([$this->buildMember(type: 'group', id: 'devops')]);
        // dave is ALSO a direct member.
        $this->memberMapper->method('findByTeamFolder')
            ->willReturn([$this->buildMember(type: 'user', id: 'dave')]);
        $this->shareTargetMapper->expects($this->never())->method('delete');

        $revoked = $this->service->handleGroupMemberLeave(userId: 'dave', groupId: 'devops');
        $this->assertSame(0, $revoked);
    }//end testGroupLeaveKeepsCoveredUser()

    /**
     * offboard requires admin authorization.
     *
     * @return void
     */
    public function testOffboardRejectsNonAdmin(): void
    {
        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->groupManager->method('isInGroup')->willReturn(false);

        $this->expectException(InvalidArgumentException::class);
        $this->service->offboard(leavingUserId: 'dave', successorUserId: 'bob', adminId: 'mallory');
    }//end testOffboardRejectsNonAdmin()

    /**
     * offboard revokes the leaver's team-derived shares and transfers
     * owned team secrets via a permanent delegation + owner reassignment;
     * secrets without a successor copy are skipped.
     *
     * @return void
     */
    public function testOffboardRevokesAndTransfers(): void
    {
        $this->groupManager->method('isAdmin')->willReturn(true);

        // Leaver holds one team-derived share + one direct share.
        $teamShare = new ShareTarget();
        $teamShare->setId('st-team');
        $teamShare->setSecretId('copy-1');
        $teamShare->setTeamFolderId('tf-x');
        $directShare = new ShareTarget();
        $directShare->setId('st-direct');
        $directShare->setSecretId('copy-2');
        $this->shareTargetMapper->method('findByTargetUser')->willReturn([$teamShare, $directShare]);

        $deletedShareIds = [];
        $this->shareTargetMapper->method('delete')->willReturnCallback(
            static function (ShareTarget $row) use (&$deletedShareIds) {
                $deletedShareIds[] = $row->getId();
                return $row;
            }
        );

        // Leaver owns one team folder containing two secrets.
        $this->mapper->method('findByOwner')->willReturn(
            [$this->buildTeamFolder(id: 'tf-dave', folderId: 'folder-dave', ownerId: 'dave')]
        );
        $this->folderMapper->method('getSubtreeIds')->willReturn(['folder-dave']);
        $secretA = new Secret();
        $secretA->setId('sec-a');
        $secretA->setName('A');
        $secretB = new Secret();
        $secretB->setId('sec-b');
        $secretB->setName('B');
        $this->secretMapper->method('findByOwner')->willReturn([$secretA, $secretB]);
        $this->secretMapper->method('findById')->willThrowException(new DoesNotExistException(''));

        // Successor holds a copy of sec-a only.
        $this->shareTargetMapper->method('findBySourceSecretAndTargetUser')
            ->willReturnCallback(
                static function (string $sourceSecretId, string $targetUserId) {
                    if ($sourceSecretId === 'sec-a') {
                        return new ShareTarget();
                    }

                    throw new DoesNotExistException('');
                }
            );

        $insertedDelegations = [];
        $this->delegationMapper->method('insert')->willReturnCallback(
            static function (SecretDelegation $row) use (&$insertedDelegations) {
                $insertedDelegations[] = $row;
                return $row;
            }
        );
        $reassigned = [];
        $this->secretMapper->method('reassignOwner')->willReturnCallback(
            static function (string $secretId, string $newOwnerId) use (&$reassigned): void {
                $reassigned[] = [$secretId, $newOwnerId];
            }
        );

        $summary = $this->service->offboard(leavingUserId: 'dave', successorUserId: 'bob', adminId: 'admin');

        $this->assertSame(1, $summary['revoked']);
        $this->assertSame(['st-team'], $deletedShareIds);
        $this->assertSame(1, $summary['transferred']);
        $this->assertSame(['sec-b'], $summary['skipped']);
        $this->assertCount(1, $insertedDelegations);
        $this->assertTrue($insertedDelegations[0]->getIsPermanent());
        $this->assertSame('bob', $insertedDelegations[0]->getDelegatedTo());
        $this->assertSame([['sec-a', 'bob']], $reassigned);
    }//end testOffboardRevokesAndTransfers()

    /**
     * folder-permission-grades §5.1: setMemberGrade is owner-only,
     * rejects invalid grades, and touches no secret rows (ciphertext).
     *
     * @return void
     */
    public function testSetMemberGradeOwnerOnlyNoCiphertext(): void
    {
        $teamFolder = new \OCA\Doriath\Db\TeamFolder();
        $teamFolder->setId('tf-1');
        $teamFolder->setFolderId('folder-1');
        $teamFolder->setOwnerId('alice');
        $this->mapper->method('findById')->willReturn($teamFolder);

        $member = new \OCA\Doriath\Db\TeamFolderMember();
        $member->setId('mem-1');
        $member->setTeamFolderId('tf-1');
        $member->setMemberType('user');
        $member->setMemberId('bob');
        $this->memberMapper->method('findById')->willReturn($member);
        $this->memberMapper->method('update')->willReturnCallback(static fn ($row) => $row);
        // No ciphertext is touched: the secret mapper is never written.
        $this->secretMapper->expects($this->never())->method('update');

        // Non-owner rejected.
        try {
            $this->service->setMemberGrade(teamFolderId: 'tf-1', memberId: 'mem-1', grade: 'write', ownerId: 'mallory');
            $this->fail('Non-owner grade change must be rejected');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Not authorized', $exception->getMessage());
        }

        // Invalid grade rejected.
        try {
            $this->service->setMemberGrade(teamFolderId: 'tf-1', memberId: 'mem-1', grade: 'admin', ownerId: 'alice');
            $this->fail('Invalid grade must be rejected');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('grade must be', $exception->getMessage());
        }

        $updated = $this->service->setMemberGrade(teamFolderId: 'tf-1', memberId: 'mem-1', grade: 'write', ownerId: 'alice');
        $this->assertSame('write', $updated->effectiveGrade());
    }//end testSetMemberGradeOwnerOnlyNoCiphertext()

    /**
     * folder-permission-grades §5.1: resolveGrade returns the MAX grade
     * along the folder ancestor chain and expands group memberships;
     * the default (legacy) grade reads as `read`.
     *
     * @return void
     */
    public function testResolveGradeMaxAlongAncestorsAndGroups(): void
    {
        $secret = new \OCA\Doriath\Db\Secret();
        $secret->setId('sec-1');
        $secret->setOwnerType('user');
        $secret->setOwnerId('alice');
        $secret->setFolderId('child');

        // child (team folder tf-child: bob read) -> parent (tf-parent: group g1 write).
        $childFolder = new \OCA\Doriath\Db\Folder();
        $childFolder->setId('child');
        $childFolder->setParentId('parent');
        $parentFolder = new \OCA\Doriath\Db\Folder();
        $parentFolder->setId('parent');
        $parentFolder->setParentId(null);
        $this->folderMapper->method('findById')->willReturnCallback(
            static fn (string $id) => $id === 'child' ? $childFolder : $parentFolder
        );

        $tfChild = new \OCA\Doriath\Db\TeamFolder();
        $tfChild->setId('tf-child');
        $tfChild->setFolderId('child');
        $tfChild->setOwnerId('alice');
        $tfParent = new \OCA\Doriath\Db\TeamFolder();
        $tfParent->setId('tf-parent');
        $tfParent->setFolderId('parent');
        $tfParent->setOwnerId('alice');
        $this->mapper->method('findByFolder')->willReturnCallback(
            static fn (string $folderId) => $folderId === 'child' ? $tfChild : $tfParent
        );

        $readMember = new \OCA\Doriath\Db\TeamFolderMember();
        $readMember->setId('m-read');
        $readMember->setTeamFolderId('tf-child');
        $readMember->setMemberType('user');
        $readMember->setMemberId('bob');
        // Legacy row: grade never set — reads as `read`.
        $groupWrite = new \OCA\Doriath\Db\TeamFolderMember();
        $groupWrite->setId('m-write');
        $groupWrite->setTeamFolderId('tf-parent');
        $groupWrite->setMemberType('group');
        $groupWrite->setMemberId('g1');
        $groupWrite->setGrade('write');
        $this->memberMapper->method('findByTeamFolder')->willReturnCallback(
            static fn (string $teamFolderId) => $teamFolderId === 'tf-child' ? [$readMember] : [$groupWrite]
        );
        $this->groupManager->method('isInGroup')->willReturnCallback(
            static fn (string $userId, string $groupId): bool => $userId === 'bob' && $groupId === 'g1'
        );

        // bob: read on the child, write via g1 on the parent -> MAX = write.
        $this->assertSame('write', $this->service->resolveGrade(secret: $secret, userId: 'bob'));
        // carol: no memberships anywhere -> null.
        $this->assertNull($this->service->resolveGrade(secret: $secret, userId: 'carol'));
    }//end testResolveGradeMaxAlongAncestorsAndGroups()
}//end class
