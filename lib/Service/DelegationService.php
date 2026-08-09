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
use OCA\Doriath\Event\Audit\AuditEvent;
use OCA\Doriath\Event\Audit\AuditEventFactory;
use OCA\Doriath\Event\Audit\AuditEventTypes;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
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
     * @param IEventDispatcher|null  $eventDispatcher   The event dispatcher
     * @param AuditEventFactory      $auditEvents       The audit-event factory
     *
     * @return void
     */
    public function __construct(
        private SecretDelegationMapper $mapper,
        private SecretMapper $secretMapper,
        private ?ShareTargetMapper $shareTargetMapper=null,
        private ?IGroupManager $groupManager=null,
        private ?IEventDispatcher $eventDispatcher=null,
        private AuditEventFactory $auditEvents=new AuditEventFactory(),
    ) {
    }//end __construct()

    /**
     * Dispatch a typed audit event, fail-soft.
     *
     * @param AuditEvent $event The audit event
     *
     * @return void
     *
     * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3
     */
    private function dispatchAudit(AuditEvent $event): void
    {
        $this->eventDispatcher?->dispatchTyped($event);
    }//end dispatchAudit()

    /**
     * Create a temporary delegation by OWNER SELF-DELEGATION
     * (FEATURES.md V1 §17.1, ownership-delegation spec.md).
     *
     * `initiatedBy` MUST be `secret.owner_id`. The owner hands share/revoke
     * authority to `delegatedTo`, who MUST already hold a share of the
     * Secret (when ShareTargetMapper is wired); otherwise the call is
     * rejected, because a delegation promotes an *existing* recipient copy
     * to co-owner status.
     *
     * The admin power-grab path lives in createAdminHandover() — the two
     * are separate entry points rather than one flag-selected method,
     * because they are different authorization decisions.
     *
     * @param string $secretId    The Secret ID
     * @param string $delegatedTo The user receiving delegation rights
     * @param string $initiatedBy The user initiating the delegation
     *
     * @return SecretDelegation
     *
     * @throws InvalidArgumentException When the Secret does not exist,
     *                                  the caller is not the owner, the
     *                                  delegate is the owner themselves,
     *                                  or the delegate holds no
     *                                  pre-existing share of the Secret.
     *
     * @spec openspec/changes/implement-user-sharing/tasks.md#task-6.2
     */
    public function createDelegation(
        string $secretId,
        string $delegatedTo,
        string $initiatedBy,
    ): SecretDelegation {
        $secret = $this->loadDelegableSecret(secretId: $secretId, delegatedTo: $delegatedTo);

        // The initiator must BE the current owner of the Secret.
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

        return $this->persistDelegation(
            secret: $secret,
            secretId: $secretId,
            delegatedTo: $delegatedTo,
            initiatedBy: $initiatedBy
        );
    }//end createDelegation()

    /**
     * Create a temporary delegation by ADMIN POWER GRAB
     * (FEATURES.md V1 §17.1, ownership-delegation spec.md).
     *
     * `initiatedBy` MUST be in the vault_admin Nextcloud group AND already
     * hold a share of the Secret. The delegation is created with
     * `delegated_to = initiatedBy` so the admin's own copy is promoted.
     * Owner consent is NOT required, but the admin must already be a
     * recipient — the path widens *who* can act, not what can be acted on.
     *
     * @param string $secretId    The Secret ID
     * @param string $delegatedTo The user receiving delegation rights; MUST
     *                            equal $initiatedBy on this path
     * @param string $initiatedBy The vault admin initiating the handover
     *
     * @return SecretDelegation
     *
     * @throws InvalidArgumentException When the Secret does not exist, the
     *                                  caller is not a vault admin,
     *                                  $delegatedTo is not the initiator,
     *                                  the initiator is already the owner,
     *                                  or the initiator holds no
     *                                  pre-existing share of the Secret.
     *
     * @spec openspec/changes/implement-user-sharing/tasks.md#task-17.1
     */
    public function createAdminHandover(
        string $secretId,
        string $delegatedTo,
        string $initiatedBy,
    ): SecretDelegation {
        $secret = $this->loadDelegableSecret(secretId: $secretId, delegatedTo: $delegatedTo);

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

        return $this->persistDelegation(
            secret: $secret,
            secretId: $secretId,
            delegatedTo: $delegatedTo,
            initiatedBy: $initiatedBy
        );
    }//end createAdminHandover()

    /**
     * Load the Secret both delegation paths act on, rejecting a blank
     * delegate up front so the two entry points share one precondition.
     *
     * @param string $secretId    The Secret ID
     * @param string $delegatedTo The candidate delegate
     *
     * @return Secret
     *
     * @throws InvalidArgumentException When the Secret does not exist or
     *                                  $delegatedTo is blank.
     */
    private function loadDelegableSecret(string $secretId, string $delegatedTo): Secret
    {
        $secret = $this->loadSecret(secretId: $secretId);

        if ($delegatedTo === '') {
            throw new InvalidArgumentException(message: 'delegated_to is required');
        }

        return $secret;
    }//end loadDelegableSecret()

    /**
     * Persist an authorized temporary delegation and audit it. Callers
     * have already made the authorization decision.
     *
     * @param Secret $secret      The Secret being delegated
     * @param string $secretId    The Secret ID as supplied by the caller
     * @param string $delegatedTo The user receiving delegation rights
     * @param string $initiatedBy The user initiating the delegation
     *
     * @return SecretDelegation
     */
    private function persistDelegation(
        Secret $secret,
        string $secretId,
        string $delegatedTo,
        string $initiatedBy,
    ): SecretDelegation {
        $entity = new SecretDelegation();
        $entity->setId(Uuid::uuid4()->toString());
        $entity->setSecretId($secretId);
        $entity->setOriginalOwnerId($secret->getOwnerId());
        $entity->setDelegatedTo($delegatedTo);
        $entity->setDelegatedAt(new DateTime());
        $entity->setInitiatedBy($initiatedBy);
        $entity->setIsPermanent(false);

        $persisted = $this->mapper->insert($entity);

        $this->dispatchAudit(
            event: $this->auditEvents->forUser(
                actorId: $initiatedBy,
                eventType: AuditEventTypes::SHARE_DELEGATED,
                objectType: 'share',
                objectId: $secretId,
                objectName: $secret->getName(),
                metadata: [
                    'delegatedTo' => $delegatedTo,
                    'isPermanent' => false,
                ],
            )
        );

        return $persisted;
    }//end persistDelegation()

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

        if ($this->isVaultAdmin(userId: $userId) === false) {
            throw new InvalidArgumentException(
                message: 'Admin handover requires membership in the vault_admin group'
            );
        }
    }//end assertVaultAdmin()

    /**
     * Whether $userId may use the admin handover path at all.
     *
     * Exists so the UI can decide whether to OFFER the takeover without
     * duplicating the membership rule: `assertVaultAdmin()` above is written
     * in terms of this predicate, so the button and the enforcement can never
     * drift apart. It answers only the group question — the per-secret
     * preconditions (not already the owner, already holds a share) stay in
     * `createAdminHandover()`, because they need the Secret.
     *
     * @param string $userId The candidate admin user ID
     *
     * @return bool
     *
     * @spec openspec/specs/user-sharing/spec.md#requirement-ownership-delegation
     */
    public function isVaultAdmin(string $userId): bool
    {
        if ($this->groupManager === null) {
            return false;
        }

        return $this->groupManager->isInGroup($userId, self::VAULT_ADMIN_GROUP);
    }//end isVaultAdmin()

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
            $this->dispatchAudit(
                event: $this->auditEvents->forUser(
                    actorId: $ownerId,
                    eventType: AuditEventTypes::SHARE_DELEGATION_RECLAIMED,
                    objectType: 'share',
                    objectId: $secretId,
                    objectName: $secret->getName(),
                )
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
