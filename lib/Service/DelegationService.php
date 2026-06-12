<?php

/**
 * Doriath Delegation Service
 *
 * Smallest scaffold for the SecretDelegation lifecycle — owner-initiated
 * temporary handover of share/revoke authority to another user, and the
 * EncryptionSuiteRevoked promotion path that cements those temporary
 * delegations into permanent owner changes.
 *
 * The full integration with the share-creation authorization path lands
 * with the §3.2/§3.3 ShareService hardening in the coordinated
 * implement-user-sharing build cycle.
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
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretDelegation;
use OCA\Doriath\Db\SecretDelegationMapper;
use OCA\Doriath\Db\SecretMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Business logic for the SecretDelegation lifecycle (scaffold).
 */
class DelegationService
{
    /**
     * Constructor for DelegationService.
     *
     * @param SecretDelegationMapper $mapper       The delegation mapper
     * @param SecretMapper           $secretMapper The Secret mapper (owner lookup)
     * @param LoggerInterface        $logger       The logger
     *
     * @return void
     */
    public function __construct(
        private SecretDelegationMapper $mapper,
        private SecretMapper $secretMapper,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Create a temporary delegation — the original owner hands share/revoke
     * authority over the Secret to `delegatedTo`. The initiator must be the
     * current owner of the Secret (admin-handover lands with §6.2 admin path).
     *
     * @param string $secretId    The Secret ID
     * @param string $delegatedTo The user receiving delegation rights
     * @param string $initiatedBy The user initiating the delegation
     *
     * @return SecretDelegation
     *
     * @throws InvalidArgumentException When the Secret does not exist,
     *                                  the initiator is not the owner, or
     *                                  the delegate is the owner themselves.
     *
     * @spec openspec/changes/implement-user-sharing/tasks.md#task-6.2
     */
    public function createDelegation(string $secretId, string $delegatedTo, string $initiatedBy): SecretDelegation
    {
        $secret = $this->loadSecret($secretId);

        if ($secret->getOwnerType() !== 'user' || $secret->getOwnerId() !== $initiatedBy) {
            throw new InvalidArgumentException(message: 'Not authorized to delegate this secret');
        }

        if ($delegatedTo === $initiatedBy) {
            throw new InvalidArgumentException(message: 'Cannot delegate to self');
        }

        if ($delegatedTo === '') {
            throw new InvalidArgumentException(message: 'delegated_to is required');
        }

        $entity = new SecretDelegation();
        $entity->setId(Uuid::uuid4()->toString());
        $entity->setSecretId($secretId);
        $entity->setOriginalOwnerId($secret->getOwnerId());
        $entity->setDelegatedTo($delegatedTo);
        $entity->setDelegatedAt(new DateTime());
        $entity->setInitiatedBy($initiatedBy);
        $entity->setIsPermanent(false);

        return $this->mapper->insert($entity);
    }//end createDelegation()

    /**
     * Reclaim — the original owner revokes all TEMPORARY delegations they
     * created for the given Secret. Permanent delegations are immutable
     * and are NOT touched.
     *
     * @param string $secretId The Secret ID
     * @param string $ownerId  The original owner reclaiming
     *
     * @return int The number of delegations removed.
     *
     * @throws InvalidArgumentException When the Secret does not exist or
     *                                  the caller is not the original owner.
     *
     * @spec openspec/changes/implement-user-sharing/tasks.md#task-6.3
     */
    public function reclaimDelegation(string $secretId, string $ownerId): int
    {
        $secret = $this->loadSecret($secretId);

        if ($secret->getOwnerType() !== 'user' || $secret->getOwnerId() !== $ownerId) {
            throw new InvalidArgumentException(message: 'Not authorized to reclaim this delegation');
        }

        $removed = 0;
        foreach ($this->mapper->findBySecret($secretId) as $entity) {
            if ($entity->getIsPermanent() === true) {
                continue;
            }

            if ($entity->getOriginalOwnerId() !== $ownerId) {
                continue;
            }

            $this->mapper->delete($entity);
            ++$removed;
        }

        return $removed;
    }//end reclaimDelegation()

    /**
     * Return all delegations for the given Secret — visible only to the
     * Secret owner.
     *
     * @param string $secretId The Secret ID
     * @param string $ownerId  The requesting owner
     *
     * @return SecretDelegation[]
     *
     * @throws InvalidArgumentException When the Secret does not exist or
     *                                  the caller is not the owner.
     *
     * @spec openspec/changes/implement-user-sharing/tasks.md#task-6.1
     */
    public function getDelegationsForSecret(string $secretId, string $ownerId): array
    {
        $secret = $this->loadSecret($secretId);

        if ($secret->getOwnerType() !== 'user' || $secret->getOwnerId() !== $ownerId) {
            throw new InvalidArgumentException(message: 'Not authorized for this secret');
        }

        return $this->mapper->findBySecret($secretId);
    }//end getDelegationsForSecret()

    /**
     * Promote all TEMPORARY delegations for the given original owner to
     * permanent. Called by the EncryptionSuiteRevoked listener when the
     * original owner loses access for good and any delegate currently
     * holding the share needs to become the de-facto owner.
     *
     * @param string $originalOwnerId The original owner user ID
     *
     * @return int Rows affected.
     *
     * @spec openspec/changes/implement-user-sharing/tasks.md#task-6.4
     */
    public function makePermanent(string $originalOwnerId): int
    {
        return $this->mapper->makePermanentByOriginalOwner($originalOwnerId);
    }//end makePermanent()

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
