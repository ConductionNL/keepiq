<?php

/**
 * Doriath Team Folder Member Controller
 *
 * Authenticated API controller for team folder MEMBERSHIP
 * (team-folder-sharing §4.1, folder-permission-grades §3.1): list members,
 * add a user/group member, remove a membership row, approve a group-join
 * request, and change a membership's permission grade. All methods are
 * #[NoAdminRequired]; per-object owner/admin authorization happens inside
 * TeamFolderService method bodies (hydra-gate-no-admin-idor).
 *
 * Split from TeamFolderController because membership is its own lifecycle:
 * every method here is keyed by a membership row inside one folder and every
 * one of them can change the fan-out of ciphertext to recipients, whereas the
 * folder-level endpoints (share, unshare, reconcile, register fan-out shares)
 * act on the folder as a whole. The URLs are unchanged — only the route names
 * moved with the methods.
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
 * Authenticated API controller for TeamFolder membership.
 */
class TeamFolderMemberController extends OCSController
{
    /**
     * Constructor for TeamFolderMemberController.
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
