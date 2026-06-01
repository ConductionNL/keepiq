<?php

/**
 * Doriath Group Share Service
 *
 * Server-side orchestration of Nextcloud group-based secret sharing: static
 * expansion to individual SecretShares, revocation cascade, new-member
 * notification + approval, and auto-revocation on member leave.
 *
 * @category Service
 * @package  OCA\Doriath\Service
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

namespace OCA\Doriath\Service;

use DateTime;
use OCA\Doriath\Db\GroupShare;
use OCA\Doriath\Db\GroupShareMapper;
use OCA\Doriath\Db\SecretShareMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Business logic for Nextcloud group-based secret sharing.
 */
class GroupShareService
{
    /**
     * Constructor for GroupShareService.
     *
     * @param GroupShareMapper        $groupShareMapper    The group share mapper
     * @param SecretShareMapper       $shareMapper         The secret share mapper
     * @param SecretOwnershipResolver $ownership           The ownership resolver
     * @param SecretCopyGateway       $copyGateway         The secret copy gateway
     * @param NotificationService     $notificationService The notification service
     * @param IGroupManager           $groupManager        The Nextcloud group manager
     *
     * @return void
     */
    public function __construct(
        private GroupShareMapper $groupShareMapper,
        private SecretShareMapper $shareMapper,
        private SecretOwnershipResolver $ownership,
        private SecretCopyGateway $copyGateway,
        private NotificationService $notificationService,
        private IGroupManager $groupManager,
    ) {
    }//end __construct()

    /**
     * Create a group share and return the list of members to encrypt for.
     *
     * Members without an active EncryptionSuite and the owner themselves are
     * excluded. The browser encrypts for the returned members and POSTs the
     * batch of encrypted copies.
     *
     * @param string $secretId The secret ID
     * @param string $groupId  The Nextcloud group ID
     * @param string $userId   The acting user (owner or delegate)
     *
     * @return array{groupShare:GroupShare,eligibleMembers:string[]}
     *
     * @throws RuntimeException When unauthorized or the group does not exist
     */
    public function createGroupShare(string $secretId, string $groupId, string $userId): array
    {
        if ($this->ownership->canManageShares($secretId, $userId) === false) {
            throw new RuntimeException('Not authorized to share this secret');
        }

        $group = $this->groupManager->get($groupId);
        if ($group === null) {
            throw new RuntimeException('Group not found');
        }

        $eligible = $this->resolveEligibleMembers($group->getUsers(), $userId);

        $groupShare = new GroupShare();
        $groupShare->setId(Uuid::uuid4()->toString());
        $groupShare->setSecretId($secretId);
        $groupShare->setGroupId($groupId);
        $groupShare->setCreatedBy($userId);
        $groupShare->setCreatedAt(new DateTime());
        $groupShare = $this->groupShareMapper->insert($groupShare);

        return [
            'groupShare'      => $groupShare,
            'eligibleMembers' => $eligible,
        ];
    }//end createGroupShare()

    /**
     * Revoke a group share, cascade-deleting all derived shares and copies.
     *
     * @param string $groupShareId The group share ID
     * @param string $userId       The acting user
     *
     * @return void
     *
     * @throws RuntimeException When unauthorized or the group share is missing
     */
    public function revokeGroupShare(string $groupShareId, string $userId): void
    {
        try {
            $groupShare = $this->groupShareMapper->findById($groupShareId);
        } catch (DoesNotExistException $e) {
            throw new RuntimeException('Group share not found');
        }

        if ($this->ownership->canManageShares($groupShare->getSecretId(), $userId) === false) {
            throw new RuntimeException('Not authorized to revoke this group share');
        }

        $shares = $this->shareMapper->deleteByGroupShare($groupShareId);
        foreach ($shares as $share) {
            $this->copyGateway->deleteCopy((string) $share->getSecretId());
        }

        $this->groupShareMapper->delete($groupShare);
    }//end revokeGroupShare()

    /**
     * Return the group shares for a secret — owner/delegate only.
     *
     * @param string $secretId The secret ID
     * @param string $userId   The acting user
     *
     * @return GroupShare[]
     */
    public function getGroupSharesForSecret(string $secretId, string $userId): array
    {
        if ($this->ownership->canManageShares($secretId, $userId) === false) {
            return [];
        }

        return $this->groupShareMapper->findBySecret($secretId);
    }//end getGroupSharesForSecret()

    /**
     * Enumerate the members of a group.
     *
     * @param string $groupId The group ID
     *
     * @return string[] User IDs
     */
    public function getGroupMembers(string $groupId): array
    {
        $group = $this->groupManager->get($groupId);
        if ($group === null) {
            return [];
        }

        return array_map(static fn ($user) => $user->getUID(), $group->getUsers());
    }//end getGroupMembers()

    /**
     * Notify secret owners that a new member joined a group with group shares.
     *
     * Notifications are batched per owner: one notification summarising the
     * number of secrets that need approval, rather than one per secret.
     *
     * @param string $userId  The newly added member
     * @param string $groupId The group joined
     *
     * @return void
     */
    public function handleNewGroupMember(string $userId, string $groupId): void
    {
        $groupShares = $this->groupShareMapper->findByGroup($groupId);
        if (count($groupShares) === 0) {
            return;
        }

        $byOwner = [];
        foreach ($groupShares as $groupShare) {
            $ownerId = $this->ownership->getOwnerId($groupShare->getSecretId());
            if ($ownerId === null || $ownerId === $userId) {
                continue;
            }

            $byOwner[$ownerId] = (($byOwner[$ownerId] ?? 0) + 1);
        }

        foreach ($byOwner as $ownerId => $secretCount) {
            $this->notificationService->notify(
                'group_member_added',
                (string) $ownerId,
                [
                    'actorId'     => $userId,
                    'groupId'     => $groupId,
                    'secretCount' => $secretCount,
                ],
                $groupId
            );
        }
    }//end handleNewGroupMember()

    /**
     * Approve adding a new group member: create their derived share.
     *
     * @param string              $groupShareId  The group share ID
     * @param string              $newMemberId   The member to grant access
     * @param array<string,mixed> $encryptedData Client-encrypted blobs + metadata
     * @param string              $userId        The approving owner/delegate
     *
     * @return void
     *
     * @throws RuntimeException When unauthorized or the member has no suite
     */
    public function approveGroupMemberShare(
        string $groupShareId,
        string $newMemberId,
        array $encryptedData,
        string $userId,
    ): void {
        try {
            $groupShare = $this->groupShareMapper->findById($groupShareId);
        } catch (DoesNotExistException $e) {
            throw new RuntimeException('Group share not found');
        }

        $secretId = $groupShare->getSecretId();
        if ($this->ownership->canManageShares($secretId, $userId) === false) {
            throw new RuntimeException('Not authorized to approve this share');
        }

        if ($this->ownership->getActiveSuiteForUser($newMemberId) === null) {
            throw new RuntimeException('New member has no active encryption suite');
        }

        $suite = $this->ownership->getActiveSuiteForUser($newMemberId);

        $copyId = $this->copyGateway->createCopy($newMemberId, $suite->getId(), $encryptedData);

        $share = new \OCA\Doriath\Db\SecretShare();
        $share->setId(Uuid::uuid4()->toString());
        $share->setSourceSecretId($secretId);
        $share->setTargetUserId($newMemberId);
        $share->setSecretId($copyId);
        $share->setGroupShareId($groupShareId);
        $share->setCreatedAt(new DateTime());
        $this->shareMapper->insert($share);

        $this->notificationService->notify(
            'secret_shared',
            $newMemberId,
            [
                'actorId'    => $userId,
                'secretName' => (string) ($encryptedData['name'] ?? ''),
            ],
            $secretId
        );
    }//end approveGroupMemberShare()

    /**
     * Auto-revoke group-derived shares when a user leaves a group.
     *
     * Direct shares (group_share_id null) for the same secret are untouched.
     *
     * @param string $userId  The departing member
     * @param string $groupId The group left
     *
     * @return void
     */
    public function handleMemberLeave(string $userId, string $groupId): void
    {
        $groupShareIds = array_map(
            static fn ($groupShare) => $groupShare->getId(),
            $this->groupShareMapper->findByGroup($groupId)
        );
        if (count($groupShareIds) === 0) {
            return;
        }

        foreach ($this->shareMapper->findByTargetUser($userId) as $share) {
            $groupShareId = $share->getGroupShareId();
            if ($groupShareId === null || in_array($groupShareId, $groupShareIds, true) === false) {
                continue;
            }

            $this->copyGateway->deleteCopy((string) $share->getSecretId());
            $this->shareMapper->delete($share);
        }
    }//end handleMemberLeave()

    /**
     * Filter a list of group users down to eligible share recipients.
     *
     * @param array<int,\OCP\IUser> $users   The group's users
     * @param string                $ownerId The owner to exclude
     *
     * @return string[] Eligible recipient user IDs
     */
    private function resolveEligibleMembers(array $users, string $ownerId): array
    {
        $eligible = [];
        foreach ($users as $user) {
            $uid = $user->getUID();
            if ($uid === $ownerId) {
                continue;
            }

            if ($this->ownership->getActiveSuiteForUser($uid) === null) {
                continue;
            }

            $eligible[] = $uid;
        }

        return $eligible;
    }//end resolveEligibleMembers()
}//end class
