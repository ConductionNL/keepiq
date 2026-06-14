<?php

/**
 * Doriath Audit Controller
 *
 * Read-only API over the append-only audit log (add-secret-audit-trail §4.1):
 * a per-secret owner-scoped activity endpoint (404-as-403 for non-owners, no
 * existence oracle), a personal activity endpoint strictly scoped to the
 * session user as actor, and an admin-only instance-wide filterable, paginated
 * view. There is deliberately NO mutating verb: the log is append-only at the
 * application surface, so the controller exposes only GET reads.
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
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Service\AuditService;
use OCA\Doriath\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Read-only controller for the audit trail.
 */
class AuditController extends Controller
{
    /**
     * Constructor for AuditController.
     *
     * @param IRequest      $request      The request
     * @param AuditService  $auditService The audit service
     * @param SecretMapper  $secretMapper The secret mapper (for ownership checks)
     * @param IUserSession  $userSession  The user session
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private AuditService $auditService,
        private SecretMapper $secretMapper,
        private IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Resolve the current user's UID or return null.
     *
     * @return string|null
     */
    private function uid(): ?string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return null;
        }

        return $user->getUID();
    }//end uid()

    /**
     * Per-secret activity, owner-scoped, newest first.
     *
     * Returns the SAME 404 response whether the secret does not exist or the
     * requester does not own it — no existence oracle (Per-Secret Activity
     * Views requirement; design D7).
     *
     * @param string $id    The secret ID
     * @param int    $page  1-based page number
     * @param int    $limit Page size
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-4.1
     */
    #[NoAdminRequired]
    public function secret(string $id, int $page=1, int $limit=50): JSONResponse
    {
        $userId = $this->uid();
        if ($userId === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        // Ownership gate: indistinguishable 404 for non-owned and nonexistent.
        try {
            $secret = $this->secretMapper->findById($id);
        } catch (DoesNotExistException | MultipleObjectsReturnedException) {
            return new JSONResponse(data: ['message' => 'Not found'], statusCode: Http::STATUS_NOT_FOUND);
        }

        if ($secret->getOwnerType() !== 'user' || $secret->getOwnerId() !== $userId) {
            return new JSONResponse(data: ['message' => 'Not found'], statusCode: Http::STATUS_NOT_FOUND);
        }

        $limit  = max(1, min(200, $limit));
        $offset = ((max(1, $page) - 1) * $limit);

        return new JSONResponse(
            data: [
                'entries' => $this->auditService->listForObject('secret', $id, $limit, $offset),
                'page'    => max(1, $page),
                'limit'   => $limit,
            ]
        );
    }//end secret()

    /**
     * Personal activity — entries the session user actored, newest first.
     *
     * @param int $page  1-based page number
     * @param int $limit Page size
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-4.1
     */
    #[NoAdminRequired]
    public function me(int $page=1, int $limit=50): JSONResponse
    {
        $userId = $this->uid();
        if ($userId === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $limit  = max(1, min(200, $limit));
        $offset = ((max(1, $page) - 1) * $limit);

        return new JSONResponse(
            data: [
                'entries' => $this->auditService->listForActor($userId, $limit, $offset),
                'page'    => max(1, $page),
                'limit'   => $limit,
            ]
        );
    }//end me()

    /**
     * Admin instance-wide audit view: filterable, paginated, with total count.
     *
     * Non-administrators are rejected by the AuthorizedAdminSetting gate before
     * the method runs (Admin Audit View requirement).
     *
     * @param string|null $eventType  Filter by event type
     * @param string|null $actor      Filter by actor id
     * @param string|null $objectType Filter by object type
     * @param string|null $objectId   Filter by object id
     * @param string|null $from       From date (ISO-8601)
     * @param string|null $to         To date (ISO-8601)
     * @param int         $page       1-based page number
     * @param int         $limit      Page size (default 50)
     *
     * @AuthorizedAdminSetting(AdminSettings::class)
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-4.1
     */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function index(
        ?string $eventType=null,
        ?string $actor=null,
        ?string $objectType=null,
        ?string $objectId=null,
        ?string $from=null,
        ?string $to=null,
        int $page=1,
        int $limit=50,
    ): JSONResponse {
        $filters = [
            'eventType'  => $eventType,
            'actor'      => $actor,
            'objectType' => $objectType,
            'objectId'   => $objectId,
            'from'       => $from,
            'to'         => $to,
        ];

        return new JSONResponse(data: $this->auditService->adminQuery($filters, $page, $limit));
    }//end index()
}//end class
