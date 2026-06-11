<?php

/**
 * Doriath Share Controller
 *
 * Authenticated API controller for user-to-user secret-share CRUD
 * (scaffold). The full sharing flow (group expansion, delegation,
 * sync-on-update) is deferred to the implement-user-sharing build cycle.
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

/**
 * Authenticated API controller for ShareTarget CRUD.
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
     * List share targets for a source secret.
     *
     * @param string $secretId The source secret ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-user-sharing/tasks.md#task-9.1
     */
    #[NoAdminRequired]
    public function index(string $secretId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $shares = $this->shareService->listSharesForSecret(sourceSecretId: $secretId);

        return new JSONResponse(
            data: array_map(
                static fn ($share) => $share->jsonSerialize(),
                $shares
            )
        );
    }//end index()

    /**
     * Create a share target.
     *
     * The browser performs the recipient-side RSA encryption and persists
     * the recipient's Secret copy through the SecretController; this
     * endpoint records the share-target row that links the two.
     *
     * @param string      $secretId          The source secret ID
     * @param string      $targetUserId      The recipient Nextcloud user ID
     * @param string      $recipientSecretId The recipient's encrypted Secret copy ID
     * @param string|null $groupShareId      Optional group-share linkage
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-user-sharing/tasks.md#task-9.1
     */
    #[NoAdminRequired]
    public function create(
        string $secretId,
        string $targetUserId,
        string $recipientSecretId,
        ?string $groupShareId=null,
    ): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        try {
            $share = $this->shareService->createShare(
                sourceSecretId: $secretId,
                targetUserId: $targetUserId,
                recipientSecretId: $recipientSecretId,
                groupShareId: $groupShareId,
                userId: $user->getUID()
            );
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        return new JSONResponse(data: $share->jsonSerialize(), statusCode: Http::STATUS_CREATED);
    }//end create()

    /**
     * Revoke a share target.
     *
     * @param string $id The share-target row ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-user-sharing/tasks.md#task-9.1
     */
    #[NoAdminRequired]
    public function destroy(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        try {
            $this->shareService->revokeShare(shareId: $id, userId: $user->getUID());
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }

        return new JSONResponse(data: ['status' => 'deleted']);
    }//end destroy()
}//end class
