<?php

/**
 * Doriath Share Controller
 *
 * API controller for user-to-user secret sharing.
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
 * API controller for SecretShare CRUD and sync-on-update.
 */
class ShareController extends OCSController
{
    /**
     * Constructor for ShareController.
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
     * List shares for a secret (owner/delegate only; recipients get empty).
     *
     * @param string $sourceSecretId The source secret ID
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function index(string $sourceSecretId): JSONResponse
    {
        $userId = $this->requireUserId();
        if ($userId === null) {
            return $this->unauthorized();
        }

        $shares = $this->shareService->getSharesForSecret($sourceSecretId, $userId);

        return new JSONResponse(
            data: array_map(static fn ($share) => $share->jsonSerialize(), $shares)
        );
    }//end index()

    /**
     * Create a single share (encrypted copy).
     *
     * @param string              $sourceSecretId The source secret ID
     * @param string              $targetUserId   The recipient user ID
     * @param array<string,mixed> $encryptedData  Client-encrypted blobs + metadata
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function create(string $sourceSecretId, string $targetUserId, array $encryptedData=[]): JSONResponse
    {
        $userId = $this->requireUserId();
        if ($userId === null) {
            return $this->unauthorized();
        }

        try {
            $share = $this->shareService->createShare($sourceSecretId, $targetUserId, $encryptedData, $userId);
            return new JSONResponse(data: $share->jsonSerialize(), statusCode: Http::STATUS_CREATED);
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
        } catch (RuntimeException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
        }
    }//end create()

    /**
     * Create multiple shares for a group expansion.
     *
     * @param string                         $sourceSecretId The source secret ID
     * @param array<int,array<string,mixed>> $shares         List of {targetUserId, encryptedData}
     * @param string                         $groupShareId   The owning group share ID
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function createBatch(string $sourceSecretId, array $shares=[], string $groupShareId=''): JSONResponse
    {
        $userId = $this->requireUserId();
        if ($userId === null) {
            return $this->unauthorized();
        }

        try {
            $created = $this->shareService->createBatchShares($sourceSecretId, $shares, $groupShareId, $userId);
            return new JSONResponse(
                data: array_map(static fn ($share) => $share->jsonSerialize(), $created),
                statusCode: Http::STATUS_CREATED
            );
        } catch (RuntimeException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
        }
    }//end createBatch()

    /**
     * Revoke a share.
     *
     * @param string $id The share ID
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
            $this->shareService->revokeShare($id, $userId);
            return new JSONResponse(data: ['status' => 'revoked']);
        } catch (RuntimeException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
        }
    }//end destroy()

    /**
     * Sync updated encrypted blobs to all copies of a shared secret.
     *
     * @param string                         $id                The source secret ID
     * @param array<int,array<string,mixed>> $updates           Per-copy encrypted blobs
     * @param string|null                    $expectedUpdatedAt Optimistic-lock timestamp
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function sync(string $id, array $updates=[], ?string $expectedUpdatedAt=null): JSONResponse
    {
        $userId = $this->requireUserId();
        if ($userId === null) {
            return $this->unauthorized();
        }

        try {
            $count = $this->shareService->syncUpdate($id, $updates, $userId, $expectedUpdatedAt);
            return new JSONResponse(data: ['updated' => $count]);
        } catch (RuntimeException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
        }
    }//end sync()

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
