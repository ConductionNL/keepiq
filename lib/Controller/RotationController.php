<?php

/**
 * Doriath Rotation Controller
 *
 * Authenticated API controller for rotation & expiry
 * (rotation-expiry-policies §5.1): per-secret expiry get/set, expiry
 * policy CRUD, and the rotation-flag lifecycle (list / flag / batch-flag
 * / mark-rotated / dismiss). All methods #[NoAdminRequired]; per-object
 * authorization in the service bodies with no existence oracle.
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
use OCA\Doriath\Exception\ForbiddenException;
use OCA\Doriath\Exception\NotFoundException;
use OCA\Doriath\Service\RotationPolicyService;
use OCA\Doriath\Service\SecretService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Authenticated API controller for rotation & expiry.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) One method per API op.
 */
class RotationController extends OCSController
{
    /**
     * Constructor for RotationController.
     *
     * @param IRequest              $request         The request object
     * @param RotationPolicyService $rotationService The rotation service
     * @param SecretService         $secretService   The secret service (setExpiry)
     * @param IUserSession          $userSession     The user session
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private RotationPolicyService $rotationService,
        private SecretService $secretService,
        private IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Resolve the session user ID or null when unauthenticated.
     *
     * @return string|null
     */
    private function sessionUserId(): ?string
    {
        return $this->userSession->getUser()?->getUID();
    }//end sessionUserId()

    /**
     * Set or clear a secret's expiry (owner-only; never touches
     * ciphertext or key age).
     *
     * @param string      $id        The secret UUID
     * @param string|null $expiresAt ISO-8601 expiry (null/'' = clear)
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/rotation-expiry-policies/specs/rotation-expiry-policies/spec.md#requirement-per-secret-expiry
     */
    #[NoAdminRequired]
    public function setExpiry(string $id, ?string $expiresAt=null): JSONResponse
    {
        $userId = $this->sessionUserId();
        if ($userId === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $when = null;
        if ($expiresAt !== null && $expiresAt !== '') {
            try {
                $when = new DateTime($expiresAt);
            } catch (Exception) {
                return new JSONResponse(
                    data: ['message' => 'Invalid expiresAt'],
                    statusCode: Http::STATUS_BAD_REQUEST
                );
            }
        }

        try {
            $secret = $this->secretService->setExpiry(id: $id, expiresAt: $when, userId: $userId);
        } catch (NotFoundException | ForbiddenException) {
            return new JSONResponse(data: ['message' => 'Not found'], statusCode: Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse(
            data: [
                'secret'          => $secret->jsonSerialize(),
                'effectiveExpiry' => $this->rotationService->resolveEffectiveExpiry(secret: $secret)?->format('c'),
            ]
        );
    }//end setExpiry()

    /**
     * A secret's stored + effective expiry.
     *
     * @param string $id The secret UUID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/rotation-expiry-policies/specs/rotation-expiry-policies/spec.md#requirement-effective-expiry-resolution
     */
    #[NoAdminRequired]
    public function getExpiry(string $id): JSONResponse
    {
        $userId = $this->sessionUserId();
        if ($userId === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        try {
            $secret = $this->secretService->findOwned(id: $id, userId: $userId);
        } catch (NotFoundException | ForbiddenException) {
            return new JSONResponse(data: ['message' => 'Not found'], statusCode: Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse(
            data: [
                'expiresAt'       => $secret->getExpiresAt()?->format('c'),
                'effectiveExpiry' => $this->rotationService->resolveEffectiveExpiry(secret: $secret)?->format('c'),
            ]
        );
    }//end getExpiry()

    /**
     * The caller's applicable expiry policies.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function policies(): JSONResponse
    {
        $userId = $this->sessionUserId();
        if ($userId === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        return new JSONResponse(
            data: array_map(
                static fn ($policy) => $policy->jsonSerialize(),
                $this->rotationService->listPolicies(userId: $userId)
            )
        );
    }//end policies()

    /**
     * Create or update a type/folder expiry policy.
     *
     * @param string   $scope        The scope (`type`|`folder`)
     * @param string   $scopeId      The scoped type/folder id
     * @param int|null $maxAgeDays   Max credential age (null = reminder-only)
     * @param array    $reminderDays Reminder thresholds in days
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/rotation-expiry-policies/specs/rotation-expiry-policies/spec.md#requirement-expiry-policies
     */
    #[NoAdminRequired]
    public function upsertPolicy(string $scope, string $scopeId, ?int $maxAgeDays=null, array $reminderDays=[]): JSONResponse
    {
        $userId = $this->sessionUserId();
        if ($userId === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        try {
            $policy = $this->rotationService->upsertPolicy(
                userId: $userId,
                scope: $scope,
                scopeId: $scopeId,
                maxAgeDays: $maxAgeDays,
                reminderDays: $reminderDays,
            );
        } catch (InvalidArgumentException $exception) {
            return new JSONResponse(
                data: ['message' => $exception->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        return new JSONResponse(data: $policy->jsonSerialize());
    }//end upsertPolicy()

    /**
     * Delete one of the caller's policies.
     *
     * @param string $id The policy UUID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function destroyPolicy(string $id): JSONResponse
    {
        $userId = $this->sessionUserId();
        if ($userId === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        try {
            $this->rotationService->deletePolicy(policyId: $id, userId: $userId);
        } catch (InvalidArgumentException $exception) {
            return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse(data: ['deleted' => true]);
    }//end destroyPolicy()

    /**
     * The caller's open rotation flags.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/rotation-expiry-policies/specs/rotation-expiry-policies/spec.md#requirement-rotation-flags
     */
    #[NoAdminRequired]
    public function flags(): JSONResponse
    {
        $userId = $this->sessionUserId();
        if ($userId === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        return new JSONResponse(
            data: array_map(
                function ($flagRow) use ($userId): array {
                    $row = $flagRow->jsonSerialize();
                    // Enrich with the (plaintext-column) secret name for
                    // list display; flags are few, so per-row lookup is fine.
                    try {
                        $row['secretName'] = $this->secretService
                            ->findOwned(id: $flagRow->getSecretId(), userId: $userId)
                            ->getName();
                    } catch (NotFoundException | ForbiddenException) {
                        $row['secretName'] = '';
                    }

                    return $row;
                },
                $this->rotationService->openFlags(userId: $userId)
            )
        );
    }//end flags()

    /**
     * Flag secrets for rotation (client breach findings send IDs only).
     *
     * @param array $secretIds The secret UUIDs to flag
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/rotation-expiry-policies/specs/rotation-expiry-policies/spec.md#requirement-rotation-flags
     */
    #[NoAdminRequired]
    public function flagBatch(array $secretIds=[]): JSONResponse
    {
        $userId = $this->sessionUserId();
        if ($userId === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $flagged = $this->rotationService->flagBatch(
            userId: $userId,
            secretIds: array_map('strval', $secretIds)
        );

        return new JSONResponse(data: ['flagged' => $flagged]);
    }//end flagBatch()

    /**
     * Mark a flag rotated — resolves ONLY on a proven key advance.
     *
     * @param string $id The flag UUID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/rotation-expiry-policies/specs/rotation-expiry-policies/spec.md#requirement-mark-rotated
     */
    #[NoAdminRequired]
    public function markRotated(string $id): JSONResponse
    {
        $userId = $this->sessionUserId();
        if ($userId === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        try {
            $result = $this->rotationService->markRotated(flagId: $id, userId: $userId);
        } catch (InvalidArgumentException $exception) {
            return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse(data: $result);
    }//end markRotated()

    /**
     * Dismiss a flag without rotation (audited owner judgment call).
     *
     * @param string $id The flag UUID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function dismissFlag(string $id): JSONResponse
    {
        $userId = $this->sessionUserId();
        if ($userId === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        try {
            $this->rotationService->dismiss(flagId: $id, userId: $userId);
        } catch (InvalidArgumentException $exception) {
            return new JSONResponse(data: ['message' => $exception->getMessage()], statusCode: Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse(data: ['dismissed' => true]);
    }//end dismissFlag()
}//end class
