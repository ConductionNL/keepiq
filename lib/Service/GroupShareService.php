<?php

/**
 * Doriath Group Share Service
 *
 * Business logic for the GroupShare lifecycle: createGroupShare returns
 * the member fan-out the browser needs to encrypt for each eligible
 * member, revokeGroupShare cascades through every ShareTarget the group
 * fan-out produced, handleNewGroupMember notifies the secret owner so
 * they can approve the new member, approveGroupMemberShare creates the
 * ShareTarget for that member, and handleMemberLeave revokes only the
 * group-derived shares (direct shares stay intact).
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
use InvalidArgumentException;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\GroupShare;
use OCA\Doriath\Db\GroupShareMapper;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\BulkGrantShareTargetMapper;
use OCA\Doriath\Db\SecretDelegationMapper;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\ShareTarget;
use OCA\Doriath\Db\ShareTargetMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Business logic for the GroupShare lifecycle.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The service threads
 *   through five mappers + IGroupManager + the share/notification helpers
 *   so the group-fan-out flow lives in one place; splitting it would
 *   scatter the invariants over four classes.
 */
class GroupShareService
{
    /**
     * Constructor for GroupShareService.
     *
     * @param GroupShareMapper           $mapper              The group-share mapper
     * @param ShareTargetMapper          $shareTargetMapper   The share-target mapper (per-row reads/writes)
     * @param BulkGrantShareTargetMapper $bulkGrantMapper     The share-target mapper keyed on the grant (cascade)
     * @param SecretMapper               $secretMapper        The Secret mapper (owner lookup)
     * @param EncryptionSuiteMapper      $suiteMapper         The suite mapper (member-eligibility filter)
     * @param SecretDelegationMapper     $delegationMapper    The delegation mapper (delegate authorization)
     * @param IGroupManager              $groupManager        The Nextcloud group manager
     * @param NotificationService        $notificationService The notification dispatcher
     * @param LoggerInterface            $logger              The logger
     *
     * @return void
     */
    public function __construct(
        private GroupShareMapper $mapper,
        private ShareTargetMapper $shareTargetMapper,
        private BulkGrantShareTargetMapper $bulkGrantMapper,
        private SecretMapper $secretMapper,
        private EncryptionSuiteMapper $suiteMapper,
        private SecretDelegationMapper $delegationMapper,
        private IGroupManager $groupManager,
        private NotificationService $notificationService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Create a group share — returns the per-member fan-out the browser
     * needs (each entry has the recipient's user ID and PEM certificate so
     * the browser can RSA-encrypt the source secret for that member). The
     * owner and members without an active EncryptionSuite are filtered out
     * server-side so the browser doesn't have to know who is "eligible".
     *
     * @param string $secretId The source Secret ID
     * @param string $groupId  The Nextcloud group ID
     * @param string $userId   The initiator (must be owner or delegate)
     *
     * @return array{groupShare:GroupShare,members:array<int,array{userId:string,certificate:string}>}
     *
     * @throws InvalidArgumentException On unauthorized / missing secret / empty group
     *
     * @spec openspec/changes/implement-user-sharing/tasks.md#4.2
     */
    public function createGroupShare(string $secretId, string $groupId, string $userId): array
    {
        if ($groupId === '') {
            throw new InvalidArgumentException(message: 'groupId is required');
        }

        $secret = $this->loadSecret(secretId: $secretId);
        $this->assertOwnerOrDelegate(secret: $secret, userId: $userId);

        $group = $this->groupManager->get($groupId);
        if ($group === null) {
            throw new InvalidArgumentException(message: 'Group not found');
        }

        // Idempotency — one GroupShare per (secret, group). Surface the
        // existing one with a fresh member list rather than persisting a
        // duplicate.
        try {
            $existing = $this->mapper->findBySecretAndGroup(secretId: $secretId, groupId: $groupId);
        } catch (DoesNotExistException) {
            $existing = null;
        }

        if ($existing === null) {
            $row = new GroupShare();
            $row->setId(Uuid::uuid4()->toString());
            $row->setSecretId($secretId);
            $row->setGroupId($groupId);
            $row->setCreatedBy($userId);
            $row->setCreatedAt(new DateTime());
            $existing = $this->mapper->insert($row);
        }

        $members = [];
        foreach ($group->getUsers() as $user) {
            $candidateId = $user->getUID();
            if ($candidateId === $secret->getOwnerId()) {
                continue;
            }

            try {
                $suite = $this->suiteMapper->findActiveByOwner(
                    ownerType: 'user',
                    ownerId: $candidateId
                );
            } catch (DoesNotExistException) {
                continue;
            }

            $members[] = [
                'userId'      => $candidateId,
                'certificate' => $suite->getCertificate(),
            ];
        }

        return [
            'groupShare' => $existing,
            'members'    => $members,
        ];
    }//end createGroupShare()

    /**
     * Revoke a group share — cascade-deletes every ShareTarget that was
     * fanned out from it, then deletes the GroupShare row itself.
     *
     * @param string $groupShareId The GroupShare row ID
     * @param string $userId       The Nextcloud user requesting the revoke
     *
     * @return void
     *
     * @throws InvalidArgumentException On unauthorized / not found
     *
     * @spec openspec/changes/implement-user-sharing/tasks.md#4.3
     */
    public function revokeGroupShare(string $groupShareId, string $userId): void
    {
        try {
            $entity = $this->mapper->findById($groupShareId);
        } catch (DoesNotExistException) {
            throw new InvalidArgumentException(message: 'Group share not found');
        }

        $secret = $this->loadSecret(secretId: $entity->getSecretId());
        $this->assertOwnerOrDelegate(secret: $secret, userId: $userId);

        $this->bulkGrantMapper->deleteByGroupShare(groupShareId: $groupShareId);
        $this->mapper->delete($entity);

        $this->logger->info(
            'Revoked group share '.$groupShareId.' for secret '.$entity->getSecretId(),
            ['app' => 'doriath']
        );
    }//end revokeGroupShare()

    /**
     * List the group shares for a given source secret — only visible to
     * the owner or an active delegate.
     *
     * @param string $secretId The source Secret ID
     * @param string $userId   The requesting user
     *
     * @return GroupShare[]
     *
     * @spec openspec/changes/implement-user-sharing/tasks.md#4.1
     */
    public function getGroupSharesForSecret(string $secretId, string $userId): array
    {
        try {
            $secret = $this->loadSecret(secretId: $secretId);
        } catch (InvalidArgumentException) {
            return [];
        }

        if ($this->isOwnerOrDelegate(secret: $secret, userId: $userId) === false) {
            return [];
        }

        return $this->mapper->findBySecret($secretId);
    }//end getGroupSharesForSecret()

    /**
     * Return the list of users in a group (lookup helper for the API).
     *
     * @param string $groupId The Nextcloud group ID
     *
     * @return string[] List of user IDs
     */
    public function getGroupMembers(string $groupId): array
    {
        $group = $this->groupManager->get($groupId);
        if ($group === null) {
            return [];
        }

        $ids = [];
        foreach ($group->getUsers() as $user) {
            $ids[] = $user->getUID();
        }

        return $ids;
    }//end getGroupMembers()

    /**
     * Handle a new user being added to a group — notify every secret
     * owner whose secret is shared with that group so they can approve
     * the new member.
     *
     * @param string $userId  The user newly added to the group
     * @param string $groupId The group that gained the member
     *
     * @return int Number of notifications dispatched
     *
     * @spec openspec/changes/implement-user-sharing/tasks.md#4.4
     */
    public function handleNewGroupMember(string $userId, string $groupId): int
    {
        $dispatched = 0;
        foreach ($this->mapper->findByGroup($groupId) as $groupShare) {
            try {
                $secret = $this->secretMapper->findById($groupShare->getSecretId());
            } catch (DoesNotExistException) {
                continue;
            }

            // Skip if the new member IS the owner.
            if ($secret->getOwnerId() === $userId) {
                continue;
            }

            $this->notificationService->notify(
                subject: 'group_member_added',
                recipientId: $secret->getOwnerId(),
                params: [
                    'newMemberId'  => $userId,
                    'groupId'      => $groupId,
                    'secretId'     => $secret->getId(),
                    'secretName'   => $secret->getName(),
                    'groupShareId' => $groupShare->getId(),
                ],
                objectType: 'secret',
                objectId: $secret->getId(),
            );
            ++$dispatched;
        }//end foreach

        return $dispatched;
    }//end handleNewGroupMember()

    /**
     * Approve the new-member notification — creates the ShareTarget that
     * fans the secret out to the new member.
     *
     * @param string $groupShareId      The GroupShare ID
     * @param string $newMemberId       The new member's Nextcloud user ID
     * @param string $recipientSecretId The recipient's encrypted Secret copy ID
     * @param string $userId            The approver (must be owner or delegate)
     *
     * @return void
     *
     * @spec openspec/changes/implement-user-sharing/tasks.md#4.5
     */
    public function approveGroupMemberShare(
        string $groupShareId,
        string $newMemberId,
        string $recipientSecretId,
        string $userId,
    ): void {
        try {
            $entity = $this->mapper->findById($groupShareId);
        } catch (DoesNotExistException) {
            throw new InvalidArgumentException(message: 'Group share not found');
        }

        $secret = $this->loadSecret(secretId: $entity->getSecretId());
        $this->assertOwnerOrDelegate(secret: $secret, userId: $userId);

        // Skip if a ShareTarget already exists for this pair (idempotent).
        try {
            $this->shareTargetMapper->findBySourceSecretAndTargetUser(
                sourceSecretId: $entity->getSecretId(),
                targetUserId: $newMemberId
            );
            return;
        } catch (DoesNotExistException) {
            // Continue.
        }

        $shareTarget = new ShareTarget();
        $shareTarget->setId(Uuid::uuid4()->toString());
        $shareTarget->setSourceSecretId($entity->getSecretId());
        $shareTarget->setTargetUserId($newMemberId);
        $shareTarget->setSecretId($recipientSecretId);
        $shareTarget->setGroupShareId($groupShareId);
        $shareTarget->setCreatedBy($userId);
        $shareTarget->setCreatedAt(new DateTime());
        $this->shareTargetMapper->insert($shareTarget);

        $this->notificationService->notify(
            subject: 'secret_shared',
            recipientId: $newMemberId,
            params: [
                'secretId'   => $entity->getSecretId(),
                'secretName' => $secret->getName(),
                'sharedBy'   => $userId,
            ],
            objectType: 'secret',
            objectId: $entity->getSecretId(),
        );
    }//end approveGroupMemberShare()

    /**
     * Handle a member leaving a group — revoke only the ShareTargets that
     * were fanned out from a GroupShare for that group; direct shares to
     * the same user are NOT touched.
     *
     * @param string $userId  The departing user
     * @param string $groupId The group they left
     *
     * @return int Number of ShareTargets revoked
     *
     * @spec openspec/changes/implement-user-sharing/tasks.md#4.6
     */
    public function handleMemberLeave(string $userId, string $groupId): int
    {
        $revoked     = 0;
        $groupShares = $this->mapper->findByGroup($groupId);
        foreach ($groupShares as $groupShare) {
            foreach ($this->bulkGrantMapper->findByGroupShare($groupShare->getId()) as $row) {
                if ($row->getTargetUserId() !== $userId) {
                    continue;
                }

                // Delete the recipient's encrypted Secret copy too.
                try {
                    $copy = $this->secretMapper->findById($row->getSecretId());
                    $this->secretMapper->delete($copy);
                } catch (DoesNotExistException) {
                    // Already gone.
                }

                $this->shareTargetMapper->delete($row);
                ++$revoked;
            }
        }

        return $revoked;
    }//end handleMemberLeave()

    /**
     * Assert that $userId is the owner of $secret or an active delegate.
     *
     * @param Secret $secret The source secret
     * @param string $userId The candidate user
     *
     * @return void
     *
     * @throws InvalidArgumentException When unauthorized
     */
    private function assertOwnerOrDelegate(Secret $secret, string $userId): void
    {
        if ($this->isOwnerOrDelegate(secret: $secret, userId: $userId) === false) {
            throw new InvalidArgumentException(
                message: 'Not authorized to manage group shares of this secret'
            );
        }
    }//end assertOwnerOrDelegate()

    /**
     * Return true when $userId is the owner of $secret or an active delegate.
     *
     * @param Secret $secret The source secret
     * @param string $userId The candidate user
     *
     * @return bool
     */
    private function isOwnerOrDelegate(Secret $secret, string $userId): bool
    {
        if ($secret->getOwnerType() === 'user' && $secret->getOwnerId() === $userId) {
            return true;
        }

        try {
            $this->delegationMapper->findActiveBySecretAndUser(
                secretId: $secret->getId(),
                userId: $userId
            );
            return true;
        } catch (DoesNotExistException) {
            return false;
        }
    }//end isOwnerOrDelegate()

    /**
     * Look up a Secret by ID, raising InvalidArgumentException on miss.
     *
     * @param string $secretId The Secret ID
     *
     * @return Secret
     *
     * @throws InvalidArgumentException
     */
    private function loadSecret(string $secretId): Secret
    {
        try {
            return $this->secretMapper->findById($secretId);
        } catch (DoesNotExistException) {
            throw new InvalidArgumentException(message: 'Secret not found');
        }
    }//end loadSecret()
}//end class
