<?php

/**
 * Doriath Team Folder Controller
 *
 * Authenticated API controller for team folder sharing
 * (team-folder-sharing §4.1): share/unshare a folder, manage its
 * user/group membership, run the fan-out reconciliation, register
 * browser-encrypted fan-out shares, approve group joins, and run the
 * admin offboarding action. All methods are #[NoAdminRequired]; per-object
 * owner/admin authorization happens inside TeamFolderService method
 * bodies (hydra-gate-no-admin-idor).
 *
 * @category Controller
 * @package  OCA\Doriath\Controller
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

namespace OCA\Doriath\Controller;

use InvalidArgumentException;
use OCA\Doriath\AppInfo\Application;
use OCA\Doriath\Service\TeamFolderService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Authenticated API controller for the TeamFolder lifecycle.
 */
class TeamFolderController extends OCSController
{
    /**
     * Constructor for TeamFolderController.
     *
     * @param IRequest          $request           The request object
     * @param TeamFolderService $teamFolderService The team-folder service
     * @param IUserSession      $userSession       The user session
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private TeamFolderService $teamFolderService,
        private IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Resolve the session user ID or null when unauthenticated.
     *
     * @return string|null
     */
    private function sessionUserId(): ?string
    {
        return $this->userSession->getUser()?->getUID();
    }//end sessionUserId()

    /**
     * List team folders: owned (with members) and shared-to-me.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/team-folder-sharing/tasks.md#4.1
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        $userId = $this->sessionUserId();
        if ($userId === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        return new JSONResponse(data: $this->teamFolderService->listForUser(userId: $userId));
    }//end index()

    /**
     * Share an owned folder — creates the TeamFolder.
     *
     * @param string $folderId The Folder UUID to share
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/team-folder-sharing/tasks.md#4.1
     */
    #[NoAdminRequired]
    public function create(string $folderId): JSONResponse
    {
        $userId = $this->sessionUserId();
        if ($userId === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        try {
            $teamFolder = $this->teamFolderService->shareFolder(folderId: $folderId, userId: $userId);
        } catch (InvalidArgumentException $exception) {
            return new JSONResponse(
                data: ['message' => $exception->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        return new JSONResponse(data: $teamFolder->jsonSerialize(), statusCode: Http::STATUS_CREATED);
    }//end create()

    /**
     * List the members of a team folder (owner sees the full list;
     * everyone else receives an empty list).
     *
     * @param string $id The TeamFolder UUID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/team-folder-sharing/tasks.md#4.1
     */
    #[NoAdminRequired]
    public function members(string $id): JSONResponse
    {
        $userId = $this->sessionUserId();
        if ($userId === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        return new JSONResponse(
            data: array_map(
                static fn ($member) => $member->jsonSerialize(),
                $this->teamFolderService->listMembers(teamFolderId: $id, userId: $userId)
            )
        );
    }//end members()

    /**
     * Add a member (user or group) — returns the fan-out payload for the
     * browser (new eligible recipients with certificates + subtree
     * secrets to encrypt).
     *
     * @param string $id         The TeamFolder UUID
     * @param string $memberType The member type (`user`|`group`)
     * @param string $memberId   The Nextcloud user or group ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/team-folder-sharing/tasks.md#4.1
     */
    #[NoAdminRequired]
    public function addMember(string $id, string $memberType, string $memberId): JSONResponse
    {
        $userId = $this->sessionUserId();
        if ($userId === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        try {
            $payload = $this->teamFolderService->addMember(
                teamFolderId: $id,
                memberType: $memberType,
                memberId: $memberId,
                userId: $userId
            );
        } catch (InvalidArgumentException $exception) {
            return new JSONResponse(
                data: ['message' => $exception->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        return new JSONResponse(
            data: [
                'member'     => $payload['member']->jsonSerialize(),
                'recipients' => $payload['recipients'],
                'secrets'    => $payload['secrets'],
            ],
            statusCode: Http::STATUS_CREATED
        );
    }//end addMember()

    /**
     * Remove a membership row — cascade-revokes derived shares of users
     * no longer covered.
     *
     * @param string $id       The TeamFolder UUID
     * @param string $memberId The membership row UUID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/team-folder-sharing/tasks.md#4.1
     */
    #[NoAdminRequired]
    public function removeMember(string $id, string $memberId): JSONResponse
    {
        $userId = $this->sessionUserId();
        if ($userId === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        try {
            $revoked = $this->teamFolderService->removeMember(
                teamFolderId: $id,
                membershipId: $memberId,
                userId: $userId
            );
        } catch (InvalidArgumentException $exception) {
            return new JSONResponse(
                data: ['message' => $exception->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        return new JSONResponse(data: ['revoked' => $revoked]);
    }//end removeMember()

    /**
     * Unshare a folder — cascade-revokes all derived shares; the folder
     * itself remains as a private folder.
     *
     * @param string $id The TeamFolder UUID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/team-folder-sharing/tasks.md#4.1
     */
    #[NoAdminRequired]
    public function destroy(string $id): JSONResponse
    {
        $userId = $this->sessionUserId();
        if ($userId === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        try {
            $revoked = $this->teamFolderService->unshareFolder(teamFolderId: $id, userId: $userId);
        } catch (InvalidArgumentException $exception) {
            return new JSONResponse(
                data: ['message' => $exception->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        return new JSONResponse(data: ['revoked' => $revoked]);
    }//end destroy()

    /**
     * Reconciliation: expected fan-out state + missing (secret ×
     * recipient) pairs for the browser to encrypt (self-healing after a
     * partial fan-out).
     *
     * @param string $id The TeamFolder UUID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/team-folder-sharing/tasks.md#2.4
     */
    #[NoAdminRequired]
    public function reconcile(string $id): JSONResponse
    {
        $userId = $this->sessionUserId();
        if ($userId === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        try {
            $state = $this->teamFolderService->reconcile(teamFolderId: $id, userId: $userId);
        } catch (InvalidArgumentException $exception) {
            return new JSONResponse(
                data: ['message' => $exception->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        return new JSONResponse(data: $state);
    }//end reconcile()

    /**
     * Register a chunk of browser-encrypted fan-out shares (idempotent
     * upsert — retried chunks never double-share).
     *
     * @param string                                                                               $id     The TeamFolder UUID
     * @param array<int,array{sourceSecretId:string,targetUserId:string,recipientSecretId:string}> $shares The fan-out rows
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/team-folder-sharing/tasks.md#2.4
     */
    #[NoAdminRequired]
    public function registerShares(string $id, array $shares): JSONResponse
    {
        $userId = $this->sessionUserId();
        if ($userId === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        try {
            $result = $this->teamFolderService->registerFanOutShares(
                teamFolderId: $id,
                shares: $shares,
                userId: $userId
            );
        } catch (InvalidArgumentException $exception) {
            return new JSONResponse(
                data: ['message' => $exception->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        return new JSONResponse(data: $result, statusCode: Http::STATUS_CREATED);
    }//end registerShares()

    /**
     * Approve a group-join request — returns the fan-out payload for the
     * approved user.
     *
     * @param string $id          The TeamFolder UUID
     * @param string $newMemberId The approved user's Nextcloud user ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/team-folder-sharing/tasks.md#3.1
     */
    #[NoAdminRequired]
    public function approveJoin(string $id, string $newMemberId): JSONResponse
    {
        $userId = $this->sessionUserId();
        if ($userId === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        try {
            $payload = $this->teamFolderService->approveJoin(
                teamFolderId: $id,
                newMemberId: $newMemberId,
                userId: $userId
            );
        } catch (InvalidArgumentException $exception) {
            return new JSONResponse(
                data: ['message' => $exception->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        return new JSONResponse(data: $payload);
    }//end approveJoin()

    /**
     * Admin offboarding: revoke a leaver's team-derived access and
     * transfer their owned team secrets to a successor. Authorization
     * (instance admin or vault_admin) is asserted in the service body.
     *
     * @param string $leavingUserId   The user being offboarded
     * @param string $successorUserId The successor user
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/team-folder-sharing/tasks.md#2.5
     */
    #[NoAdminRequired]
    public function offboard(string $leavingUserId, string $successorUserId): JSONResponse
    {
        $userId = $this->sessionUserId();
        if ($userId === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        try {
            $summary = $this->teamFolderService->offboard(
                leavingUserId: $leavingUserId,
                successorUserId: $successorUserId,
                adminId: $userId
            );
        } catch (InvalidArgumentException $exception) {
            return new JSONResponse(
                data: ['message' => $exception->getMessage()],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }

        return new JSONResponse(data: $summary);
    }//end offboard()

    /**
     * Set a membership's permission grade (owner-only; grade changes
     * touch no ciphertext).
     *
     * @param string $id       The team folder UUID
     * @param string $memberId The membership row UUID
     * @param string $grade    The grade (`read`|`write`)
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/folder-permission-grades/spec.md#requirement-team-folder-membership-carries-a-read-or-write-grade
     */
    #[NoAdminRequired]
    public function setMemberGrade(string $id, string $memberId, string $grade=''): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        try {
            $member = $this->teamFolderService->setMemberGrade(
                teamFolderId: $id,
                memberId: $memberId,
                grade: $grade,
                ownerId: $user->getUID(),
            );
        } catch (InvalidArgumentException $exception) {
            return new JSONResponse(
                data: ['message' => $exception->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        return new JSONResponse(data: $member->jsonSerialize());
    }//end setMemberGrade()
}//end class
