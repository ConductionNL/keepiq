<?php

/**
 * Doriath Secret Request Controller
 *
 * Authenticated API controller for SecretRequest CRUD (scaffold). The
 * #[PublicPage] two-phase fill-in endpoint is deferred to the full
 * implement-secret-requests build cycle.
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
use OCA\Doriath\Service\SecretRequestService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Authenticated API controller for SecretRequest CRUD.
 */
class SecretRequestController extends OCSController
{
    /**
     * Creation path: a plain request against the recipient's suite.
     *
     * @var string
     */
    private const MODE_PLAIN = 'plain';

    /**
     * Creation path: a re-request of an already-filled secret.
     *
     * @var string
     */
    private const MODE_RE_REQUEST = 're_request';

    /**
     * Creation path: a request owned by an application.
     *
     * @var string
     */
    private const MODE_APPLICATION = 'application';

    /**
     * Constructor for SecretRequestController.
     *
     * @param IRequest             $request The request object
     * @param SecretRequestService $service The secret-request service
     * @param IUserSession         $session The user session
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private SecretRequestService $service,
        private IUserSession $session,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List secret requests created by the current user.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-secret-requests/tasks.md#task-4.1
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        $user = $this->session->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $requests = $this->service->listByUser($user->getUID());

        return new JSONResponse(
            data: array_map(static fn ($r) => $r->jsonSerialize(), $requests)
        );
    }//end index()

    /**
     * List secret requests for a given Secret — owner-only.
     *
     * @param string $secretId The Secret ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-secret-requests/tasks.md#task-3.8
     */
    #[NoAdminRequired]
    public function listBySecret(string $secretId): JSONResponse
    {
        $user = $this->session->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        try {
            $requests = $this->service->listBySecret(secretId: $secretId, userId: $user->getUID());
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }

        return new JSONResponse(
            data: array_map(static fn ($r) => $r->jsonSerialize(), $requests)
        );
    }//end listBySecret()

    /**
     * Create a new pending secret request.
     *
     * @param string        $secretId          The Secret ID (unfilled or to-be-overwritten)
     * @param string        $encryptionSuiteId The recipient's active suite ID
     * @param array<string> $requestedFields   Field names to be filled in
     * @param bool          $isReRequest       Whether this is a re-request
     * @param string|null   $expiresAt         Optional ISO-8601 expiry
     * @param string|null   $applicationId     Optional owning application ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-secret-requests/tasks.md#task-4.1
     *
     * $isReRequest is a field of the POST body that the Nextcloud router
     * binds by name, not an internal switch a caller chooses. Splitting it
     * into two methods would split the route, changing the HTTP contract;
     * the discriminator therefore stays and the dispatch to
     * createReRequest() vs create() is made explicit below.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     */
    #[NoAdminRequired]
    public function create(
        string $secretId,
        string $encryptionSuiteId='',
        array $requestedFields=[],
        bool $isReRequest=false,
        ?string $expiresAt=null,
        ?string $applicationId=null,
    ): JSONResponse {
        $user = $this->session->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $expiry = null;
        if ($expiresAt !== null && $expiresAt !== '') {
            try {
                $expiry = new DateTime($expiresAt);
            } catch (Exception) {
                return new JSONResponse(
                    data: ['message' => 'Invalid expiry timestamp'],
                    statusCode: Http::STATUS_BAD_REQUEST
                );
            }
        }

        // Resolve the three creation paths to one explicit mode here, so the
        // dispatcher below is a plain lookup rather than a flag-driven branch.
        // An applicationId wins over the re-request discriminator, matching
        // the original precedence.
        $mode = match (true) {
            ($applicationId !== null && $applicationId !== '') => self::MODE_APPLICATION,
            ($isReRequest === true) => self::MODE_RE_REQUEST,
            default => self::MODE_PLAIN,
        };

        try {
            $entity = $this->dispatchCreate(
                mode: $mode,
                secretId: $secretId,
                encryptionSuiteId: $encryptionSuiteId,
                requestedFields: $requestedFields,
                expiry: $expiry,
                applicationId: (string) $applicationId,
                userId: $user->getUID()
            );
        } catch (InvalidArgumentException $e) {
            $code   = $e->getCode();
            $status = Http::STATUS_BAD_REQUEST;
            if ($code === 403 || $code === 404 || $code === 409) {
                $status = $code;
            }

            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: $status
            );
        }//end try

        return new JSONResponse(data: $entity->jsonSerialize(), statusCode: Http::STATUS_CREATED);
    }//end create()

    /**
     * Route a create to the application, re-request or plain service path.
     *
     * @param string        $mode              One of the MODE_* constants
     * @param string        $secretId          The Secret ID
     * @param string        $encryptionSuiteId The recipient's active suite ID
     * @param array<string> $requestedFields   Field names to be filled in
     * @param DateTime|null $expiry            Parsed expiry, or null
     * @param string        $applicationId     Owning application ID ('' unless
     *                                         $mode is MODE_APPLICATION)
     * @param string        $userId            The requesting user
     *
     * @return \OCA\Doriath\Db\SecretRequest
     *
     * @throws InvalidArgumentException Propagated from the service.
     */
    private function dispatchCreate(
        string $mode,
        string $secretId,
        string $encryptionSuiteId,
        array $requestedFields,
        ?DateTime $expiry,
        string $applicationId,
        string $userId
    ): \OCA\Doriath\Db\SecretRequest {
        if ($mode === self::MODE_APPLICATION) {
            return $this->service->createForApplication(
                secretId: $secretId,
                applicationId: $applicationId,
                requestedFields: $requestedFields,
                expiresAt: $expiry,
                userId: $userId,
            );
        }

        if ($mode === self::MODE_RE_REQUEST) {
            return $this->service->createReRequest(
                secretId: $secretId,
                requestedFields: $requestedFields,
                expiresAt: $expiry,
                userId: $userId,
            );
        }

        return $this->service->create(
            secretId: $secretId,
            encryptionSuiteId: $encryptionSuiteId,
            requestedFields: $requestedFields,
            isReRequest: false,
            expiresAt: $expiry,
            userId: $userId,
        );
    }//end dispatchCreate()

    /**
     * Approve a pending secret request.
     *
     * @param string $id The request ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-secret-requests/tasks.md#task-4.1
     */
    #[NoAdminRequired]
    public function approve(string $id): JSONResponse
    {
        $user = $this->session->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        try {
            $entity = $this->service->approve(requestId: $id, userId: $user->getUID());
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        return new JSONResponse(data: $entity->jsonSerialize());
    }//end approve()

    /**
     * Decline a pending secret request.
     *
     * @param string $id The request ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-secret-requests/tasks.md#task-4.1
     */
    #[NoAdminRequired]
    public function decline(string $id): JSONResponse
    {
        $user = $this->session->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        try {
            $entity = $this->service->decline(requestId: $id, userId: $user->getUID());
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        return new JSONResponse(data: $entity->jsonSerialize());
    }//end decline()

    /**
     * Revoke (cascade-delete) all requests for the secret bound to $id.
     *
     * Scaffold-level revoke: deletes the row through the mapper by relying
     * on the service's owned-request lookup; the full revoke semantics
     * (delete unfilled Secret with new requests, keep Secret on
     * re-requests) ship with the dedicated build cycle.
     *
     * @param string $id The request ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/implement-secret-requests/tasks.md#task-4.1
     */
    #[NoAdminRequired]
    public function destroy(string $id): JSONResponse
    {
        $user = $this->session->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        try {
            $entity = $this->service->decline(requestId: $id, userId: $user->getUID());
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }

        return new JSONResponse(data: ['status' => 'deleted', 'request' => $entity->jsonSerialize()]);
    }//end destroy()
}//end class
