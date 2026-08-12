<?php

/**
 * Doriath Secret Request Service
 *
 * The SecretRequest lifecycle: create / fill / approve / decline / list
 * over SecretRequest rows. The preconditions and authorization rules live
 * in SecretRequestPolicy, the outbound audit + notification signalling in
 * SecretRequestOutbox, and the compromise-recovery locking in
 * SecretRequestSuiteLockService — this class is the state machine that
 * moves the rows between those decisions.
 *
 * @category Service
 * @package  OCA\Doriath\Service
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

namespace OCA\Doriath\Service;

use DateTime;
use InvalidArgumentException;
use OCA\Doriath\Db\SecretRequest;
use OCA\Doriath\Db\SecretRequestMapper;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Business logic for the SecretRequest lifecycle.
 */
class SecretRequestService
{
    /**
     * Constructor for SecretRequestService.
     *
     * @param SecretRequestMapper $mapper           The mapper
     * @param SecretRequestPolicy $policy           The precondition/authorization policy
     * @param SecretRequestOutbox $outbox           The audit + notification outbox
     * @param LoggerInterface     $logger           The logger
     * @param WriteLockService    $writeLockService The compromise-recovery write lock
     *
     * @return void
     */
    public function __construct(
        private SecretRequestMapper $mapper,
        private SecretRequestPolicy $policy,
        private SecretRequestOutbox $outbox,
        private LoggerInterface $logger,
        private WriteLockService $writeLockService,
    ) {
    }//end __construct()

    /**
     * Look up a pending, non-expired request by its public access token.
     *
     * Distinguishes locked / expired / fulfilled / unknown via specific
     * error codes so the public fill page can render targeted messaging.
     *
     * @param string $token The access token
     *
     * @return SecretRequest
     *
     * @throws InvalidArgumentException With code 404 (unknown), 410 (fulfilled),
     *                                  423 (locked) or 408 (expired).
     *
     * @spec openspec/specs/secret-requests/spec.md#requirement-fill-in-via-link
     */
    public function getByToken(string $token): SecretRequest
    {
        return $this->policy->requireOpenByToken(token: $token);
    }//end getByToken()

    /**
     * Mark a pending request as fulfilled from the public fill endpoint.
     *
     * Validates the lifecycle / expiry / requested-fields invariants, atomically
     * flips status to fulfilled, and announces it to the original requester.
     *
     * ⚠️ `$encryptedFields` IS NOT PERSISTED ANYWHERE. This docblock used to say
     * the calling controller was responsible for writing the blobs to the linked
     * Secret row; no caller does. SecretRequestFillController::fill() invokes
     * this method and returns the status, so a fill-in silently discards the
     * value the recipient submitted and the request is marked fulfilled anyway.
     *
     * Two consequences worth knowing before relying on this method:
     *   - the requested value never reaches the vault, so the request is
     *     "fulfilled" with nothing to show for it;
     *   - a secret carrying `possibly_compromised_at` cannot be cleared this way,
     *     because clearing is bound to an actual `key` write (see the
     *     possibly-compromised-flag lifecycle requirement). Whoever wires the
     *     write MUST route it through SecretService::update so the flag clears
     *     and version history snapshots, rather than touching the mapper.
     *
     * @param string              $token           The access token
     * @param array<string,mixed> $encryptedFields A map of fieldName => encryptedValue
     *
     * @return SecretRequest
     *
     * @throws InvalidArgumentException
     *
     * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.5
     */
    public function fill(string $token, array $encryptedFields): SecretRequest
    {
        $entity = $this->policy->requireOpenByToken(token: $token);
        $this->policy->requireAllRequestedFields(entity: $entity, encryptedFields: $encryptedFields);

        // Atomic transition: re-load + flip to defend against a parallel
        // fill that may have raced us between the token lookup and here.
        $current = $this->policy->requirePendingById(requestId: $entity->getId());

        $current->setStatus(SecretRequest::STATUS_FULFILLED);
        $current->setFulfilledAt(new DateTime());
        $persisted = $this->mapper->update($current);

        $this->logger->info(
            'Filled secret request '.$current->getId().' for secret '.$current->getSecretId(),
            ['app' => 'doriath']
        );

        $this->outbox->announceFulfilled(request: $current);

        return $persisted;
    }//end fill()

    /**
     * Create a new pending secret request.
     *
     * @param string        $secretId          The Secret ID (unfilled or re-request)
     * @param string        $encryptionSuiteId The recipient's active suite ID
     * @param array<string> $requestedFields   Field names to be filled in
     * @param bool          $isReRequest       Whether this is a re-request
     * @param DateTime|null $expiresAt         Optional expiry
     * @param string        $userId            The Nextcloud user creating the request
     *
     * @return SecretRequest
     *
     * @throws InvalidArgumentException
     *
     * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.5
     */
    public function create(
        string $secretId,
        string $encryptionSuiteId,
        array $requestedFields,
        bool $isReRequest,
        ?DateTime $expiresAt,
        string $userId,
    ): SecretRequest {
        // Pending requests are locked for the duration of a migration and
        // re-pointed to the new suite on completion. A request created now would
        // be born locked, so refuse it with an explanation instead.
        $this->writeLockService->assertNotWriteLocked(ownerId: $userId);

        if ($secretId === '') {
            throw new InvalidArgumentException(message: 'secretId is required');
        }

        if ($encryptionSuiteId === '') {
            throw new InvalidArgumentException(message: 'encryptionSuiteId is required');
        }

        if ($requestedFields === []) {
            throw new InvalidArgumentException(message: 'requestedFields cannot be empty');
        }

        if ($userId === '') {
            throw new InvalidArgumentException(message: 'userId is required');
        }

        $entity = new SecretRequest();
        $entity->setId(Uuid::uuid4()->toString());
        $entity->setSecretId($secretId);
        $entity->setEncryptionSuiteId($encryptionSuiteId);
        $entity->setToken(bin2hex(random_bytes(16)));
        $encodedFields = json_encode(array_values($requestedFields));
        if ($encodedFields === false) {
            $encodedFields = '[]';
        }

        $entity->setRequestedFields($encodedFields);
        $entity->setStatus(SecretRequest::STATUS_PENDING);
        $entity->setIsReRequest($isReRequest);
        $entity->setExpiresAt($expiresAt);
        $entity->setCreatedBy($userId);
        $entity->setCreatedAt(new DateTime());

        $persisted = $this->mapper->insert($entity);

        // Re-requests dispatch their own REQUEST_RE_REQUESTED event from
        // createReRequest(); a plain create dispatches REQUEST_CREATED.
        if ($isReRequest === false) {
            $this->outbox->recordCreated(userId: $userId, requestId: $persisted->getId());
        }

        return $persisted;
    }//end create()

    /**
     * Create a new pending secret request keyed to an application.
     *
     * Resolves the application's active EncryptionSuite through the policy
     * so the caller does not need to know the suite ID. Enforces the same
     * invariants as `create()` plus an explicit application-active check
     * (no requests for pending or rejected applications).
     *
     * @param string        $secretId        The Secret ID
     * @param string        $applicationId   The recipient application ID
     * @param array<string> $requestedFields Field names to be filled in
     * @param DateTime|null $expiresAt       Optional expiry
     * @param string        $userId          The Nextcloud user creating the request
     *
     * @return SecretRequest
     *
     * @throws InvalidArgumentException When the application has no active suite
     *
     * @spec openspec/changes/implement-secret-requests/tasks.md#task-3.3
     */
    public function createForApplication(
        string $secretId,
        string $applicationId,
        array $requestedFields,
        ?DateTime $expiresAt,
        string $userId,
    ): SecretRequest {
        $suiteId = $this->policy->requireApplicationSuiteId(applicationId: $applicationId);

        return $this->create(
            secretId: $secretId,
            encryptionSuiteId: $suiteId,
            requestedFields: $requestedFields,
            isReRequest: false,
            expiresAt: $expiresAt,
            userId: $userId,
        );
    }//end createForApplication()

    /**
     * Create a re-request for an existing secret.
     *
     * A re-request renews the access to a Secret whose recipient lost
     * the plaintext (rotation, recovery, etc). Guards:
     *   - the Secret must already exist (lookup via SecretMapper);
     *   - no other pending request may be open for the same Secret;
     *   - the caller must own the Secret being re-requested.
     *
     * The EncryptionSuite is reused from the Secret's current suite ID
     * (the recipient encrypts under their existing key material).
     *
     * @param string        $secretId        The Secret ID
     * @param array<string> $requestedFields Field names to be re-filled
     * @param DateTime|null $expiresAt       Optional expiry
     * @param string        $userId          The Nextcloud user creating the re-request
     *
     * @return SecretRequest
     *
     * @throws InvalidArgumentException When the Secret is missing, a pending request exists,
     *                                  or the caller is not the owner.
     *
     * @spec openspec/changes/implement-secret-requests/tasks.md#task-3.4
     */
    public function createReRequest(
        string $secretId,
        array $requestedFields,
        ?DateTime $expiresAt,
        string $userId,
    ): SecretRequest {
        $secret = $this->policy->requireReRequestableSecret(secretId: $secretId, userId: $userId);
        $this->policy->requireNoPendingRequest(secretId: $secretId);

        $persisted = $this->create(
            secretId: $secretId,
            encryptionSuiteId: $secret->getEncryptionSuiteId(),
            requestedFields: $requestedFields,
            isReRequest: true,
            expiresAt: $expiresAt,
            userId: $userId,
        );

        $this->outbox->recordReRequested(userId: $userId, requestId: $persisted->getId());

        return $persisted;
    }//end createReRequest()

    /**
     * Approve (mark fulfilled) a pending secret request.
     *
     * The caller (controller) is responsible for the encryption-blob writes
     * to the linked Secret row before flipping the status. This method
     * only enforces the lifecycle transition and the expiry/ownership
     * checks.
     *
     * @param string $requestId The request ID
     * @param string $userId    The Nextcloud user approving the request
     *
     * @return SecretRequest
     *
     * @throws InvalidArgumentException
     *
     * @spec openspec/specs/secret-requests/spec.md#requirement-write-once
     */
    public function approve(string $requestId, string $userId): SecretRequest
    {
        $entity = $this->policy->requireOwnRequest(requestId: $requestId, userId: $userId);

        if ($entity->getStatus() !== SecretRequest::STATUS_PENDING) {
            throw new InvalidArgumentException(message: 'Request is not pending');
        }

        if ($entity->isExpired() === true) {
            throw new InvalidArgumentException(message: 'Request has expired');
        }

        $entity->setStatus(SecretRequest::STATUS_FULFILLED);
        $entity->setFulfilledAt(new DateTime());

        $this->logger->info(
            'Approved secret request '.$requestId.' for secret '.$entity->getSecretId(),
            ['app' => 'doriath']
        );

        return $this->mapper->update($entity);
    }//end approve()

    /**
     * Decline a pending secret request.
     *
     * @param string $requestId The request ID
     * @param string $userId    The Nextcloud user declining the request
     *
     * @return SecretRequest
     *
     * @throws InvalidArgumentException
     *
     * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.5
     */
    public function decline(string $requestId, string $userId): SecretRequest
    {
        $entity = $this->policy->requireOwnRequest(requestId: $requestId, userId: $userId);

        if ($entity->getStatus() !== SecretRequest::STATUS_PENDING) {
            throw new InvalidArgumentException(message: 'Request is not pending');
        }

        $entity->setStatus(SecretRequest::STATUS_DECLINED);

        $this->logger->info(
            'Declined secret request '.$requestId,
            ['app' => 'doriath']
        );

        $updated = $this->mapper->update($entity);

        $this->outbox->recordRevoked(userId: $userId, requestId: $requestId);

        return $updated;
    }//end decline()

    /**
     * List secret requests created by a given user.
     *
     * @param string $userId The Nextcloud user ID
     *
     * @return SecretRequest[]
     *
     * @spec openspec/specs/secret-requests/spec.md#requirement-create-secret-request
     */
    public function listByUser(string $userId): array
    {
        return $this->mapper->findByCreatedBy($userId);
    }//end listByUser()

    /**
     * List all secret requests for a given Secret — visible only to the
     * Secret owner. Used by the Secret detail sidebar to render the
     * "Requests" history block.
     *
     * @param string $secretId The Secret ID
     * @param string $userId   The requesting Nextcloud user ID
     *
     * @return SecretRequest[]
     *
     * @throws InvalidArgumentException When the Secret does not exist or
     *                                  the caller is not its owner.
     *
     * @spec openspec/changes/implement-secret-requests/tasks.md#task-3.8
     */
    public function listBySecret(string $secretId, string $userId): array
    {
        $this->policy->requireListableSecret(secretId: $secretId, userId: $userId);

        return $this->mapper->findBySecretId($secretId);
    }//end listBySecret()

    /**
     * Cascade-delete all secret requests for a Secret.
     *
     * @param string $secretId The Secret ID
     *
     * @return void
     *
     * @spec openspec/specs/secret-requests/spec.md#requirement-revoke-request
     */
    public function deleteAllForSecret(string $secretId): void
    {
        $this->mapper->deleteBySecretId($secretId);
    }//end deleteAllForSecret()
}//end class
