<?php

/**
 * Doriath Group Share Service
 *
 * Business logic for group-level secret sharing: create, revoke, and member lifecycle.
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
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\SecretShareMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Business logic for group-level secret sharing.
 */
class GroupShareService
{
    /**
     * Constructor for GroupShareService.
     *
     * @param GroupShareMapper      $groupShareMapper      The group share mapper
     * @param SecretShareMapper     $shareMapper           The secret share mapper
     * @param SecretMapper          $secretMapper          The secret mapper
     * @param EncryptionSuiteMapper $encryptionSuiteMapper The encryption suite mapper
     * @param IGroupManager         $groupManager          The Nextcloud group manager
     * @param LoggerInterface       $logger                The logger interface
     *
     * @return void
     */
    public function __construct(
        private GroupShareMapper $groupShareMapper,
        private SecretShareMapper $shareMapper,
        private SecretMapper $secretMapper,
        private EncryptionSuiteMapper $encryptionSuiteMapper,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Create a group share for a secret.
     *
     * Validates ownership, queries all current group members, identifies which
     * members have an active encryption suite and extracts their public keys from
     * the suite certificate, creates the GroupShare record, and returns the share
     * along with the list of eligible members and their public key PEMs.
     *
     * @param string $secretId The secret ID to share
     * @param string $groupId  The Nextcloud group ID to share with
     * @param string $userId   The caller's Nextcloud user ID (must own the secret)
     *
     * @return array{groupShare: GroupShare, members: array<int,array{userId: string, publicKeyPem: string}>}
     *
     * @throws InvalidArgumentException When the caller does not own the secret or the group does not exist
     * @throws DoesNotExistException    When the secret does not exist
     */
    public function createGroupShare(string $secretId, string $groupId, string $userId): array
    {
        $secret = $this->secretMapper->findById(id: $secretId);

        if ($secret->getOwnerId() !== $userId) {
            throw new InvalidArgumentException('You do not own this secret');
        }

        $group = $this->groupManager->get($groupId);

        if ($group === null) {
            throw new InvalidArgumentException("Group '{$groupId}' does not exist");
        }

        $members = [];

        foreach ($group->getUsers() as $user) {
            $memberId = $user->getUID();

            try {
                $suite = $this->encryptionSuiteMapper->findActiveByOwner(
                    ownerType: 'user',
                    ownerId: $memberId
                );

                // Extract public key PEM from the suite certificate.
                $cert         = $suite->getCertificate() ?? '';
                $publicKeyPem = '';
                if ($cert !== '') {
                    $pubKey = openssl_pkey_get_public($cert);
                    if ($pubKey !== false) {
                        $details      = openssl_pkey_get_details($pubKey);
                        $publicKeyPem = $details['key'] ?? '';
                    }
                }

                $members[] = [
                    'userId'       => $memberId,
                    'publicKeyPem' => $publicKeyPem,
                ];
            } catch (DoesNotExistException) {
                // Member has no active suite — not eligible for sharing.
                $this->logger->debug(
                    "Doriath: createGroupShare — user {$memberId} has no active suite, skipping"
                );
            }//end try
        }//end foreach

        $groupShare = new GroupShare();
        $groupShare->setId(Uuid::uuid4()->toString());
        $groupShare->setSecretId($secretId);
        $groupShare->setGroupId($groupId);
        $groupShare->setCreatedBy($userId);
        $groupShare->setCreatedAt(new DateTime());

        $this->groupShareMapper->insert($groupShare);

        $this->logger->info(
            "Doriath: Group share created for secret {$secretId} to group {$groupId} by {$userId}"
        );

        return [
            'groupShare' => $groupShare,
            'members'    => $members,
        ];
    }//end createGroupShare()

    /**
     * Revoke a group share.
     *
     * Validates ownership, finds all user-level SecretShares originating from this
     * group share, deletes the Secret copy for each, then deletes all SecretShares
     * and the GroupShare record.
     *
     * @param string $groupShareId The group share ID to revoke
     * @param string $userId       The caller's Nextcloud user ID (must own the source secret)
     *
     * @return void
     *
     * @throws InvalidArgumentException When the caller does not own the source secret
     * @throws DoesNotExistException    When the group share does not exist
     */
    public function revokeGroupShare(string $groupShareId, string $userId): void
    {
        $groupShare = $this->groupShareMapper->findById(id: $groupShareId);
        $secret     = $this->secretMapper->findById(id: $groupShare->getSecretId());

        if ($secret->getOwnerId() !== $userId) {
            throw new InvalidArgumentException('You do not own this secret');
        }

        // Delete each recipient's Secret copy.
        $userShares = $this->shareMapper->findByGroupShare(groupShareId: $groupShareId);

        foreach ($userShares as $userShare) {
            try {
                $copy = $this->secretMapper->findById(id: $userShare->getSecretId());
                $this->secretMapper->delete($copy);
            } catch (DoesNotExistException) {
                $this->logger->warning(
                    "Doriath: revokeGroupShare — Secret copy {$userShare->getSecretId()} not found, continuing"
                );
            }

            $this->shareMapper->delete($userShare);
        }//end foreach

        $this->groupShareMapper->delete($groupShare);

        $this->logger->info("Doriath: Group share {$groupShareId} revoked by {$userId}");
    }//end revokeGroupShare()

    /**
     * Handle a new member being added to a group.
     *
     * Finds all GroupShares for this group and logs the event. The actual secret
     * sharing for the new member will be triggered by the caller once notifications
     * are wired.
     *
     * @param string $userId  The Nextcloud user ID of the new member
     * @param string $groupId The Nextcloud group ID
     *
     * @return void
     */
    public function handleNewGroupMember(string $userId, string $groupId): void
    {
        $groupShares = $this->groupShareMapper->findByGroup(groupId: $groupId);

        if (count($groupShares) === 0) {
            return;
        }

        $this->logger->info(
            "Doriath: handleNewGroupMember — user {$userId} added to group {$groupId}; "
            .count($groupShares)." group share(s) pending notification"
        );
    }//end handleNewGroupMember()

    /**
     * Handle a member leaving a group.
     *
     * Finds all SecretShares targeting this user that originated from a GroupShare
     * for the given group, then deletes their Secret copies and the SecretShare records.
     *
     * @param string $userId  The Nextcloud user ID of the departing member
     * @param string $groupId The Nextcloud group ID
     *
     * @return void
     */
    public function handleMemberLeave(string $userId, string $groupId): void
    {
        $userShares  = $this->shareMapper->findByTargetUser(targetUserId: $userId);
        $groupShares = $this->groupShareMapper->findByGroup(groupId: $groupId);

        $groupShareIds = array_map(
            callback: static fn(GroupShare $gs) => $gs->getId(),
            array: $groupShares
        );

        foreach ($userShares as $share) {
            $gsId = $share->getGroupShareId();
            if ($gsId === null) {
                continue;
            }

            if (in_array(needle: $gsId, haystack: $groupShareIds, strict: true) === false) {
                continue;
            }

            try {
                $copy = $this->secretMapper->findById(id: $share->getSecretId());
                $this->secretMapper->delete($copy);
            } catch (DoesNotExistException) {
                $this->logger->warning(
                    "Doriath: handleMemberLeave — Secret copy {$share->getSecretId()} not found, continuing"
                );
            }

            $this->shareMapper->delete($share);

            $this->logger->info(
                "Doriath: handleMemberLeave — share {$share->getId()} removed for user {$userId} leaving group {$groupId}"
            );
        }//end foreach
    }//end handleMemberLeave()

    /**
     * Return all group shares for a given secret.
     *
     * @param string $secretId The secret ID
     * @param string $userId   The caller's Nextcloud user ID (must own the secret)
     *
     * @return GroupShare[]
     *
     * @throws InvalidArgumentException When the caller does not own the secret
     * @throws DoesNotExistException    When the secret does not exist
     */
    public function getGroupSharesForSecret(string $secretId, string $userId): array
    {
        $secret = $this->secretMapper->findById(id: $secretId);

        if ($secret->getOwnerId() !== $userId) {
            throw new InvalidArgumentException('You do not own this secret');
        }

        return $this->groupShareMapper->findBySecret(secretId: $secretId);
    }//end getGroupSharesForSecret()
}//end class
