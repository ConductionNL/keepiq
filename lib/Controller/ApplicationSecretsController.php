<?php

/**
 * Doriath Application Secrets Controller
 *
 * Bearer-authenticated read endpoint that returns the encrypted secrets
 * owned by the calling application. The Application entity is injected
 * by JwtAuthMiddleware after the Authorization header has been
 * validated.
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

use OCA\Doriath\AppInfo\Application as DoriathApp;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretMapper;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;

/**
 * Read-only secrets API for Bearer-authenticated applications.
 *
 * Endpoints exposed:
 *
 * - `GET /api/v1/app/secrets`      — list the calling app's secrets.
 * - `GET /api/v1/app/secrets/{id}` — fetch a specific secret (ciphertext only).
 *
 * The route is registered with `#[PublicPage]` so the framework lets the
 * request reach the controller; JwtAuthMiddleware then enforces the
 * Bearer-token requirement before any handler runs. Responses contain
 * the encrypted blobs verbatim — the calling application decrypts them
 * with its private key.
 */
class ApplicationSecretsController extends ApplicationApiController
{
    /**
     * Constructor for ApplicationSecretsController.
     *
     * @param IRequest     $request      The HTTP request
     * @param SecretMapper $secretMapper The secret mapper
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private SecretMapper $secretMapper,
    ) {
        parent::__construct(appName: DoriathApp::APP_ID, request: $request);
    }//end __construct()

    /**
     * List secrets owned by the calling application.
     *
     * @return JSONResponse
     */
    #[PublicPage]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 30, period: 60)]
    public function index(): JSONResponse
    {
        $application = $this->getApplication();
        if ($application === null) {
            return new JSONResponse(
                data: ['message' => 'Bearer token required'],
                statusCode: Http::STATUS_UNAUTHORIZED
            );
        }

        $secrets = $this->secretMapper->findByOwner(
            ownerType: 'application',
            ownerId: $application->getId()
        );

        return new JSONResponse(
            data: [
                'items' => array_map(
                    static fn (Secret $s) => $s->jsonSerialize(),
                    $secrets
                ),
                'total' => count($secrets),
            ]
        );
    }//end index()

    /**
     * Fetch a single secret by ID.
     *
     * @param string $id The secret ID
     *
     * @return JSONResponse
     */
    #[PublicPage]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 30, period: 60)]
    public function show(string $id): JSONResponse
    {
        $application = $this->getApplication();
        if ($application === null) {
            return new JSONResponse(
                data: ['message' => 'Bearer token required'],
                statusCode: Http::STATUS_UNAUTHORIZED
            );
        }

        try {
            $secret = $this->secretMapper->findById($id);
        } catch (DoesNotExistException) {
            return new JSONResponse(
                data: ['message' => 'Secret not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        // Enforce owner equality — apps can only see their own secrets.
        if ($secret->getOwnerType() !== 'application'
            || $secret->getOwnerId() !== $application->getId()
        ) {
            return new JSONResponse(
                data: ['message' => 'Not authorized'],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }

        return new JSONResponse(data: $secret->jsonSerialize());
    }//end show()
}//end class
