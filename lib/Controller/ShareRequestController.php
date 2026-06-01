<?php

/**
 * Doriath Share Request Controller
 *
 * API controller for recipient-initiated share requests. The request itself is
 * a Nextcloud notification; these endpoints submit and resolve it.
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
use OCA\Doriath\Service\ShareService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;

/**
 * API controller for share requests.
 */
class ShareRequestController extends OCSController
{
    /**
     * Constructor for ShareRequestController.
     *
     * @param IRequest     $request      The request object
     * @param ShareService $shareService The share service
     * @param IUserSession $userSession  The user session
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private ShareService $shareService,
        private IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Submit a share request (recipient asks the owner to share with a third party).
     *
     * @param string $sourceSecretId The source secret ID
     * @param string $targetUserId   The proposed new recipient
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function create(string $sourceSecretId, string $targetUserId): JSONResponse
    {
        $userId = $this->requireUserId();
        if ($userId === null) {
            return $this->unauthorized();
        }

        try {
            $this->shareService->submitShareRequest($sourceSecretId, $targetUserId, $userId);
            return new JSONResponse(data: ['status' => 'requested'], statusCode: Http::STATUS_CREATED);
        } catch (RuntimeException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
        }
    }//end create()

    /**
     * Approve a share request: create the share directly from owner to target.
     *
     * @param string              $sourceSecretId The source secret ID
     * @param string              $targetUserId   The requested recipient
     * @param string              $requesterId    The recipient who requested
     * @param array<string,mixed> $encryptedData  Client-encrypted blobs + metadata
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function approve(
        string $sourceSecretId,
        string $targetUserId,
        string $requesterId,
        array $encryptedData=[],
    ): JSONResponse {
        $userId = $this->requireUserId();
        if ($userId === null) {
            return $this->unauthorized();
        }

        try {
            $share = $this->shareService->createShare($sourceSecretId, $targetUserId, $encryptedData, $userId);
            $this->shareService->notifyShareRequestApproved($sourceSecretId, $requesterId);
            return new JSONResponse(data: $share->jsonSerialize(), statusCode: Http::STATUS_CREATED);
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
        } catch (RuntimeException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
        }
    }//end approve()

    /**
     * Deny a share request: notify the requester, create no share.
     *
     * @param string $sourceSecretId The source secret ID
     * @param string $requesterId    The recipient who requested
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function deny(string $sourceSecretId, string $requesterId): JSONResponse
    {
        $userId = $this->requireUserId();
        if ($userId === null) {
            return $this->unauthorized();
        }

        try {
            $this->shareService->denyShareRequest($sourceSecretId, $requesterId, $userId);
            return new JSONResponse(data: ['status' => 'denied']);
        } catch (RuntimeException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
        }
    }//end deny()

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
