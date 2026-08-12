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
 * This class owns the ATTACHMENT lifecycle (share, unshare, membership rows)
 * and sequences the collaborators that own the rest:
 *  - TeamFolderQueryService          ownership guards, listings, ancestor-chain
 *                                    recipient and grade resolution
 *  - TeamFolderMembershipResolver    membership validation and expansion,
 *                                    recipient eligibility, subtree secrets
 *  - TeamFolderShareService          the derived ShareTarget rows: fan-out,
 *                                    reconciliation gaps, revocation
 *  - TeamFolderOffboardingService    admin offboarding of a departing user
 *  - TeamFolderAuditor               the team-folder audit vocabulary
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
use OCA\Doriath\Db\TeamFolder;
use OCA\Doriath\Db\TeamFolderMapper;
use OCA\Doriath\Db\TeamFolderMember;
use OCA\Doriath\Db\TeamFolderMemberMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Business logic for the TeamFolder lifecycle.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The service threads
 *   through the team-folder mappers plus the five collaborators that own
 *   the folder fan-out invariants, so the ORDER of the lifecycle lives in
 *   one place.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)   One public method per
 *   API operation of the team-folder lifecycle.
 */
class TeamFolderService
{
    /**
     * Constructor for TeamFolderService.
     *
     * @param TeamFolderMapper             $mapper              The team-folder mapper
     * @param TeamFolderMemberMapper       $memberMapper        The membership mapper
     * @param TeamFolderQueryService       $queries             The team-folder read side
     * @param TeamFolderMembershipResolver $memberships         The membership resolver
     * @param TeamFolderShareService       $shares              The derived-share service
     * @param TeamFolderOffboardingService $offboarding         The offboarding service
     * @param TeamFolderAuditor            $audit               The team-folder auditor
     * @param NotificationService          $notificationService The notification dispatcher
     * @param IDBConnection                $db                  The database connection
     *
     * @return void
     *
     * @spec exclude Constructor wiring only.
     */
    public function __construct(
        private TeamFolderMapper $mapper,
        private TeamFolderMemberMapper $memberMapper,
        private TeamFolderQueryService $queries,
        private TeamFolderMembershipResolver $memberships,
        private TeamFolderShareService $shares,
        private TeamFolderOffboardingService $offboarding,
        private TeamFolderAuditor $audit,
        private NotificationService $notificationService,
        private IDBConnection $db,
    ) {
    }//end __construct()

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
        $folder   = $this->queries->loadOwnedFolder(folderId: $folderId, userId: $userId);
        $existing = $this->queries->findByFolder(folderId: $folder->getId());
        if ($existing !== null) {
            return $existing;
        }

        $entity = new TeamFolder();
        $entity->setId(Uuid::uuid4()->toString());
        $entity->setFolderId($folder->getId());
        $entity->setOwnerId($userId);
        $entity->setCreatedAt(new DateTime());
        $entity->setUpdatedAt(new DateTime());
        $persisted = $this->mapper->insert($entity);

        $this->audit->folderShared(
            actorId: $userId,
            teamFolderId: $persisted->getId(),
            folderName: $folder->getName(),
            folderId: $folder->getId(),
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
        $teamFolder = $this->queries->loadOwnedTeamFolder(teamFolderId: $teamFolderId, userId: $userId);

        $this->db->beginTransaction();
        try {
            $revoked = $this->shares->revokeForTeamFolder(teamFolderId: $teamFolderId);

            $this->memberMapper->deleteByTeamFolder(teamFolderId: $teamFolderId);
            $this->mapper->delete($teamFolder);
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        $this->audit->folderUnshared(
            actorId: $userId,
            teamFolderId: $teamFolderId,
            folderId: $teamFolder->getFolderId(),
            revoked: $revoked,
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
        return $this->queries->listForUser(userId: $userId);
    }//end listForUser()

    /**
     * List the members of a team folder — full list for the owner only.
     *
     * @param string $teamFolderId The TeamFolder UUID
     * @param string $userId       The caller
     *
     * @return array<int,TeamFolderMember> Empty for non-owners
     *
     * @spec openspec/changes/team-folder-sharing/tasks.md#4.1
     */
    public function listMembers(string $teamFolderId, string $userId): array
    {
        return $this->queries->listMembers(teamFolderId: $teamFolderId, userId: $userId);
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
        $teamFolder = $this->queries->loadOwnedTeamFolder(teamFolderId: $teamFolderId, userId: $userId);
        $this->memberships->assertMemberAddable(
            teamFolder: $teamFolder,
            memberType: $memberType,
            memberId: $memberId
        );

        $coveredBefore = $this->memberships->effectiveUsers(teamFolderId: $teamFolderId);
        $membership    = $this->findOrCreateMembership(
            teamFolderId: $teamFolderId,
            memberType: $memberType,
            memberId: $memberId,
            userId: $userId
        );

        $newUsers = array_values(
            array_diff(
                $this->memberships->expandMember(memberType: $memberType, memberId: $memberId),
                $coveredBefore,
                [$teamFolder->getOwnerId()]
            )
        );

        return [
            'member'     => $membership,
            'recipients' => $this->memberships->eligibleRecipients(userIds: $newUsers),
            'secrets'    => $this->memberships->subtreeSecretRefs(teamFolder: $teamFolder),
        ];
    }//end addMember()

    /**
     * Return the existing membership row, or create (and audit) a new one.
     *
     * @param string $teamFolderId The TeamFolder UUID
     * @param string $memberType   The member type (`user`|`group`)
     * @param string $memberId     The Nextcloud user or group ID
     * @param string $userId       The caller adding the member
     *
     * @return TeamFolderMember
     *
     * @spec openspec/changes/team-folder-sharing/tasks.md#2.2
     */
    private function findOrCreateMembership(
        string $teamFolderId,
        string $memberType,
        string $memberId,
        string $userId,
    ): TeamFolderMember {
        try {
            return $this->memberMapper->findMembership(
                teamFolderId: $teamFolderId,
                memberType: $memberType,
                memberId: $memberId
            );
        } catch (DoesNotExistException) {
            // No membership yet — create it below.
        }

        $membership = new TeamFolderMember();
        $membership->setId(Uuid::uuid4()->toString());
        $membership->setTeamFolderId($teamFolderId);
        $membership->setMemberType($memberType);
        $membership->setMemberId($memberId);
        $membership->setAddedBy($userId);
        $membership->setCreatedAt(new DateTime());
        $membership = $this->memberMapper->insert($membership);

        $this->audit->memberAdded(
            actorId: $userId,
            teamFolderId: $teamFolderId,
            memberType: $memberType,
            memberId: $memberId,
        );

        return $membership;
    }//end findOrCreateMembership()

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
        $this->queries->loadOwnedTeamFolder(teamFolderId: $teamFolderId, userId: $userId);

        try {
            $membership = $this->memberMapper->findById(id: $membershipId);
        } catch (DoesNotExistException) {
            throw new InvalidArgumentException(message: 'Membership not found');
        }

        if ($membership->getTeamFolderId() !== $teamFolderId) {
            throw new InvalidArgumentException(message: 'Membership does not belong to this team folder');
        }

        $this->memberMapper->delete($membership);

        $coveredAfter = $this->memberships->effectiveUsers(teamFolderId: $teamFolderId);
        $dropped      = array_diff(
            $this->memberships->expandMember(
                memberType: $membership->getMemberType(),
                memberId: $membership->getMemberId()
            ),
            $coveredAfter
        );

        $revoked = 0;
        foreach ($dropped as $droppedUserId) {
            $revoked += $this->shares->revokeForMember(
                teamFolderId: $teamFolderId,
                targetUserId: $droppedUserId
            );
        }

        $this->audit->memberRemoved(
            actorId: $userId,
            teamFolderId: $teamFolderId,
            memberType: $membership->getMemberType(),
            memberId: $membership->getMemberId(),
            revoked: $revoked,
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
        return $this->queries->resolveRecipients(secretId: $secretId);
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
        $teamFolder = $this->queries->loadOwnedTeamFolder(teamFolderId: $teamFolderId, userId: $userId);

        $secrets    = $this->memberships->subtreeSecretRefs(teamFolder: $teamFolder);
        $recipients = $this->memberships->eligibleRecipients(
            userIds: array_values(
                array_diff(
                    $this->memberships->effectiveUsers(teamFolderId: $teamFolderId),
                    [$teamFolder->getOwnerId()]
                )
            )
        );

        return [
            'secrets'    => $secrets,
            'recipients' => $recipients,
            'missing'    => $this->shares->missingPairs(secrets: $secrets, recipients: $recipients),
        ];
    }//end reconcile()

    /**
     * Register a batch of browser-encrypted fan-out shares — the caller is
     * authorized here, the ciphertext rows are materialised by
     * TeamFolderShareService.
     *
     * @param string                         $teamFolderId The TeamFolder UUID
     * @param array<int,array<string,mixed>> $shares       Rows of sourceSecretId, targetUserId,
     *                                                     encryptedKey, encryptedLogin,
     *                                                     encryptedAdditionalFields
     * @param string                         $userId       The caller (must be the owner)
     *
     * @return array{created: int, rows: array<int,array{sourceSecretId: string, targetUserId: string, recipientSecretId: string}>}
     *
     * @throws InvalidArgumentException On not found / not authorized
     *
     * @spec openspec/changes/team-folder-sharing/tasks.md#2.4
     */
    public function registerFanOutShares(string $teamFolderId, array $shares, string $userId): array
    {
        $teamFolder = $this->queries->loadOwnedTeamFolder(teamFolderId: $teamFolderId, userId: $userId);

        $subtreeSecretIds = [];
        foreach ($this->memberships->subtreeSecretRefs(teamFolder: $teamFolder) as $secretRef) {
            $subtreeSecretIds[$secretRef['id']] = true;
        }

        return $this->shares->registerFanOutShares(
            teamFolder: $teamFolder,
            shares: $shares,
            subtreeSecretIds: $subtreeSecretIds,
            userId: $userId
        );
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
        $teamFolder = $this->queries->loadOwnedTeamFolder(teamFolderId: $teamFolderId, userId: $userId);

        $covered = $this->memberships->effectiveUsers(teamFolderId: $teamFolderId);
        if (in_array($newMemberId, $covered, true) === false) {
            throw new InvalidArgumentException(message: 'User is not covered by this team folder\'s membership');
        }

        return [
            'recipients' => $this->memberships->eligibleRecipients(userIds: [$newMemberId]),
            'secrets'    => $this->memberships->subtreeSecretRefs(teamFolder: $teamFolder),
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
            $covered      = $this->memberships->effectiveUsers(teamFolderId: $teamFolderId);
            if (in_array($userId, $covered, true) === true) {
                // Still covered by a direct membership or another group.
                continue;
            }

            $revoked += $this->shares->revokeForMember(teamFolderId: $teamFolderId, targetUserId: $userId);
        }

        return $revoked;
    }//end handleGroupMemberLeave()

    /**
     * Admin offboarding: revoke every team-folder-derived share held by
     * the leaving user, then transfer each team secret the leaver OWNS to
     * the successor. Run by TeamFolderOffboardingService.
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
        return $this->offboarding->offboard(
            leavingUserId: $leavingUserId,
            successorUserId: $successorUserId,
            adminId: $adminId
        );
    }//end offboard()

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
     * @spec openspec/specs/folder-permission-grades/spec.md#requirement-team-folder-membership-carries-a-read-or-write-grade
     * @spec openspec/specs/folder-permission-grades/spec.md#requirement-grade-changes-and-non-owner-writes-are-audited
     */
    public function setMemberGrade(string $teamFolderId, string $memberId, string $grade, string $ownerId): TeamFolderMember
    {
        if (in_array($grade, ['read', 'write'], true) === false) {
            throw new InvalidArgumentException(message: 'grade must be read or write');
        }

        $this->queries->loadOwnedTeamFolder(teamFolderId: $teamFolderId, userId: $ownerId);

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

        $this->audit->gradeChanged(
            actorId: $ownerId,
            teamFolderId: $teamFolderId,
            memberType: $member->getMemberType(),
            memberId: $member->getMemberId(),
            grade: $grade,
        );

        return $member;
    }//end setMemberGrade()

    /**
     * The MAX grade any team-folder membership along a secret's folder
     * ancestor chain grants a user (`write` outranks `read`), or null
     * when nothing applies.
     *
     * @param Secret $secret The SOURCE secret
     * @param string $userId The candidate user
     *
     * @return string|null `write`, `read`, or null
     *
     * @spec openspec/specs/folder-permission-grades/spec.md#requirement-effective-grade-is-the-highest-grade-along-the-ancestor-folder-chain
     */
    public function resolveGrade(Secret $secret, string $userId): ?string
    {
        return $this->queries->resolveGrade(secret: $secret, userId: $userId);
    }//end resolveGrade()
}//end class
