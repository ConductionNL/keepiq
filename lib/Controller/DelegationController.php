<?php

/**
 * Doriath Delegation Controller
 *
 * API controller for secret ownership delegation.
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
use OCA\Doriath\Service\DelegationService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;

/**
 * API controller for SecretDelegation CRUD and reclaim.
 */
class DelegationController extends OCSController
{
    /**
     * Constructor for DelegationController.
     *
     * @param IRequest          $request           The request object
     * @param DelegationService $delegationService The delegation service
     * @param IUserSession      $userSession       The user session
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private DelegationService $delegationService,
        private IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List delegations for a secret (owner/delegate only).
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

        $delegations = $this->delegationService->getDelegationsForSecret($secretId, $userId);

        return new JSONResponse(
            data: array_map(static fn ($d) => $d->jsonSerialize(), $delegations)
        );
    }//end index()

    /**
     * Create a delegation (owner self-delegation or vault-admin power grab).
     *
     * @param string $secretId   The secret ID
     * @param string $delegateTo The delegate user ID
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function create(string $secretId, string $delegateTo): JSONResponse
    {
        $userId = $this->requireUserId();
        if ($userId === null) {
            return $this->unauthorized();
        }

        try {
            $delegation = $this->delegationService->createDelegation($secretId, $delegateTo, $userId);
            return new JSONResponse(data: $delegation->jsonSerialize(), statusCode: Http::STATUS_CREATED);
        } catch (RuntimeException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
        }
    }//end create()

    /**
     * Reclaim (delete) all temporary delegations for a secret.
     *
     * @param string $secretId The secret ID
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function reclaim(string $secretId): JSONResponse
    {
        $userId = $this->requireUserId();
        if ($userId === null) {
            return $this->unauthorized();
        }

        try {
            $count = $this->delegationService->reclaimDelegation($secretId, $userId);
            return new JSONResponse(data: ['reclaimed' => $count]);
        } catch (RuntimeException $e) {
            return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
        }
    }//end reclaim()

    /**
     * Resolve the current authenticated user ID, or null.
     *
     * @return string|null
     */
    private function requireUserId(): ?string
    {
        $user = $this->userSession->getUser();
        return ($user === null ? null : $user->getUID());
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
