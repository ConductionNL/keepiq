<?php

/**
 * Doriath Delegation Service
 *
 * Server-side orchestration of secret ownership delegation: co-owner grants
 * (admin power grab + user self-delegation), reclaim of temporary delegations,
 * and permanent transfer on EncryptionSuite revocation.
 *
 * A delegation requires the delegate to already hold a shared copy of the
 * secret — without a copy encrypted with their key they cannot decrypt it
 * (cryptographic constraint).
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
use OCA\Doriath\Db\SecretDelegation;
use OCA\Doriath\Db\SecretDelegationMapper;
use OCA\Doriath\Db\SecretShareMapper;
use OCP\IGroupManager;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Business logic for secret ownership delegation.
 */
class DelegationService
{
    /**
     * Nextcloud group whose members may perform an admin power grab.
     *
     * @var string
     */
    private const VAULT_ADMIN_GROUP = 'vault_admin';

    /**
     * Constructor for DelegationService.
     *
     * @param SecretDelegationMapper  $delegationMapper The delegation mapper
     * @param SecretShareMapper       $shareMapper      The secret share mapper
     * @param SecretOwnershipResolver $ownership        The ownership resolver
     * @param SecretCopyGateway       $copyGateway      The secret copy gateway
     * @param IGroupManager           $groupManager     The Nextcloud group manager
     *
     * @return void
     */
    public function __construct(
        private SecretDelegationMapper $delegationMapper,
        private SecretShareMapper $shareMapper,
        private SecretOwnershipResolver $ownership,
        private SecretCopyGateway $copyGateway,
        private IGroupManager $groupManager,
    ) {
    }//end __construct()

    /**
     * Create a delegation granting co-owner rights over a secret.
     *
     * The initiator must be the original owner (self-delegation) or a member of
     * the vault_admin group (admin power grab). The delegate must already hold a
     * shared copy of the secret.
     *
     * @param string $secretId    The secret ID
     * @param string $delegateTo  The delegate user ID
     * @param string $initiatedBy The initiating user ID
     *
     * @return SecretDelegation
     *
     * @throws RuntimeException When unauthorized or the delegate holds no share
     */
    public function createDelegation(string $secretId, string $delegateTo, string $initiatedBy): SecretDelegation
    {
        $ownerId = $this->ownership->getOwnerId($secretId);
        if ($ownerId === null) {
            throw new RuntimeException('Secret owner could not be resolved');
        }

        $isOwner = ($ownerId === $initiatedBy);
        $isAdmin = $this->groupManager->isInGroup($initiatedBy, self::VAULT_ADMIN_GROUP);
        if ($isOwner === false && $isAdmin === false) {
            throw new RuntimeException('Only the owner or a vault admin may delegate this secret');
        }

        if ($this->shareMapper->findBySourceSecretAndTargetUser($secretId, $delegateTo) === null) {
            throw new RuntimeException('Delegate must already hold a shared copy of the secret');
        }

        if ($this->delegationMapper->findActiveBySecretAndUser($secretId, $delegateTo) !== null) {
            throw new RuntimeException('Secret is already delegated to this user');
        }

        $delegation = new SecretDelegation();
        $delegation->setId(Uuid::uuid4()->toString());
        $delegation->setSecretId($secretId);
        $delegation->setOriginalOwnerId($ownerId);
        $delegation->setDelegatedTo($delegateTo);
        $delegation->setDelegatedAt(new DateTime());
        $delegation->setInitiatedBy($initiatedBy);
        $delegation->setIsPermanent(false);

        return $this->delegationMapper->insert($delegation);
    }//end createDelegation()

    /**
     * Reclaim (delete) all temporary delegations for a secret.
     *
     * Only the original owner may reclaim. Permanent delegations are not
     * affected — ownership has already transferred.
     *
     * @param string $secretId The secret ID
     * @param string $ownerId  The acting user (must be original owner)
     *
     * @return int The number of delegations reclaimed
     *
     * @throws RuntimeException When the caller is not the original owner
     */
    public function reclaimDelegation(string $secretId, string $ownerId): int
    {
        if ($this->ownership->isOwner($secretId, $ownerId) === false) {
            throw new RuntimeException('Only the original owner may reclaim delegations');
        }

        $reclaimed = 0;
        foreach ($this->delegationMapper->findBySecret($secretId) as $delegation) {
            if ($delegation->getIsPermanent() === true) {
                continue;
            }

            $this->delegationMapper->delete($delegation);
            $reclaimed++;
        }

        return $reclaimed;
    }//end reclaimDelegation()

    /**
     * Return the delegations for a secret — owner/delegate only.
     *
     * @param string $secretId The secret ID
     * @param string $userId   The acting user
     *
     * @return SecretDelegation[]
     */
    public function getDelegationsForSecret(string $secretId, string $userId): array
    {
        if ($this->ownership->canManageShares($secretId, $userId) === false) {
            return [];
        }

        return $this->delegationMapper->findBySecret($secretId);
    }//end getDelegationsForSecret()

    /**
     * Make all temporary delegations of an owner permanent and remove the
     * owner's now-inaccessible copies (EncryptionSuite revocation transfer).
     *
     * @param string $originalOwnerId The original owner whose suite was revoked
     *
     * @return SecretDelegation[] The delegations made permanent
     */
    public function makePermanent(string $originalOwnerId): array
    {
        $delegations = $this->delegationMapper->makePermanentByOriginalOwner($originalOwnerId);

        foreach ($delegations as $delegation) {
            $ownerShare = $this->shareMapper->findBySourceSecretAndTargetUser(
                $delegation->getSecretId(),
                $originalOwnerId
            );
            if ($ownerShare !== null) {
                $this->copyGateway->deleteCopy((string) $ownerShare->getSecretId());
                $this->shareMapper->delete($ownerShare);
            }
        }

        return $delegations;
    }//end makePermanent()

    /**
     * Cascade-delete all delegations for a secret (used on secret delete).
     *
     * @param string $secretId The secret ID
     *
     * @return void
     */
    public function deleteAllDelegationsForSecret(string $secretId): void
    {
        $this->delegationMapper->deleteBySecret($secretId);
    }//end deleteAllDelegationsForSecret()
}//end class
