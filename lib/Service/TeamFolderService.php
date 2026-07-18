<?php

/**
 * Doriath Team Folder Service
 *
 * Business logic for team folder sharing (team-folder-sharing §2):
 * a TeamFolder attaches shared membership (users + groups) to an existing
 * owner Folder; every secret in the folder subtree is fanned out to the
 * effective member set as ordinary per-recipient RSA copies. There is no
 * shared symmetric key and the server never sees plaintext (ADR-003) —
 * the browser encrypts every (secret × recipient) pair and this service
 * only records provenance-linked ShareTarget rows idempotently.
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
use OCA\Doriath\Event\Audit\AuditEvent;
use OCA\Doriath\Event\Audit\AuditEventTypes;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Business logic for the TeamFolder lifecycle.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   The service threads
 *   through the folder/secret/share/suite/delegation mappers plus the
 *   user/group managers so the folder fan-out invariants live in one
 *   place; splitting it would scatter them.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     One public method per
 *   API operation of the team-folder lifecycle.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The membership
 *   expansion + fan-out reconciliation logic is inherently branchy; the
 *   individual methods stay small.
 */
class TeamFolderService
{
    /**
     * The Nextcloud group whose members may run the offboarding action
     * (in addition to instance admins). Mirrors DelegationService.
     *
     * @var string
     */
    private const VAULT_ADMIN_GROUP = 'vault_admin';

    /**
     * Constructor for TeamFolderService.
     *
     * @param TeamFolderMapper       $mapper              The team-folder mapper
     * @param TeamFolderMemberMapper $memberMapper        The membership mapper
     * @param FolderMapper           $folderMapper        The folder mapper (subtree walk)
     * @param SecretMapper           $secretMapper        The secret mapper
     * @param ShareTargetMapper      $shareTargetMapper   The share-target mapper (fan-out rows)
     * @param EncryptionSuiteMapper  $suiteMapper         The suite mapper (eligibility filter)
     * @param SecretDelegationMapper $delegationMapper    The delegation mapper (offboarding)
     * @param SecretTypeService      $typeService         The secret-type resolver (copy typing)
     * @param IGroupManager          $groupManager        The Nextcloud group manager
     * @param IUserManager           $userManager         The Nextcloud user manager
     * @param NotificationService    $notificationService The notification dispatcher
     * @param IDBConnection          $db                  The database connection
     * @param LoggerInterface        $logger              The logger
     * @param IEventDispatcher|null  $eventDispatcher     The audit event dispatcher
     *
     * @return void
     */
    public function __construct(
        private TeamFolderMapper $mapper,
        private TeamFolderMemberMapper $memberMapper,
        private FolderMapper $folderMapper,
        private SecretMapper $secretMapper,
        private ShareTargetMapper $shareTargetMapper,
        private EncryptionSuiteMapper $suiteMapper,
        private SecretDelegationMapper $delegationMapper,
        private SecretTypeService $typeService,
        private IGroupManager $groupManager,
        private IUserManager $userManager,
        private NotificationService $notificationService,
        private IDBConnection $db,
        private LoggerInterface $logger,
        private ?IEventDispatcher $eventDispatcher=null,
    ) {
    }//end __construct()

    /**
     * Dispatch an audit event when a dispatcher is wired.
     *
     * @param AuditEvent $event The audit event
     *
     * @return void
     */
    private function dispatchAudit(AuditEvent $event): void
    {
        $this->eventDispatcher?->dispatchTyped($event);
    }//end dispatchAudit()

    /**
     * Share an owned folder — creates the TeamFolder attachment.
     *
     * Idempotent: sharing an already-shared folder returns the existing
     * TeamFolder unchanged.
     *
     * @param string $folderId The Folder UUID to share
     * @param string $userId   The caller (must own the folder)
     *
     * @return TeamFolder
     *
     * @throws InvalidArgumentException When the folder is missing or not owned
     *
     * @spec openspec/changes/team-folder-sharing/tasks.md#2.1
     */
    public function shareFolder(string $folderId, string $userId): TeamFolder
    {
        $folder = $this->loadOwnedFolder(folderId: $folderId, userId: $userId);

        try {
            return $this->mapper->findByFolder(folderId: $folder->getId());
        } catch (DoesNotExistException) {
            // Not shared yet — create below.
        }

        $entity = new TeamFolder();
        $entity->setId(Uuid::uuid4()->toString());
        $entity->setFolderId($folder->getId());
        $entity->setOwnerId($userId);
        $entity->setCreatedAt(new DateTime());
        $entity->setUpdatedAt(new DateTime());
        $persisted = $this->mapper->insert($entity);

        $this->dispatchAudit(
            event: AuditEvent::forUser(
                actorId: $userId,
                eventType: AuditEventTypes::TEAM_FOLDER_SHARED,
                objectType: 'team_folder',
                objectId: $persisted->getId(),
                objectName: $folder->getName(),
                metadata: ['folderId' => $folder->getId()],
            )
        );

        return $persisted;
    }//end shareFolder()

    /**
     * Unshare a folder — cascade-revokes every derived ShareTarget (and
     * the recipient Secret copies), removes all memberships and the
     * TeamFolder row. The folder itself remains as a private folder.
     *
     * @param string $teamFolderId The TeamFolder UUID
     * @param string $userId       The caller (must be the owner)
     *
     * @return int Number of derived shares revoked
     *
     * @throws InvalidArgumentException When not found / not authorized
     *
     * @spec openspec/changes/team-folder-sharing/tasks.md#2.4
     */
    public function unshareFolder(string $teamFolderId, string $userId): int
    {
        $teamFolder = $this->loadOwnedTeamFolder(teamFolderId: $teamFolderId, userId: $userId);

        $revoked = 0;
        $this->db->beginTransaction();
        try {
            foreach ($this->shareTargetMapper->findByTeamFolder(teamFolderId: $teamFolderId) as $row) {
                $this->deleteRecipientCopy(row: $row);
                $this->shareTargetMapper->delete($row);
                ++$revoked;
            }

            $this->memberMapper->deleteByTeamFolder(teamFolderId: $teamFolderId);
            $this->mapper->delete($teamFolder);
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        $this->dispatchAudit(
            event: AuditEvent::forUser(
                actorId: $userId,
                eventType: AuditEventTypes::TEAM_FOLDER_UNSHARED,
                objectType: 'team_folder',
                objectId: $teamFolderId,
                objectName: '',
                metadata: [
                    'folderId'     => $teamFolder->getFolderId(),
                    'revokedCount' => $revoked,
                ],
            )
        );

        return $revoked;
    }//end unshareFolder()

    /**
     * List team folders for a user: folders they own (with membership)
     * and folders shared to them (as direct user member or via a group).
     *
     * @param string $userId The requesting user
     *
     * @return array{owned:array<int,array<string,mixed>>,memberOf:array<int,array<string,mixed>>}
     *
     * @spec openspec/changes/team-folder-sharing/tasks.md#4.1
     */
    public function listForUser(string $userId): array
    {
        $owned = [];
        foreach ($this->mapper->findByOwner(ownerId: $userId) as $teamFolder) {
            $owned[] = $this->describe(teamFolder: $teamFolder, includeMembers: true);
        }

        $memberOf = [];
        $seen     = [];
        foreach ($this->membershipRowsForUser(userId: $userId) as $membership) {
            $teamFolderId = $membership->getTeamFolderId();
            if (isset($seen[$teamFolderId]) === true) {
                continue;
            }

            $seen[$teamFolderId] = true;
            try {
                $teamFolder = $this->mapper->findById(id: $teamFolderId);
            } catch (DoesNotExistException) {
                continue;
            }

            // Recipients see the folder identity, never the member list
            // (share-visibility rule, user-sharing spec).
            $memberOf[] = $this->describe(teamFolder: $teamFolder, includeMembers: false);
        }

        return [
            'owned'    => $owned,
            'memberOf' => $memberOf,
        ];
    }//end listForUser()

    /**
     * List the members of a team folder — full list for the owner only.
     *
     * @param string $teamFolderId The TeamFolder UUID
     * @param string $userId       The caller
     *
     * @return TeamFolderMember[] Empty for non-owners
     *
     * @spec openspec/changes/team-folder-sharing/tasks.md#4.1
     */
    public function listMembers(string $teamFolderId, string $userId): array
    {
        try {
            $teamFolder = $this->mapper->findById(id: $teamFolderId);
        } catch (DoesNotExistException) {
            return [];
        }

        if ($teamFolder->getOwnerId() !== $userId) {
            return [];
        }

        return $this->memberMapper->findByTeamFolder(teamFolderId: $teamFolderId);
    }//end listMembers()

    /**
     * Add a member (user or group) to a team folder and return the
     * fan-out payload the browser needs: the newly covered eligible
     * recipients (with their public certificates) and the secrets in the
     * folder subtree to encrypt for them.
     *
     * Idempotent: re-adding an existing membership returns a payload with
     * no new recipients.
     *
     * @param string $teamFolderId The TeamFolder UUID
     * @param string $memberType   The member type (`user`|`group`)
     * @param string $memberId     The Nextcloud user or group ID
     * @param string $userId       The caller (must be the owner)
     *
     * @return array{member:TeamFolderMember,recipients:array<int,array{userId:string,certificate:string}>,secrets:array<int,array{id:string,name:string}>}
     *
     * @throws InvalidArgumentException On invalid input / not authorized
     *
     * @spec openspec/changes/team-folder-sharing/tasks.md#2.2
     */
    public function addMember(string $teamFolderId, string $memberType, string $memberId, string $userId): array
    {
        $teamFolder = $this->loadOwnedTeamFolder(teamFolderId: $teamFolderId, userId: $userId);

        if (in_array($memberType, ['user', 'group'], true) === false) {
            throw new InvalidArgumentException(message: 'memberType must be user or group');
        }

        if ($memberId === '') {
            throw new InvalidArgumentException(message: 'memberId is required');
        }

        if ($memberType === 'user' && $memberId === $teamFolder->getOwnerId()) {
            throw new InvalidArgumentException(message: 'Cannot add the folder owner as a member');
        }

        if ($memberType === 'user' && $this->userManager->get($memberId) === null) {
            throw new InvalidArgumentException(message: 'User not found');
        }

        if ($memberType === 'group' && $this->groupManager->get($memberId) === null) {
            throw new InvalidArgumentException(message: 'Group not found');
        }

        $coveredBefore = $this->effectiveUsers(teamFolderId: $teamFolderId);

        try {
            $membership = $this->memberMapper->findMembership(
                teamFolderId: $teamFolderId,
                memberType: $memberType,
                memberId: $memberId
            );
        } catch (DoesNotExistException) {
            $membership = new TeamFolderMember();
            $membership->setId(Uuid::uuid4()->toString());
            $membership->setTeamFolderId($teamFolderId);
            $membership->setMemberType($memberType);
            $membership->setMemberId($memberId);
            $membership->setAddedBy($userId);
            $membership->setCreatedAt(new DateTime());
            $membership = $this->memberMapper->insert($membership);

            $this->dispatchAudit(
                event: AuditEvent::forUser(
                    actorId: $userId,
                    eventType: AuditEventTypes::TEAM_FOLDER_MEMBER_ADDED,
                    objectType: 'team_folder',
                    objectId: $teamFolderId,
                    objectName: '',
                    metadata: [
                        'memberType' => $memberType,
                        'memberId'   => $memberId,
                    ],
                )
            );
        }//end try

        $newUsers = array_values(
            array_diff(
                $this->expandMember(memberType: $memberType, memberId: $memberId),
                $coveredBefore,
                [$teamFolder->getOwnerId()]
            )
        );

        return [
            'member'     => $membership,
            'recipients' => $this->eligibleRecipients(userIds: $newUsers),
            'secrets'    => $this->subtreeSecretRefs(teamFolder: $teamFolder),
        ];
    }//end addMember()

    /**
     * Remove a membership row — revokes the derived shares of every user
     * that is no longer covered by any remaining membership.
     *
     * @param string $teamFolderId The TeamFolder UUID
     * @param string $membershipId The membership row UUID
     * @param string $userId       The caller (must be the owner)
     *
     * @return int Number of derived shares revoked
     *
     * @throws InvalidArgumentException On not found / not authorized
     *
     * @spec openspec/changes/team-folder-sharing/tasks.md#2.2
     */
    public function removeMember(string $teamFolderId, string $membershipId, string $userId): int
    {
        $this->loadOwnedTeamFolder(teamFolderId: $teamFolderId, userId: $userId);

        try {
            $membership = $this->memberMapper->findById(id: $membershipId);
        } catch (DoesNotExistException) {
            throw new InvalidArgumentException(message: 'Membership not found');
        }

        if ($membership->getTeamFolderId() !== $teamFolderId) {
            throw new InvalidArgumentException(message: 'Membership does not belong to this team folder');
        }

        $this->memberMapper->delete($membership);

        $coveredAfter = $this->effectiveUsers(teamFolderId: $teamFolderId);
        $dropped      = array_diff(
            $this->expandMember(
                memberType: $membership->getMemberType(),
                memberId: $membership->getMemberId()
            ),
            $coveredAfter
        );

        $revoked = 0;
        foreach ($dropped as $droppedUserId) {
            $revoked += $this->revokeDerivedShares(teamFolderId: $teamFolderId, targetUserId: $droppedUserId);
        }

        $this->dispatchAudit(
            event: AuditEvent::forUser(
                actorId: $userId,
                eventType: AuditEventTypes::TEAM_FOLDER_MEMBER_REMOVED,
                objectType: 'team_folder',
                objectId: $teamFolderId,
                objectName: '',
                metadata: [
                    'memberType'   => $membership->getMemberType(),
                    'memberId'     => $membership->getMemberId(),
                    'revokedCount' => $revoked,
                ],
            )
        );

        return $revoked;
    }//end removeMember()

    /**
     * Resolve the effective recipient set of a secret by walking its
     * folder ancestor chain and unioning every ancestor team folder's
     * member set (nested folders inherit, union-only).
     *
     * @param string $secretId The secret UUID
     *
     * @return string[] Recipient user IDs (owner excluded)
     *
     * @spec openspec/changes/team-folder-sharing/tasks.md#2.3
     */
    public function resolveRecipients(string $secretId): array
    {
        try {
            $secret = $this->secretMapper->findById($secretId);
        } catch (DoesNotExistException) {
            return [];
        }

        $folderId = $secret->getFolderId();
        if ($folderId === null || $folderId === '') {
            return [];
        }

        $users = [];
        foreach ($this->ancestorTeamFolders(folderId: $folderId) as $teamFolder) {
            foreach ($this->effectiveUsers(teamFolderId: $teamFolder->getId()) as $memberUserId) {
                $users[$memberUserId] = true;
            }
        }

        unset($users[$secret->getOwnerId()]);

        return array_keys($users);
    }//end resolveRecipients()

    /**
     * Reconciliation: re-derive the expected (secret × recipient) set for
     * a team folder and return the pairs that are missing a ShareTarget,
     * together with recipient certificates so the browser can encrypt
     * them. Idempotent server writes make a partial fan-out self-heal.
     *
     * @param string $teamFolderId The TeamFolder UUID
     * @param string $userId       The caller (must be the owner)
     *
     * @return array{secrets:array<int,array{id:string,name:string}>,recipients:array<int,array{userId:string,certificate:string}>,missing:array<int,array{secretId:string,userId:string}>}
     *
     * @throws InvalidArgumentException On not found / not authorized
     *
     * @spec openspec/changes/team-folder-sharing/tasks.md#2.4
     */
    public function reconcile(string $teamFolderId, string $userId): array
    {
        $teamFolder = $this->loadOwnedTeamFolder(teamFolderId: $teamFolderId, userId: $userId);

        $secrets    = $this->subtreeSecretRefs(teamFolder: $teamFolder);
        $recipients = $this->eligibleRecipients(
            userIds: array_values(
                array_diff(
                    $this->effectiveUsers(teamFolderId: $teamFolderId),
                    [$teamFolder->getOwnerId()]
                )
            )
        );

        $missing = [];
        foreach ($secrets as $secretRef) {
            foreach ($recipients as $recipient) {
                try {
                    $this->shareTargetMapper->findBySourceSecretAndTargetUser(
                        sourceSecretId: $secretRef['id'],
                        targetUserId: $recipient['userId']
                    );
                } catch (DoesNotExistException) {
                    $missing[] = [
                        'secretId' => $secretRef['id'],
                        'userId'   => $recipient['userId'],
                    ];
                }
            }
        }

        return [
            'secrets'    => $secrets,
            'recipients' => $recipients,
            'missing'    => $missing,
        ];
    }//end reconcile()

    /**
     * Register a batch of browser-encrypted fan-out shares. Each row
     * carries the ciphertext of one (source secret × recipient) pair,
     * RSA-encrypted in the owner's browser under the recipient's public
     * certificate. The server creates the recipient's Secret copy AND
     * the provenance-linked ShareTarget in one transaction — it only
     * ever handles ciphertext (ADR-003).
     *
     * Idempotent: an existing (source, recipient) pair is skipped, so a
     * retried or duplicated chunk never double-shares.
     *
     * @param string                         $teamFolderId The TeamFolder UUID
     * @param array<int,array<string,mixed>> $shares       Rows of sourceSecretId, targetUserId,
     *                                                     encryptedKey, encryptedLogin,
     *                                                     encryptedAdditionalFields
     * @param string                         $userId       The caller (must be the owner)
     *
     * @return int Number of fan-out shares created (skips excluded)
     *
     * @throws InvalidArgumentException On not found / not authorized
     *
     * @spec openspec/changes/team-folder-sharing/tasks.md#2.4
     */
    public function registerFanOutShares(string $teamFolderId, array $shares, string $userId): array
    {
        $createdRows = [];
        $teamFolder  = $this->loadOwnedTeamFolder(teamFolderId: $teamFolderId, userId: $userId);

        $subtreeSecretIds = [];
        foreach ($this->subtreeSecretRefs(teamFolder: $teamFolder) as $secretRef) {
            $subtreeSecretIds[$secretRef['id']] = true;
        }

        $created       = 0;
        $newRecipients = [];
        $this->db->beginTransaction();
        try {
            foreach ($shares as $row) {
                $sourceSecretId = (string) ($row['sourceSecretId'] ?? '');
                $targetUserId   = (string) ($row['targetUserId'] ?? '');
                $encryptedKey   = (string) ($row['encryptedKey'] ?? '');

                if ($sourceSecretId === '' || $targetUserId === '' || $encryptedKey === '') {
                    continue;
                }

                // Only secrets inside this team folder's subtree may carry
                // its provenance, and never a copy for the owner.
                if (isset($subtreeSecretIds[$sourceSecretId]) === false
                    || $targetUserId === $teamFolder->getOwnerId()
                ) {
                    continue;
                }

                try {
                    $this->shareTargetMapper->findBySourceSecretAndTargetUser(
                        sourceSecretId: $sourceSecretId,
                        targetUserId: $targetUserId
                    );
                    continue;
                } catch (DoesNotExistException) {
                    // No existing share — create below.
                }

                $copy = $this->createRecipientCopy(
                    sourceSecretId: $sourceSecretId,
                    targetUserId: $targetUserId,
                    encryptedKey: $encryptedKey,
                    encryptedLogin: $this->nullableString(value: $row['encryptedLogin'] ?? null),
                    encryptedAdditionalFields: $this->nullableString(value: $row['encryptedAdditionalFields'] ?? null),
                );
                if ($copy === null) {
                    // Recipient without an active suite — skipped silently.
                    continue;
                }

                $entity = new ShareTarget();
                $entity->setId(Uuid::uuid4()->toString());
                $entity->setSourceSecretId($sourceSecretId);
                $entity->setTargetUserId($targetUserId);
                $entity->setSecretId($copy->getId());
                $entity->setTeamFolderId($teamFolderId);
                $entity->setCreatedBy($userId);
                $entity->setCreatedAt(new DateTime());
                $this->shareTargetMapper->insert($entity);
                ++$created;
                $newRecipients[$targetUserId] = true;
                $createdRows[] = [
                    'sourceSecretId'    => $sourceSecretId,
                    'targetUserId'      => $targetUserId,
                    'recipientSecretId' => $copy->getId(),
                ];
            }//end foreach

            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }//end try

        // One notification per recipient per fan-out run — not per secret.
        foreach (array_keys($newRecipients) as $recipientId) {
            $this->notificationService->notify(
                subject: 'team_folder_shared',
                recipientId: (string) $recipientId,
                params: [
                    'teamFolderId' => $teamFolderId,
                    'sharedBy'     => $userId,
                ],
                objectType: 'team_folder',
                objectId: $teamFolderId,
            );
        }

        return [
            'created' => $created,
            'rows'    => $createdRows,
        ];
    }//end registerFanOutShares()

    /**
     * Group-join propagation: a user joined a group that is a member of
     * one or more team folders — notify each folder owner so they can
     * approve the join (approval triggers the fan-out for that user).
     *
     * @param string $userId  The user newly added to the group
     * @param string $groupId The group that gained the member
     *
     * @return int Number of notifications dispatched
     *
     * @spec openspec/changes/team-folder-sharing/tasks.md#3.1
     */
    public function handleGroupMemberJoin(string $userId, string $groupId): int
    {
        $dispatched = 0;
        foreach ($this->memberMapper->findGroupMemberships(groupId: $groupId) as $membership) {
            try {
                $teamFolder = $this->mapper->findById(id: $membership->getTeamFolderId());
            } catch (DoesNotExistException) {
                continue;
            }

            if ($teamFolder->getOwnerId() === $userId) {
                continue;
            }

            $this->notificationService->notify(
                subject: 'team_folder_join_request',
                recipientId: $teamFolder->getOwnerId(),
                params: [
                    'newMemberId'  => $userId,
                    'groupId'      => $groupId,
                    'teamFolderId' => $teamFolder->getId(),
                ],
                objectType: 'team_folder',
                objectId: $teamFolder->getId(),
            );
            ++$dispatched;
        }//end foreach

        return $dispatched;
    }//end handleGroupMemberJoin()

    /**
     * Owner approval of a group join: returns the fan-out payload for the
     * approved user (their certificate + the subtree secrets) so the
     * browser can encrypt and register the shares.
     *
     * @param string $teamFolderId The TeamFolder UUID
     * @param string $newMemberId  The approved user's Nextcloud user ID
     * @param string $userId       The approver (must be the owner)
     *
     * @return array{recipients:array<int,array{userId:string,certificate:string}>,secrets:array<int,array{id:string,name:string}>}
     *
     * @throws InvalidArgumentException On not found / not authorized / not covered
     *
     * @spec openspec/changes/team-folder-sharing/tasks.md#3.1
     */
    public function approveJoin(string $teamFolderId, string $newMemberId, string $userId): array
    {
        $teamFolder = $this->loadOwnedTeamFolder(teamFolderId: $teamFolderId, userId: $userId);

        if (in_array($newMemberId, $this->effectiveUsers(teamFolderId: $teamFolderId), true) === false) {
            throw new InvalidArgumentException(message: 'User is not covered by this team folder\'s membership');
        }

        return [
            'recipients' => $this->eligibleRecipients(userIds: [$newMemberId]),
            'secrets'    => $this->subtreeSecretRefs(teamFolder: $teamFolder),
        ];
    }//end approveJoin()

    /**
     * Group-leave propagation: revoke the departing user's derived shares
     * for every team folder whose only coverage of the user was the group
     * they just left. Direct shares and other memberships stay intact.
     *
     * @param string $userId  The departing user
     * @param string $groupId The group they left
     *
     * @return int Number of ShareTargets revoked
     *
     * @spec openspec/changes/team-folder-sharing/tasks.md#3.2
     */
    public function handleGroupMemberLeave(string $userId, string $groupId): int
    {
        $revoked = 0;
        foreach ($this->memberMapper->findGroupMemberships(groupId: $groupId) as $membership) {
            $teamFolderId = $membership->getTeamFolderId();
            if (in_array($userId, $this->effectiveUsers(teamFolderId: $teamFolderId), true) === true) {
                // Still covered by a direct membership or another group.
                continue;
            }

            $revoked += $this->revokeDerivedShares(teamFolderId: $teamFolderId, targetUserId: $userId);
        }

        return $revoked;
    }//end handleGroupMemberLeave()

    /**
     * Admin offboarding: revoke every team-folder-derived share held by
     * the leaving user, then transfer each team secret the leaver OWNS to
     * the successor via the existing permanent-delegation mechanics
     * (SecretDelegation row + owner reassignment, mirroring the
     * account-deletion transfer path). Secrets whose successor holds no
     * recipient copy yet are reported as skipped — the admin re-runs the
     * offboarding after adding the successor to the folder.
     *
     * @param string $leavingUserId   The user being offboarded
     * @param string $successorUserId The user taking over owned team secrets
     * @param string $adminId         The caller (instance admin or vault_admin)
     *
     * @return array{revoked:int,transferred:int,skipped:array<int,string>}
     *
     * @throws InvalidArgumentException On invalid input / not authorized
     *
     * @spec openspec/changes/team-folder-sharing/tasks.md#2.5
     */
    public function offboard(string $leavingUserId, string $successorUserId, string $adminId): array
    {
        $this->assertOffboardingAdmin(userId: $adminId);

        if ($leavingUserId === '' || $successorUserId === '') {
            throw new InvalidArgumentException(message: 'leavingUserId and successorUserId are required');
        }

        if ($leavingUserId === $successorUserId) {
            throw new InvalidArgumentException(message: 'Successor must differ from the leaving user');
        }

        // Step 1 — revoke every team-folder-derived share held by the leaver.
        $revoked = 0;
        foreach ($this->shareTargetMapper->findByTargetUser(targetUserId: $leavingUserId) as $row) {
            if ($row->getTeamFolderId() === null || $row->getTeamFolderId() === '') {
                continue;
            }

            $this->deleteRecipientCopy(row: $row);
            $this->shareTargetMapper->delete($row);
            ++$revoked;
        }

        // Step 2 — transfer team secrets the leaver owns to the successor.
        $transferred = 0;
        $skipped     = [];
        foreach ($this->mapper->findByOwner(ownerId: $leavingUserId) as $teamFolder) {
            foreach ($this->subtreeSecretRefs(teamFolder: $teamFolder) as $secretRef) {
                try {
                    $this->shareTargetMapper->findBySourceSecretAndTargetUser(
                        sourceSecretId: $secretRef['id'],
                        targetUserId: $successorUserId
                    );
                } catch (DoesNotExistException) {
                    // Delegation promotes an existing recipient copy; the
                    // successor holds none for this secret yet.
                    $skipped[] = $secretRef['id'];
                    continue;
                }

                $delegation = new SecretDelegation();
                $delegation->setId(Uuid::uuid4()->toString());
                $delegation->setSecretId($secretRef['id']);
                $delegation->setOriginalOwnerId($leavingUserId);
                $delegation->setDelegatedTo($successorUserId);
                $delegation->setDelegatedAt(new DateTime());
                $delegation->setInitiatedBy($adminId);
                $delegation->setIsPermanent(true);
                $this->delegationMapper->insert($delegation);

                $this->secretMapper->reassignOwner(
                    secretId: $secretRef['id'],
                    newOwnerId: $successorUserId
                );
                ++$transferred;
            }//end foreach
        }//end foreach

        $this->logger->info(
            'Offboarded '.$leavingUserId.': revoked '.$revoked.' team shares, transferred '
            .$transferred.' secrets to '.$successorUserId,
            ['app' => 'doriath']
        );

        $this->dispatchAudit(
            event: AuditEvent::forUser(
                actorId: $adminId,
                eventType: AuditEventTypes::TEAM_FOLDER_OFFBOARDED,
                objectType: 'team_folder',
                objectId: $leavingUserId,
                objectName: '',
                metadata: [
                    'leavingUserId'    => $leavingUserId,
                    'successorUserId'  => $successorUserId,
                    'revokedCount'     => $revoked,
                    'transferredCount' => $transferred,
                ],
            )
        );

        return [
            'revoked'     => $revoked,
            'transferred' => $transferred,
            'skipped'     => $skipped,
        ];
    }//end offboard()

    /**
     * Expand a membership to concrete user IDs (a group expands to its
     * current users — static expansion per ADR-003, no live group key).
     *
     * @param string $memberType The member type (`user`|`group`)
     * @param string $memberId   The user or group ID
     *
     * @return string[]
     */
    private function expandMember(string $memberType, string $memberId): array
    {
        if ($memberType === 'user') {
            return [$memberId];
        }

        $group = $this->groupManager->get($memberId);
        if ($group === null) {
            return [];
        }

        $ids = [];
        foreach ($group->getUsers() as $user) {
            $ids[] = $user->getUID();
        }

        return $ids;
    }//end expandMember()

    /**
     * The effective (deduplicated) user set covered by a team folder's
     * current memberships. The owner is NOT excluded here; callers strip
     * the owner where relevant.
     *
     * @param string $teamFolderId The TeamFolder UUID
     *
     * @return string[]
     */
    private function effectiveUsers(string $teamFolderId): array
    {
        $users = [];
        foreach ($this->memberMapper->findByTeamFolder(teamFolderId: $teamFolderId) as $membership) {
            foreach ($this->expandMember(
                memberType: $membership->getMemberType(),
                memberId: $membership->getMemberId()
            ) as $memberUserId) {
                $users[$memberUserId] = true;
            }
        }

        return array_keys($users);
    }//end effectiveUsers()

    /**
     * Filter user IDs to those with an active EncryptionSuite and return
     * their public certificates for browser-side encryption. Users
     * without a suite are skipped silently (§2.2).
     *
     * @param string[] $userIds The candidate user IDs
     *
     * @return array<int,array{userId:string,certificate:string}>
     */
    private function eligibleRecipients(array $userIds): array
    {
        $recipients = [];
        foreach ($userIds as $candidateId) {
            try {
                $suite = $this->suiteMapper->findActiveByOwner(ownerType: 'user', ownerId: $candidateId);
            } catch (DoesNotExistException) {
                continue;
            }

            $recipients[] = [
                'userId'      => $candidateId,
                'certificate' => $suite->getCertificate(),
            ];
        }

        return $recipients;
    }//end eligibleRecipients()

    /**
     * The owner's secrets in the team folder's subtree (id + plaintext
     * display name only — names are server-visible metadata already).
     *
     * @param TeamFolder $teamFolder The team folder
     *
     * @return array<int,array{id:string,name:string}>
     */
    private function subtreeSecretRefs(TeamFolder $teamFolder): array
    {
        $refs      = [];
        $folderIds = $this->folderMapper->getSubtreeIds(folderId: $teamFolder->getFolderId());
        foreach ($folderIds as $folderId) {
            foreach ($this->secretMapper->findByOwner(
                ownerType: 'user',
                ownerId: $teamFolder->getOwnerId(),
                folderId: (string) $folderId
            ) as $secret) {
                $refs[] = [
                    'id'   => $secret->getId(),
                    'name' => $secret->getName(),
                ];
            }
        }

        return $refs;
    }//end subtreeSecretRefs()

    /**
     * Walk a folder's ancestor chain (including itself) and collect every
     * attached TeamFolder, nearest first.
     *
     * @param string $folderId The starting Folder UUID
     *
     * @return TeamFolder[]
     */
    private function ancestorTeamFolders(string $folderId): array
    {
        $found   = [];
        $current = $folderId;
        $guard   = 0;
        while ($current !== null && $current !== '' && $guard < 100) {
            ++$guard;
            try {
                $found[] = $this->mapper->findByFolder(folderId: $current);
            } catch (DoesNotExistException) {
                // This level is not shared — continue up.
            }

            try {
                $folder = $this->folderMapper->findById($current);
            } catch (DoesNotExistException) {
                break;
            }

            $current = $folder->getParentId();
        }

        return $found;
    }//end ancestorTeamFolders()

    /**
     * Revoke one user's derived ShareTargets for one team folder,
     * deleting their recipient Secret copies too.
     *
     * @param string $teamFolderId The TeamFolder UUID
     * @param string $targetUserId The recipient losing access
     *
     * @return int Rows revoked
     */
    private function revokeDerivedShares(string $teamFolderId, string $targetUserId): int
    {
        $revoked = 0;
        foreach ($this->shareTargetMapper->findByTeamFolderAndTargetUser(
            teamFolderId: $teamFolderId,
            targetUserId: $targetUserId
        ) as $row) {
            $this->deleteRecipientCopy(row: $row);
            $this->shareTargetMapper->delete($row);
            ++$revoked;
        }

        return $revoked;
    }//end revokeDerivedShares()

    /**
     * Create the recipient's encrypted Secret copy from browser-supplied
     * ciphertext. Mirrors SecretService::create's entity construction:
     * plaintext metadata (name/url) is copied from the source, the type
     * is re-resolved for the recipient (their unavailable custom types
     * fall back to the system default), the folder is left null (the
     * recipient organises their own tree), and the ciphertext fields are
     * stored verbatim — the server never decrypts anything.
     *
     * @param string      $sourceSecretId            The owner's source secret ID
     * @param string      $targetUserId              The recipient user ID
     * @param string      $encryptedKey              The RSA-encrypted key blob
     * @param string|null $encryptedLogin            The RSA-encrypted login blob
     * @param string|null $encryptedAdditionalFields The RSA-encrypted additional-fields blob
     *
     * @return \OCA\Doriath\Db\Secret|null Null when the recipient has no active suite
     */
    private function createRecipientCopy(
        string $sourceSecretId,
        string $targetUserId,
        string $encryptedKey,
        ?string $encryptedLogin,
        ?string $encryptedAdditionalFields,
    ): ?\OCA\Doriath\Db\Secret {
        try {
            $source = $this->secretMapper->findById($sourceSecretId);
        } catch (DoesNotExistException) {
            return null;
        }

        try {
            $suite = $this->suiteMapper->findActiveByOwner(ownerType: 'user', ownerId: $targetUserId);
        } catch (DoesNotExistException) {
            return null;
        }

        try {
            $typeId = $this->typeService->resolveTypeForSecret($source->getTypeId(), $targetUserId);
        } catch (InvalidArgumentException) {
            // Owner's custom type is not visible to the recipient — default.
            $typeId = $this->typeService->resolveTypeForSecret(null, $targetUserId);
        }

        $now  = new DateTime();
        $copy = new \OCA\Doriath\Db\Secret();
        $copy->setId(Uuid::uuid4()->toString());
        $copy->setName($source->getName());
        $copy->setUrl($source->getUrl());
        $copy->setTypeId($typeId);
        $copy->setFolderId(null);
        $copy->setKey($encryptedKey);
        $copy->setLogin($encryptedLogin);
        $copy->setAdditionalFields($encryptedAdditionalFields);
        $copy->setEncryptionSuiteId($suite->getId());
        $copy->setOwnerType('user');
        $copy->setOwnerId($targetUserId);
        $copy->setCreatedAt($now);
        $copy->setUpdatedAt($now);
        $copy->setKeyUpdatedAt($now);
        $this->secretMapper->insert($copy);

        return $copy;
    }//end createRecipientCopy()

    /**
     * Normalize an optional string value: empty becomes null.
     *
     * @param mixed $value The candidate value
     *
     * @return string|null
     */
    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = (string) $value;

        if ($string === '') {
            return null;
        }

        return $string;
    }//end nullableString()

    /**
     * Delete the recipient's encrypted Secret copy of a ShareTarget row
     * (best-effort — a missing copy is already gone).
     *
     * @param ShareTarget $row The share-target row
     *
     * @return void
     */
    private function deleteRecipientCopy(ShareTarget $row): void
    {
        try {
            $copy = $this->secretMapper->findById($row->getSecretId());
            $this->secretMapper->delete($copy);
        } catch (DoesNotExistException) {
            // Already gone.
        }
    }//end deleteRecipientCopy()

    /**
     * All membership rows that cover a user: direct user rows plus group
     * rows of every group the user belongs to.
     *
     * @param string $userId The user
     *
     * @return TeamFolderMember[]
     */
    private function membershipRowsForUser(string $userId): array
    {
        $rows = [];
        $user = $this->userManager->get($userId);

        // Direct user memberships.
        foreach ($this->userMemberships(userId: $userId) as $row) {
            $rows[] = $row;
        }

        // Group memberships via the user's groups.
        if ($user !== null) {
            foreach ($this->groupManager->getUserGroupIds($user) as $groupId) {
                foreach ($this->memberMapper->findGroupMemberships(groupId: (string) $groupId) as $row) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }//end membershipRowsForUser()

    /**
     * Direct user-type membership rows for a user.
     *
     * @param string $userId The user
     *
     * @return TeamFolderMember[]
     */
    private function userMemberships(string $userId): array
    {
        return $this->memberMapper->findUserMemberships(userId: $userId);
    }//end userMemberships()

    /**
     * Describe a team folder for the API (folder name resolved; members
     * included for the owner view only).
     *
     * @param TeamFolder $teamFolder     The team folder
     * @param bool       $includeMembers Whether to include the member list
     *
     * @return array<string,mixed>
     */
    private function describe(TeamFolder $teamFolder, bool $includeMembers): array
    {
        $folderName = '';
        try {
            $folderName = $this->folderMapper->findById($teamFolder->getFolderId())->getName();
        } catch (DoesNotExistException) {
            // Folder vanished — describe with an empty name.
        }

        $data = [
            'id'         => $teamFolder->getId(),
            'folderId'   => $teamFolder->getFolderId(),
            'folderName' => $folderName,
            'ownerId'    => $teamFolder->getOwnerId(),
            'createdAt'  => $teamFolder->getCreatedAt()?->format('c'),
        ];

        if ($includeMembers === true) {
            $data['members'] = $this->memberMapper->findByTeamFolder(teamFolderId: $teamFolder->getId());
        }

        return $data;
    }//end describe()

    /**
     * Load a Folder and assert user ownership.
     *
     * @param string $folderId The Folder UUID
     * @param string $userId   The candidate owner
     *
     * @return Folder
     *
     * @throws InvalidArgumentException On missing folder / foreign owner
     */
    private function loadOwnedFolder(string $folderId, string $userId): Folder
    {
        try {
            $folder = $this->folderMapper->findById($folderId);
        } catch (DoesNotExistException) {
            throw new InvalidArgumentException(message: 'Folder not found');
        }

        if ($folder->getOwnerType() !== 'user' || $folder->getOwnerId() !== $userId) {
            throw new InvalidArgumentException(message: 'Not authorized to share this folder');
        }

        return $folder;
    }//end loadOwnedFolder()

    /**
     * Load a TeamFolder and assert the caller owns it.
     *
     * @param string $teamFolderId The TeamFolder UUID
     * @param string $userId       The candidate owner
     *
     * @return TeamFolder
     *
     * @throws InvalidArgumentException On missing row / foreign owner
     */
    private function loadOwnedTeamFolder(string $teamFolderId, string $userId): TeamFolder
    {
        try {
            $teamFolder = $this->mapper->findById(id: $teamFolderId);
        } catch (DoesNotExistException) {
            throw new InvalidArgumentException(message: 'Team folder not found');
        }

        if ($teamFolder->getOwnerId() !== $userId) {
            throw new InvalidArgumentException(message: 'Not authorized to manage this team folder');
        }

        return $teamFolder;
    }//end loadOwnedTeamFolder()

    /**
     * Assert the caller may run the offboarding action: a Nextcloud
     * instance admin or a member of the vault_admin group.
     *
     * @param string $userId The candidate admin
     *
     * @return void
     *
     * @throws InvalidArgumentException When unauthorized
     */
    private function assertOffboardingAdmin(string $userId): void
    {
        if ($this->groupManager->isAdmin($userId) === true) {
            return;
        }

        if ($this->groupManager->isInGroup($userId, self::VAULT_ADMIN_GROUP) === true) {
            return;
        }

        throw new InvalidArgumentException(
            message: 'Offboarding requires instance admin or vault_admin membership'
        );
    }//end assertOffboardingAdmin()

    /**
     * Set a membership's permission grade — owner-only; grade changes
     * touch no ciphertext (folder-permission-grades §2.1).
     *
     * @param string $teamFolderId The team folder UUID
     * @param string $memberId     The membership row UUID
     * @param string $grade        The grade (`read`|`write`)
     * @param string $ownerId      The calling user (must own the folder)
     *
     * @return TeamFolderMember
     *
     * @throws InvalidArgumentException On non-owner, unknown member, or invalid grade
     *
     * @spec openspec/changes/folder-permission-grades/specs/folder-permission-grades/spec.md#requirement-grades-per-membership
     */
    public function setMemberGrade(string $teamFolderId, string $memberId, string $grade, string $ownerId): TeamFolderMember
    {
        if (in_array($grade, ['read', 'write'], true) === false) {
            throw new InvalidArgumentException(message: 'grade must be read or write');
        }

        $this->loadOwnedTeamFolder(teamFolderId: $teamFolderId, userId: $ownerId);

        try {
            $member = $this->memberMapper->findById($memberId);
        } catch (DoesNotExistException) {
            throw new InvalidArgumentException(message: 'Membership not found');
        }

        if ($member->getTeamFolderId() !== $teamFolderId) {
            throw new InvalidArgumentException(message: 'Membership not found');
        }

        $member->setGrade($grade);
        $member = $this->memberMapper->update($member);

        $this->dispatchAudit(
            event: AuditEvent::forUser(
                actorId: $ownerId,
                eventType: AuditEventTypes::TEAM_FOLDER_GRADE_CHANGED,
                objectType: 'team_folder',
                objectId: $teamFolderId,
                objectName: '',
                metadata: [
                    'memberType' => $member->getMemberType(),
                    'memberId'   => $member->getMemberId(),
                    'grade'      => $grade,
                ],
            )
        );

        return $member;
    }//end setMemberGrade()

    /**
     * The MAX grade any team-folder membership along a secret's folder
     * ancestor chain grants a user (`write` outranks `read`), or null
     * when nothing applies. Group memberships expand via the Nextcloud
     * group manager (folder-permission-grades §2.2). Server-visible
     * metadata only — never any ciphertext.
     *
     * @param Secret $secret The SOURCE secret
     * @param string $userId The candidate user
     *
     * @return string|null `write`, `read`, or null
     *
     * @spec openspec/changes/folder-permission-grades/specs/folder-permission-grades/spec.md#requirement-effective-grade-resolution
     */
    public function resolveGrade(Secret $secret, string $userId): ?string
    {
        $best     = null;
        $folderId = $secret->getFolderId();
        $hops     = 0;
        while ($folderId !== null && $folderId !== '' && $hops < 50) {
            ++$hops;
            try {
                $teamFolder = $this->mapper->findByFolder($folderId);
                foreach ($this->memberMapper->findByTeamFolder(teamFolderId: $teamFolder->getId()) as $membership) {
                    if ($this->membershipCovers(membership: $membership, userId: $userId) === false) {
                        continue;
                    }

                    if ($membership->effectiveGrade() === 'write') {
                        return 'write';
                    }

                    $best = 'read';
                }
            } catch (DoesNotExistException) {
                // Not a team folder — keep climbing.
            }

            try {
                $folderId = $this->folderMapper->findById($folderId)->getParentId();
            } catch (DoesNotExistException) {
                break;
            }
        }//end while

        return $best;
    }//end resolveGrade()

    /**
     * Whether a membership row covers a user (direct or via group).
     *
     * @param TeamFolderMember $membership The membership row
     * @param string           $userId     The candidate user
     *
     * @return bool
     */
    private function membershipCovers(TeamFolderMember $membership, string $userId): bool
    {
        if ($membership->getMemberType() === 'user') {
            return $membership->getMemberId() === $userId;
        }

        if ($membership->getMemberType() === 'group') {
            return $this->groupManager->isInGroup($userId, $membership->getMemberId());
        }

        return false;
    }//end membershipCovers()
}//end class
