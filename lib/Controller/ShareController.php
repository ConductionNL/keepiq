<?php

/**
 * Doriath Share Controller
 *
 * API controller for user-level secret sharing: create, batch create, revoke, list and sync.
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

use Exception;
use InvalidArgumentException;
use OCA\Doriath\AppInfo\Application;
use OCA\Doriath\Service\ShareService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * API controller for user-level secret sharing.
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
     * List all shares for a given source secret.
     *
     * @param string $secretId The source secret ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    public function index(string $secretId): JSONResponse
    {
        $userId = $this->userSession->getUser()->getUID();

        try {
            $shares = $this->shareService->getSharesForSecret(
                sourceSecretId: $secretId,
                userId: $userId
            );

            $data = array_map(
                callback: static fn($share) => $share->jsonSerialize(),
                array: $shares
            );

            return new JSONResponse(data: $data);
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_FORBIDDEN
            );
        } catch (Exception $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }//end try
    }//end index()

    /**
     * Create a share of a secret for a target user.
     *
     * @param string      $sourceSecretId            The source secret ID
     * @param string      $targetUserId              The recipient user ID
     * @param string      $encryptedKey              The encrypted key blob for the recipient
     * @param string|null $encryptedLogin            The encrypted login blob for the recipient
     * @param string|null $encryptedAdditionalFields The encrypted additional fields for the recipient
     * @param string      $name                      The secret name
     * @param string|null $url                       The associated URL
     * @param string      $typeId                    The secret type ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    public function create(
        string $sourceSecretId,
        string $targetUserId,
        string $encryptedKey,
        ?string $encryptedLogin=null,
        ?string $encryptedAdditionalFields=null,
        string $name='',
        ?string $url=null,
        string $typeId='',
    ): JSONResponse {
        $userId = $this->userSession->getUser()->getUID();

        try {
            $share = $this->shareService->createShare(
                sourceSecretId: $sourceSecretId,
                targetUserId: $targetUserId,
                encryptedData: [
                    'key'              => $encryptedKey,
                    'login'            => $encryptedLogin,
                    'additionalFields' => $encryptedAdditionalFields,
                    'name'             => $name,
                    'url'              => $url,
                    'typeId'           => $typeId,
                ],
                userId: $userId
            );

            return new JSONResponse(data: $share->jsonSerialize(), statusCode: Http::STATUS_CREATED);
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_FORBIDDEN
            );
        } catch (Exception $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }//end try
    }//end create()

    /**
     * Create multiple shares in a batch operation.
     *
     * Reads 'shares' from the request body — each entry must contain targetUserId
     * and encrypted fields. Optionally a 'groupShareId' can be provided.
     *
     * @param string $sourceSecretId The source secret ID
     * @param string $groupShareId   The group share ID to associate with each created share
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    public function createBatch(string $sourceSecretId, string $groupShareId=''): JSONResponse
    {
        $userId = $this->userSession->getUser()->getUID();
        $shares = $this->request->getParam(key: 'shares', default: []);

        if (is_array($shares) === false) {
            $shares = [];
        }

        $resolvedGroupShareId = null;
        if ($groupShareId !== '') {
            $resolvedGroupShareId = $groupShareId;
        }

        try {
            $created = $this->shareService->createBatchShares(
                sourceSecretId: $sourceSecretId,
                shares: $shares,
                groupShareId: $resolvedGroupShareId,
                userId: $userId
            );

            $data = array_map(
                callback: static fn($share) => $share->jsonSerialize(),
                array: $created
            );

            return new JSONResponse(data: $data, statusCode: Http::STATUS_CREATED);
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_FORBIDDEN
            );
        } catch (Exception $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }//end try
    }//end createBatch()

    /**
     * Revoke a share by ID.
     *
     * @param string $id The share ID to revoke
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    public function destroy(string $id): JSONResponse
    {
        $userId = $this->userSession->getUser()->getUID();

        try {
            $this->shareService->revokeShare(shareId: $id, userId: $userId);

            return new JSONResponse(data: ['message' => 'Share revoked successfully']);
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_FORBIDDEN
            );
        } catch (Exception $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }
    }//end destroy()

    /**
     * Sync updated encrypted fields for shared secret copies.
     *
     * Reads 'updates' from the request body — each entry must contain
     * secretId, key, login and additionalFields.
     *
     * @param string $secretId The source secret ID (for logging)
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    public function sync(string $secretId): JSONResponse
    {
        $userId  = $this->userSession->getUser()->getUID();
        $updates = $this->request->getParam(key: 'updates', default: []);

        if (is_array($updates) === false) {
            $updates = [];
        }

        try {
            $this->shareService->syncUpdate(
                secretId: $secretId,
                updates: $updates,
                userId: $userId
            );

            return new JSONResponse(data: ['message' => 'Sync completed']);
        } catch (Exception $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }
    }//end sync()
}//end class
