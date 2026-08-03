<?php

/**
 * Doriath Group Share Controller
 *
 * Authenticated API controller for the GroupShare lifecycle:
 *  - index   list group shares for a secret
 *  - create  create a group share and return the per-member fan-out
 *  - destroy revoke a group share (cascade)
 *  - approveNewMember + denyNewMember resolve the
 *    'group_member_added' notification path
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
use OCA\Doriath\Service\GroupShareService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Authenticated API controller for GroupShare CRUD.
 */
class GroupShareController extends OCSController
{
    /**
     * Constructor for GroupShareController.
     *
     * @param IRequest          $request           The request object
     * @param GroupShareService $groupShareService The group-share service
     * @param IUserSession      $userSession       The user session
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private GroupShareService $groupShareService,
        private IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List group shares for a secret.
     *
     * @param string $secretId The source secret ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-user-sharing/tasks.md#9.2
     */
    #[NoAdminRequired]
    public function index(string $secretId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $rows = $this->groupShareService->getGroupSharesForSecret(
            secretId: $secretId,
            userId: $user->getUID()
        );

        return new JSONResponse(
            data: array_map(static fn ($row) => $row->jsonSerialize(), $rows)
        );
    }//end index()

    /**
     * Create a group share. Returns the GroupShare row + the per-member
     * fan-out the browser uses to encrypt the source secret for each
     * eligible member.
     *
     * @param string $secretId The source secret ID
     * @param string $groupId  The Nextcloud group ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-user-sharing/tasks.md#9.2
     */
    #[NoAdminRequired]
    public function create(string $secretId, string $groupId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        try {
            $result = $this->groupShareService->createGroupShare(
                secretId: $secretId,
                groupId: $groupId,
                userId: $user->getUID()
            );
        } catch (InvalidArgumentException $exception) {
            return new JSONResponse(
                data: ['message' => $exception->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        return new JSONResponse(
            data: [
                'groupShare' => $result['groupShare']->jsonSerialize(),
                'members'    => $result['members'],
            ],
            statusCode: Http::STATUS_CREATED
        );
    }//end create()

    /**
     * Revoke a group share.
     *
     * @param string $id The group-share ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-user-sharing/tasks.md#9.2
     */
    #[NoAdminRequired]
    public function destroy(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        try {
            $this->groupShareService->revokeGroupShare(
                groupShareId: $id,
                userId: $user->getUID()
            );
        } catch (InvalidArgumentException $exception) {
            return new JSONResponse(
                data: ['message' => $exception->getMessage()],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }

        return new JSONResponse(data: ['status' => 'deleted']);
    }//end destroy()

    /**
     * Approve a new member's share (resolves the 'group_member_added'
     * notification).
     *
     * @param string $id                The GroupShare ID
     * @param string $newMemberId       The new member's UID
     * @param string $recipientSecretId The recipient's encrypted Secret copy ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-user-sharing/tasks.md#9.2
     */
    #[NoAdminRequired]
    public function approveNewMember(
        string $id,
        string $newMemberId,
        string $recipientSecretId,
    ): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        try {
            $this->groupShareService->approveGroupMemberShare(
                groupShareId: $id,
                newMemberId: $newMemberId,
                recipientSecretId: $recipientSecretId,
                userId: $user->getUID()
            );
        } catch (InvalidArgumentException $exception) {
            return new JSONResponse(
                data: ['message' => $exception->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        return new JSONResponse(data: ['status' => 'approved']);
    }//end approveNewMember()

    /**
     * Deny a new member's share. No state changes server-side — the
     * notification is the only artefact.
     *
     * @param string $id          The GroupShare ID
     * @param string $newMemberId The new member's UID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-user-sharing/tasks.md#9.2
     */
    #[NoAdminRequired]
    public function denyNewMember(string $id, string $newMemberId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        // No server-side state to mutate — the controller exists so the
        // browser can dismiss the notification consistently. The identifiers
        // are echoed back so the client can correlate the acknowledgement
        // with the notification it dismissed.
        return new JSONResponse(
            data: [
                'status'      => 'denied',
                'id'          => $id,
                'newMemberId' => $newMemberId,
            ]
        );
    }//end denyNewMember()
}//end class
