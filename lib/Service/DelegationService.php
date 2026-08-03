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
use OCA\Doriath\Db\ShareTargetMapper;
use OCA\Doriath\Event\Audit\AuditEventTypes;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use Ramsey\Uuid\Uuid;

/**
 * Business logic for the SecretDelegation lifecycle (scaffold).
 */
class DelegationService
{
    /**
     * The Nextcloud group whose members can initiate admin-handover
     * delegations (override the owner-consent requirement). Members of
     * this group still MUST already hold a share of the target Secret —
     * the admin path widens *who* can create the delegation, not what
     * can be delegated without a pre-existing share.
     *
     * @var string
     */
    public const VAULT_ADMIN_GROUP = 'vault_admin';

    /**
     * Constructor for DelegationService.
     *
     * @param SecretDelegationMapper $mapper            The delegation mapper
     * @param SecretMapper           $secretMapper      The Secret mapper (owner lookup)
     * @param ShareTargetMapper|null $shareTargetMapper Pre-existing-share lookup (admin path)
     * @param IGroupManager|null     $groupManager      Group membership check (admin path)
     * @param AuditTrail|null        $auditTrail        The audit trail
     *
     * @return void
     */
    public function __construct(
        private SecretDelegationMapper $mapper,
        private SecretMapper $secretMapper,
        private ?ShareTargetMapper $shareTargetMapper=null,
        private ?IGroupManager $groupManager=null,
        private ?AuditTrail $auditTrail=null,
    ) {
    }//end __construct()

    /**
     * Create a temporary delegation.
     *
     * Two paths are supported (FEATURES.md V1 §17.1, ownership-delegation
     * spec.md):
     *
     *  - **Owner self-delegation** — `initiatedBy === secret.owner_id`. The
     *    owner hands share/revoke authority to `delegatedTo`. The delegate
     *    MUST already hold a share of the Secret (when ShareTargetMapper
     *    is wired); otherwise the call is rejected because a delegation
     *    promotes an *existing* recipient copy to co-owner status.
     *  - **Admin power grab** — `initiatedBy ∈ vault_admin` (Nextcloud
     *    group) AND `initiatedBy` already holds a share of the Secret. The
     *    delegation is created with `delegated_to = initiatedBy` so the
     *    admin's own copy is promoted. Owner consent is NOT required, but
     *    the admin must already be a recipient — the path widens *who*
     *    can act, not what can be acted on.
     *
     * @param string $secretId    The Secret ID
     * @param string $delegatedTo The user receiving delegation rights
     * @param string $initiatedBy The user initiating the delegation
     * @param bool   $isAdminPath When true, validate via admin-handover branch
     *                            instead of owner-self-delegation.
     *
     * @return SecretDelegation
     *
     * @throws InvalidArgumentException When the Secret does not exist,
     *                                  the caller is not authorized, the
     *                                  delegate is the original owner, or
     *                                  the delegate holds no pre-existing
     *                                  share of the Secret.
     *
     * @spec openspec/changes/implement-user-sharing/tasks.md#task-6.2
     */
    public function createDelegation(
        string $secretId,
        string $delegatedTo,
        string $initiatedBy,
        bool $isAdminPath=false,
    ): SecretDelegation {
        $secret = $this->loadSecret(secretId: $secretId);

        if ($delegatedTo === '') {
            throw new InvalidArgumentException(message: 'delegated_to is required');
        }

        if ($isAdminPath === true) {
            // Admin-handover branch: the initiator must be a vault admin
            // AND already hold a share of the Secret. The delegation
            // promotes the admin's own existing recipient copy, so the
            // delegate is always the initiator on this path.
            $this->assertVaultAdmin(userId: $initiatedBy);

            if ($delegatedTo !== $initiatedBy) {
                throw new InvalidArgumentException(
                    message: 'Admin handover promotes the initiating admin; delegated_to must match the initiator'
                );
            }

            if ($secret->getOwnerType() !== 'user' || $secret->getOwnerId() === $initiatedBy) {
                throw new InvalidArgumentException(
                    message: 'Admin handover does not apply to the secret owner themselves'
                );
            }

            $this->assertHoldsPreExistingShare(secretId: $secretId, userId: $initiatedBy);
        } else {
            // Owner self-delegation branch: the initiator must BE the
            // current owner of the Secret.
            if ($secret->getOwnerType() !== 'user' || $secret->getOwnerId() !== $initiatedBy) {
                throw new InvalidArgumentException(message: 'Not authorized to delegate this secret');
            }

            if ($delegatedTo === $initiatedBy) {
                throw new InvalidArgumentException(message: 'Cannot delegate to self');
            }

            // Delegate MUST already hold a share when the share-target
            // mapper is wired; this preserves the "delegations promote
            // existing recipients" invariant from spec.md.
            $this->assertHoldsPreExistingShare(secretId: $secretId, userId: $delegatedTo);
        }//end if

        $entity = new SecretDelegation();
        $entity->setId(Uuid::uuid4()->toString());
        $entity->setSecretId($secretId);
        $entity->setOriginalOwnerId($secret->getOwnerId());
        $entity->setDelegatedTo($delegatedTo);
        $entity->setDelegatedAt(new DateTime());
        $entity->setInitiatedBy($initiatedBy);
        $entity->setIsPermanent(false);

        $persisted = $this->mapper->insert($entity);

        $this->auditTrail?->forUser(
            actorId: $initiatedBy,
            eventType: AuditEventTypes::SHARE_DELEGATED,
            objectType: 'share',
            objectId: $secretId,
            objectName: $secret->getName(),
            metadata: [
                'delegatedTo' => $delegatedTo,
                'isPermanent' => false,
            ],
        );

        return $persisted;
    }//end createDelegation()

    /**
     * Assert that $userId is a member of the vault_admin group.
     *
     * @param string $userId The candidate admin user ID
     *
     * @return void
     *
     * @throws InvalidArgumentException When the group manager is wired
     *                                  but the user is not a vault admin.
     */
    private function assertVaultAdmin(string $userId): void
    {
        if ($this->groupManager === null) {
            // No group manager wired — admin path cannot be authorized.
            throw new InvalidArgumentException(
                message: 'Admin handover is not available in this context'
            );
        }

        if ($this->groupManager->isInGroup($userId, self::VAULT_ADMIN_GROUP) === false) {
            throw new InvalidArgumentException(
                message: 'Admin handover requires membership in the vault_admin group'
            );
        }
    }//end assertVaultAdmin()

    /**
     * Assert that $userId already holds a share of $secretId. No-op when
     * the share-target mapper is not wired (preserves backward compat with
     * existing constructors that pre-date the §17.1 hardening).
     *
     * @param string $secretId The source Secret ID
     * @param string $userId   The candidate recipient
     *
     * @return void
     *
     * @throws InvalidArgumentException When the share-target mapper is
     *                                  wired but no share row exists for
     *                                  (sourceSecret, user).
     */
    private function assertHoldsPreExistingShare(string $secretId, string $userId): void
    {
        if ($this->shareTargetMapper === null) {
            return;
        }

        try {
            $this->shareTargetMapper->findBySourceSecretAndTargetUser(
                sourceSecretId: $secretId,
                targetUserId: $userId,
            );
        } catch (DoesNotExistException) {
            throw new InvalidArgumentException(
                message: 'Delegation requires the recipient to already hold a share of the secret'
            );
        }
    }//end assertHoldsPreExistingShare()

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
        $secret = $this->loadSecret(secretId: $secretId);

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

        if ($removed > 0) {
            $this->auditTrail?->forUser(
                actorId: $ownerId,
                eventType: AuditEventTypes::SHARE_DELEGATION_RECLAIMED,
                objectType: 'share',
                objectId: $secretId,
                objectName: $secret->getName(),
            );
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
        $secret = $this->loadSecret(secretId: $secretId);

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
