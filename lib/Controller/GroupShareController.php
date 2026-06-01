<?php

/**
 * Doriath Group Share Controller
 *
 * API controller for Nextcloud group-based secret sharing.
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

use OCA\Doriath\AppInfo\Application;
use OCA\Doriath\Service\GroupShareService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;

/**
 * API controller for GroupShare CRUD and member approval.
 */
class GroupShareController extends OCSController
{
    /**
     * Constructor for GroupShareController.
     *
     * @param IRequest          $request           The request object
     * @param GroupShareService $groupShareService The group share service
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
     * List group shares for a secret (owner/delegate only).
     *
     * @param string $secretId The secret ID
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function index(string $secretId): JSONResponse
    {
        $userId = $this->requireUserId();
        if ($userId === null) {
            return $this->unauthorized();
        }

        $groupShares = $this->groupShareService->getGroupSharesForSecret($secretId, $userId);

        return new JSONResponse(
            data: array_map(static fn ($groupShare) => $groupShare->jsonSerialize(), $groupShares)
        );
    }//end index()

    /**
     * Create a group share and return the eligible member list to encrypt for.
     *
     * @param string $secretId The secret ID
     * @param string $groupId  The Nextcloud group ID
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function create(string $secretId, string $groupId): JSONResponse
    {
        $userId = $this->requireUserId();
        if ($userId === null) {
            return $this->unauthorized();
        }

        try {
            $result = $this->groupShareService->createGroupShare($secretId, $groupId, $userId);
            return new JSONResponse(
                data: [
                    'groupShare'      => $result['groupShare']->jsonSerialize(),
                    'eligibleMembers' => $result['eligibleMembers'],
                ],
                statusCode: Http::STATUS_CREATED
            );
        } catch (RuntimeException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
        }
    }//end create()

    /**
     * Revoke a group share (cascade-deletes derived shares and copies).
     *
     * @param string $id The group share ID
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function destroy(string $id): JSONResponse
    {
        $userId = $this->requireUserId();
        if ($userId === null) {
            return $this->unauthorized();
        }

        try {
            $this->groupShareService->revokeGroupShare($id, $userId);
            return new JSONResponse(data: ['status' => 'revoked']);
        } catch (RuntimeException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
        }
    }//end destroy()

    /**
     * Approve adding a new group member: create their derived share.
     *
     * @param string              $id            The group share ID
     * @param string              $newMemberId   The member to grant access
     * @param array<string,mixed> $encryptedData Client-encrypted blobs + metadata
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function approveNewMember(string $id, string $newMemberId, array $encryptedData=[]): JSONResponse
    {
        $userId = $this->requireUserId();
        if ($userId === null) {
            return $this->unauthorized();
        }

        try {
            $this->groupShareService->approveGroupMemberShare($id, $newMemberId, $encryptedData, $userId);
            return new JSONResponse(data: ['status' => 'approved']);
        } catch (RuntimeException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
        }
    }//end approveNewMember()

    /**
     * Deny adding a new group member (no-op acknowledgement).
     *
     * The GroupShare remains active for future members; no share is created.
     *
     * @param string $id          The group share ID
     * @param string $newMemberId The member that was denied
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function denyNewMember(string $id, string $newMemberId): JSONResponse
    {
        $userId = $this->requireUserId();
        if ($userId === null) {
            return $this->unauthorized();
        }

        // Denial creates no share and leaves the group share intact; the
        // owner's decision is recorded only by dismissing the notification.
        return new JSONResponse(data: ['status' => 'denied', 'groupShareId' => $id, 'member' => $newMemberId]);
    }//end denyNewMember()

    /**
     * Resolve the current authenticated user ID, or null.
     *
     * @return string|null
     */
    private function requireUserId(): ?string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return null;
        }

        return $user->getUID();
    }//end requireUserId()

    /**
     * Build an unauthorized JSON response.
     *
     * @return JSONResponse
     */
    private function unauthorized(): JSONResponse
    {
        return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
    }//end unauthorized()
}//end class
