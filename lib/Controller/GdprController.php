<?php

/**
 * Doriath GDPR Controller
 *
 * Exposes the data-subject endpoints (secret-export-gdpr D3/D4): the
 * server-readable personal-data metadata package (GDPR Art. 15) and account
 * data deletion (GDPR Art. 17).
 *
 * Every endpoint is scoped exclusively to the session user — there is no
 * parameter that could select another user (fail-closed, no IDOR). The
 * master-password re-authentication that gates in-app deletion is a CLIENT-SIDE
 * proof of knowledge: under the always-E2E model (ADR-003) the server never
 * sees the master password and cannot verify it, so the typed confirmation
 * phrase is the server-checkable gate while the client enforces re-entry.
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
use OCA\Doriath\Event\GdprExportPerformedEvent;
use OCA\Doriath\Service\AccountDeletionService;
use OCA\Doriath\Service\GdprService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for GDPR data-subject endpoints.
 */
class GdprController extends Controller
{
    /**
     * The exact phrase a user must type to confirm account data deletion.
     *
     * @var string
     */
    public const CONFIRMATION_PHRASE = 'DELETE MY DORIATH DATA';

    /**
     * Constructor for GdprController.
     *
     * @param IRequest                $request          The request
     * @param GdprService             $gdprService      The GDPR metadata service
     * @param AccountDeletionService  $deletionService  The deletion-cascade service
     * @param IUserSession            $userSession      The user session
     * @param IEventDispatcher        $dispatcher       The event dispatcher
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private GdprService $gdprService,
        private AccountDeletionService $deletionService,
        private IUserSession $userSession,
        private IEventDispatcher $dispatcher,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Return the session user's server-readable personal-data metadata package.
     *
     * Self-scoped: there is no user parameter; the data subject is always the
     * authenticated session user. The browser merges this with the
     * client-decrypted vault into the full GDPR export package. Emits
     * GdprExportPerformedEvent recording whether the client included the vault
     * half (reported via the includesVault query flag).
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/secret-export-gdpr/specs/gdpr-compliance/spec.md
     */
    #[NoAdminRequired]
    public function metadata(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $userId   = $user->getUID();
        $document = $this->gdprService->collectMetadata(userId: $userId);

        $includesVault = filter_var(
            $this->request->getParam('includesVault', 'false'),
            FILTER_VALIDATE_BOOLEAN
        );

        $this->dispatcher->dispatchTyped(
            new GdprExportPerformedEvent(userId: $userId, includesVault: $includesVault)
        );

        return new JSONResponse(data: $document);
    }//end metadata()

    /**
     * Delete all of the session user's Doriath data (GDPR Art. 17).
     *
     * Gated by the typed confirmation phrase in the request body. The
     * master-password re-authentication is enforced client-side (proof of
     * knowledge): the server cannot verify the master password under the
     * always-E2E model (ADR-003), so it never sees it. Returns the per-entity
     * DeletionReport counts.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/secret-export-gdpr/specs/gdpr-compliance/spec.md
     */
    #[NoAdminRequired]
    public function deleteAccountData(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $confirmation = (string) $this->request->getParam('confirmation', '');
        if ($confirmation !== self::CONFIRMATION_PHRASE) {
            return new JSONResponse(
                data: ['message' => 'Confirmation phrase does not match'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        $report = $this->deletionService->deleteAllFor(
            userId: $user->getUID(),
            trigger: 'in-app',
        );

        return new JSONResponse(data: ['deleted' => true, 'report' => $report]);
    }//end deleteAccountData()
}//end class
