<?php

/**
 * Doriath Key Generator Controller
 *
 * API controller exposing the stateless key generator.
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
use OCA\Doriath\Service\KeyGeneratorService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;

/**
 * API controller for cryptographically secure key generation.
 */
class KeyGeneratorController extends OCSController
{
    /**
     * Constructor for KeyGeneratorController.
     *
     * @param IRequest            $request      The request object
     * @param KeyGeneratorService $keyGenerator The key generator service
     * @param IUserSession        $userSession  The user session
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private KeyGeneratorService $keyGenerator,
        private IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Generate a cryptographically random key.
     *
     * Accepts a JSON body with optional fields: length, includeSpecialCharacters,
     * excludedCharacters and regex. Any authenticated user may call this endpoint.
     *
     * @param int    $length                   Desired output length
     * @param bool   $includeSpecialCharacters Whether to include the OWASP special set
     * @param string $excludedCharacters       Characters to remove from the resolved set
     * @param string $regex                    Optional regex override
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $includeSpecialCharacters is an
     *   optional field of the JSON request body, not an internal mode switch. The
     *   Nextcloud router binds request fields by NAME, and an optional field must
     *   carry a default — which is the only thing this rule fires on. Splitting the
     *   method would split the route and change the HTTP contract.
     * @SuppressWarnings(PHPMD.LongVariable)        $includeSpecialCharacters is the wire
     *   field name posted by src/dialogs/KeyGeneratorModal.vue; because the router
     *   binds by name, shortening the parameter would break the frontend contract.
     */
    #[NoAdminRequired]
    public function generate(
        int $length=16,
        bool $includeSpecialCharacters=true,
        string $excludedCharacters='',
        string $regex='',
    ): JSONResponse {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(
                data: ['message' => 'Unauthorized'],
                statusCode: Http::STATUS_UNAUTHORIZED
            );
        }

        try {
            $generatedKey = $this->keyGenerator->generate(
                length: $length,
                includeSpecialCharacters: $includeSpecialCharacters,
                excludedCharacters: $excludedCharacters,
                regex: $regex
            );

            return new JSONResponse(data: ['generatedKey' => $generatedKey]);
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        } catch (RuntimeException $e) {
            return new JSONResponse(
                data: ['message' => $e->getMessage()],
                statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }//end try
    }//end generate()
}//end class
