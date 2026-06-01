<?php

/**
 * Doriath Link Share Controller
 *
 * Authenticated API controller for link share CRUD operations performed
 * by the secret owner: list link shares for a secret, create a new link
 * share, and revoke (delete) one. Every method enforces that the
 * requesting user is the owner of the link share / secret.
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

use DateTime;
use Exception;
use InvalidArgumentException;
use OCA\Doriath\AppInfo\Application;
use OCA\Doriath\Service\LinkShareService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * Authenticated API controller for link share CRUD.
 */
class LinkShareController extends OCSController
{
    /**
     * Constructor for LinkShareController.
     *
     * @param IRequest         $request          The request object
     * @param LinkShareService $linkShareService The link share service
     * @param IUserSession     $userSession      The user session
     * @param IURLGenerator    $urlGenerator     The URL generator
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private LinkShareService $linkShareService,
        private IUserSession $userSession,
        private IURLGenerator $urlGenerator,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List the link shares for a secret owned by the current user.
     *
     * @param string $secretId The secret ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function index(string $secretId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $shares = $this->linkShareService->listBySecret(secretId: $secretId, userId: $user->getUID());

        return new JSONResponse(
            data: array_map(
                static fn ($share) => $share->jsonSerialize(),
                $shares
            )
        );
    }//end index()

    /**
     * Create a link share for a secret owned by the current user.
     *
     * @param string      $secretId          The secret ID (from the route)
     * @param string      $encryptedSnapshot The base64 AES-256-GCM blob
     * @param string      $argon2idSalt      The base64 Argon2id salt
     * @param int         $usageLimit        The usage limit (1-10)
     * @param string|null $expiresAt         Optional ISO-8601 expiry timestamp
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @SuppressWarnings(PHPMD.StaticAccess)  DateTime::createFromFormat() is the canonical PHP
     *   factory for parsing an ISO-8601 timestamp; there is no instance-based alternative.
     */
    #[NoAdminRequired]
    public function create(
        string $secretId,
        string $encryptedSnapshot,
        string $argon2idSalt,
        int $usageLimit=1,
        ?string $expiresAt=null,
    ): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $expiry = null;
        if ($expiresAt !== null && $expiresAt !== '') {
            $parsed = DateTime::createFromFormat(DateTime::ATOM, $expiresAt);
            if ($parsed === false) {
                return new JSONResponse(
                    data: ['message' => 'Invalid expiry timestamp'],
                    statusCode: Http::STATUS_BAD_REQUEST
                );
            }

            $expiry = $parsed;
        }

        try {
            $linkShare = $this->linkShareService->create(
                secretId: $secretId,
                encryptedSnapshot: $encryptedSnapshot,
                salt: $argon2idSalt,
                usageLimit: $usageLimit,
                expiresAt: $expiry,
                userId: $user->getUID()
            );
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        $linkUrl = $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->linkToRoute('doriath.dashboard.page').'#/share/link/'.$linkShare->getToken()
        );

        $data            = $linkShare->jsonSerialize();
        $data['linkUrl'] = $linkUrl;

        return new JSONResponse(data: $data, statusCode: Http::STATUS_CREATED);
    }//end create()

    /**
     * Revoke (delete) a link share owned by the current user.
     *
     * @param string $id The link share ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function destroy(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        try {
            $this->linkShareService->delete(id: $id, userId: $user->getUID());
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_NOT_FOUND
            );
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_FORBIDDEN
            );
        } catch (Exception $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        return new JSONResponse(data: ['status' => 'revoked']);
    }//end destroy()
}//end class
