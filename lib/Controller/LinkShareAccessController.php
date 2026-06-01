<?php

/**
 * Doriath Link Share Access Controller
 *
 * Public, unauthenticated API controller for the two-phase link share
 * access protocol. Phase 1 (`show`) returns the encrypted snapshot blob
 * and the Argon2id salt for a valid token; Phase 2 (`confirm`) records a
 * successful decryption and increments the usage count. All error cases
 * return a uniform 404 to prevent token enumeration.
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
use OCA\Doriath\Service\LinkShareService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Public API controller for link share access (Phase 1 + Phase 2).
 */
class LinkShareAccessController extends Controller
{
    /**
     * Constructor for LinkShareAccessController.
     *
     * @param IRequest         $request          The request object
     * @param LinkShareService $linkShareService The link share service
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private LinkShareService $linkShareService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Phase 1: fetch the encrypted snapshot blob for a token.
     *
     * Returns the encrypted blob and Argon2id salt the browser needs to
     * derive the key and decrypt the snapshot. Returns 404 for an invalid,
     * expired, usage-exhausted or brute-force-deleted token.
     *
     * @param string $token The access token
     *
     * @PublicPage
     * @NoCSRFRequired
     *
     * @return JSONResponse
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function show(string $token): JSONResponse
    {
        try {
            $linkShare = $this->linkShareService->getByToken($token);
        } catch (DoesNotExistException) {
            return new JSONResponse(
                data: ['message' => 'Link not found or expired'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        return new JSONResponse(data: $linkShare->jsonSerializeForAccess());
    }//end show()

    /**
     * Phase 2: confirm a successful decryption for a token.
     *
     * Increments usage_count atomically, resets failed_attempts and
     * deletes the link share when the usage limit is reached. Returns 404
     * for an invalid or already-exhausted token.
     *
     * @param string $token The access token
     *
     * @PublicPage
     * @NoCSRFRequired
     *
     * @return JSONResponse
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function confirm(string $token): JSONResponse
    {
        try {
            $linkShare = $this->linkShareService->confirmAccess($token);
        } catch (DoesNotExistException) {
            return new JSONResponse(
                data: ['message' => 'Link not found or expired'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        $remaining = ($linkShare->getUsageLimit() - $linkShare->getUsageCount());

        return new JSONResponse(
            data: [
                'usageCount' => $linkShare->getUsageCount(),
                'usageLimit' => $linkShare->getUsageLimit(),
                'remaining'  => max(0, $remaining),
            ]
        );
    }//end confirm()
}//end class
