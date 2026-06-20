<?php

/**
 * Doriath Secret Request Service
 *
 * Smallest scaffold for the secret-request capability — provides
 * create / approve / decline methods over SecretRequest rows. The full
 * public fill-in flow, compromise-locking, re-request handling and
 * Vue UI ship with the dedicated implement-secret-requests build cycle.
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
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\SecretRequest;
use OCA\Doriath\Db\SecretRequestMapper;
use OCA\Doriath\Event\Audit\AuditEvent;
use OCA\Doriath\Event\Audit\AuditEventTypes;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Business logic for SecretRequest lifecycle (scaffold).
 *
 * The two-phase public fill endpoint, the compromise-recovery lock, the
 * notification integration and the re-request decay flow are deferred to
 * the coordinated implement-secret-requests build cycle.
 */
class SecretRequestService
{
    /**
     * Constructor for SecretRequestService.
     *
     * @param SecretRequestMapper      $mapper              The mapper
     * @param LoggerInterface          $logger              The logger
     * @param NotificationService|null $notificationService Optional notification dispatcher
     * @param SecretMapper|null        $secretMapper        Optional Secret mapper for owner lookups
     *
     * @return void
     */
    public function __construct(
        private SecretRequestMapper $mapper,
        private LoggerInterface $logger,
        private ?NotificationService $notificationService=null,
        private ?SecretMapper $secretMapper=null,
        private ?EncryptionSuiteMapper $suiteMapper=null,
        private ?IEventDispatcher $eventDispatcher=null,
    ) {
    }//end __construct()

    /**
     * Dispatch a typed audit event, fail-soft.
     *
     * @param AuditEvent $event The audit event
     *
     * @return void
     *
     * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3
     */
    private function dispatchAudit(AuditEvent $event): void
    {
        $this->eventDispatcher?->dispatchTyped($event);
    }//end dispatchAudit()

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
     */
    public function getByToken(string $token): SecretRequest
    {
        if ($token === '') {
            throw new InvalidArgumentException(message: 'token is required', code: 400);
        }

        try {
            $entity = $this->mapper->findByToken($token);
        } catch (DoesNotExistException) {
            throw new InvalidArgumentException(message: 'Request not found', code: 404);
        }

        switch ($entity->getStatus()) {
            case SecretRequest::STATUS_LOCKED:
                throw new InvalidArgumentException(message: 'Request is temporarily unavailable', code: 423);

            case SecretRequest::STATUS_FULFILLED:
                throw new InvalidArgumentException(message: 'Request was already fulfilled', code: 410);

            case SecretRequest::STATUS_DECLINED:
                throw new InvalidArgumentException(message: 'Request was declined', code: 410);

            case SecretRequest::STATUS_PENDING:
                if ($entity->isExpired() === true) {
                    throw new InvalidArgumentException(message: 'Request has expired', code: 408);
                }
                return $entity;

            default:
                throw new InvalidArgumentException(message: 'Request is in an unknown state', code: 500);
        }
    }//end getByToken()

    /**
     * Mark a pending request as fulfilled from the public fill endpoint.
     *
     * The caller (controller) is responsible for writing the encrypted
     * blobs to the linked Secret row. This method validates the
     * lifecycle / expiry / requested-fields invariants and atomically
     * flips status to fulfilled, then dispatches the request_fulfilled
     * notification to the original requester.
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
        $entity = $this->getByToken(token: $token);

        $requested = json_decode(json: $entity->getRequestedFields(), associative: true);
        if (is_array($requested) === false) {
            $requested = [];
        }

        foreach ($requested as $field) {
            if (array_key_exists($field, $encryptedFields) === false) {
                throw new InvalidArgumentException(message: 'Missing required field: '.$field, code: 400);
            }

            $value = $encryptedFields[$field];
            if (is_string($value) === false || $value === '') {
                throw new InvalidArgumentException(message: 'Empty value for field: '.$field, code: 400);
            }
        }

        // Atomic transition: re-load + flip to defend against a parallel
        // fill that may have raced us between getByToken() and here.
        try {
            $current = $this->mapper->findById($entity->getId());
        } catch (DoesNotExistException) {
            throw new InvalidArgumentException(message: 'Request not found', code: 404);
        }

        if ($current->getStatus() !== SecretRequest::STATUS_PENDING) {
            throw new InvalidArgumentException(message: 'Request is not pending', code: 409);
        }

        $current->setStatus(SecretRequest::STATUS_FULFILLED);
        $current->setFulfilledAt(new DateTime());
        $persisted = $this->mapper->update($current);

        $this->logger->info(
            'Filled secret request '.$current->getId().' for secret '.$current->getSecretId(),
            ['app' => 'doriath']
        );

        // Notify the requester — silently noop when the dependency was
        // not wired (legacy call sites still using the 2-arg constructor).
        if ($this->notificationService !== null && $current->getCreatedBy() !== '') {
            $this->notificationService->notify(
                subject: 'request_fulfilled',
                recipientId: $current->getCreatedBy(),
                params: ['secret_id' => $current->getSecretId()],
                objectType: 'secret',
                objectId: $current->getSecretId(),
            );
        }

        $this->dispatchAudit(
            AuditEvent::forLinkVisitor(
                eventType: AuditEventTypes::REQUEST_FULFILLED,
                objectType: 'secret_request',
                objectId: $current->getId(),
            )
        );

        return $persisted;
    }//end fill()

    /**
     * Lock all pending requests bound to an EncryptionSuite. Invoked by
     * the compromise-recovery flow when the recipient's keys are flagged.
     *
     * @param string $encryptionSuiteId The recipient's old EncryptionSuite ID
     *
     * @return int The number of rows affected.
     */
    public function lockByEncryptionSuiteId(string $encryptionSuiteId): int
    {
        if ($encryptionSuiteId === '') {
            throw new InvalidArgumentException(message: 'encryptionSuiteId is required');
        }

        $count = $this->mapper->lockByEncryptionSuiteId($encryptionSuiteId);

        $this->logger->info(
            'Locked '.$count.' pending secret requests for compromised suite '.$encryptionSuiteId,
            ['app' => 'doriath']
        );

        return $count;
    }//end lockByEncryptionSuiteId()

    /**
     * Re-point locked requests at a new EncryptionSuite + reopen them.
     *
     * @param string $oldEncryptionSuiteId The old EncryptionSuite ID
     * @param string $newEncryptionSuiteId The new EncryptionSuite ID
     *
     * @return int The number of rows affected.
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function unlockAndUpdateSuite(string $oldEncryptionSuiteId, string $newEncryptionSuiteId): int
    {
        if ($oldEncryptionSuiteId === '' || $newEncryptionSuiteId === '') {
            throw new InvalidArgumentException(message: 'Both suite IDs are required');
        }

        if ($oldEncryptionSuiteId === $newEncryptionSuiteId) {
            throw new RuntimeException(message: 'oldEncryptionSuiteId and newEncryptionSuiteId must differ');
        }

        $count = $this->mapper->unlockAndUpdateSuite($oldEncryptionSuiteId, $newEncryptionSuiteId);

        $this->logger->info(
            'Unlocked '.$count.' secret requests by migrating suite '.$oldEncryptionSuiteId.' -> '.$newEncryptionSuiteId,
            ['app' => 'doriath']
        );

        return $count;
    }//end unlockAndUpdateSuite()

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
        $entity->setRequestedFields(json_encode(array_values($requestedFields)) ?: '[]');
        $entity->setStatus(SecretRequest::STATUS_PENDING);
        $entity->setIsReRequest($isReRequest);
        $entity->setExpiresAt($expiresAt);
        $entity->setCreatedBy($userId);
        $entity->setCreatedAt(new DateTime());

        $persisted = $this->mapper->insert($entity);

        // Re-requests dispatch their own REQUEST_RE_REQUESTED event from
        // createReRequest(); a plain create dispatches REQUEST_CREATED.
        if ($isReRequest === false) {
            $this->dispatchAudit(
                AuditEvent::forUser(
                    actorId: $userId,
                    eventType: AuditEventTypes::REQUEST_CREATED,
                    objectType: 'secret_request',
                    objectId: $persisted->getId(),
                )
            );
        }

        return $persisted;
    }//end create()

    /**
     * Create a new pending secret request keyed to an application.
     *
     * Resolves the application's active EncryptionSuite via the injected
     * EncryptionSuiteMapper so the caller does not need to know the
     * suite ID. Enforces the same invariants as `create()` plus an
     * explicit application-active check (no requests for pending or
     * rejected applications).
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
     * @throws RuntimeException When the suite mapper dependency is not wired
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
        if ($applicationId === '') {
            throw new InvalidArgumentException(message: 'applicationId is required');
        }

        if ($this->suiteMapper === null) {
            throw new RuntimeException(message: 'EncryptionSuite mapper not wired for application requests');
        }

        try {
            $suite = $this->suiteMapper->findActiveByOwner('application', $applicationId);
        } catch (DoesNotExistException | MultipleObjectsReturnedException) {
            throw new InvalidArgumentException(
                message: 'No active EncryptionSuite for application '.$applicationId,
                code: 400,
            );
        }

        return $this->create(
            secretId: $secretId,
            encryptionSuiteId: $suite->getId(),
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
     * @throws RuntimeException When the Secret mapper dependency is not wired.
     *
     * @spec openspec/changes/implement-secret-requests/tasks.md#task-3.4
     */
    public function createReRequest(
        string $secretId,
        array $requestedFields,
        ?DateTime $expiresAt,
        string $userId,
    ): SecretRequest {
        if ($secretId === '') {
            throw new InvalidArgumentException(message: 'secretId is required');
        }

        if ($this->secretMapper === null) {
            throw new RuntimeException(message: 'Secret mapper not wired for re-requests');
        }

        try {
            $secret = $this->secretMapper->findById($secretId);
        } catch (DoesNotExistException) {
            throw new InvalidArgumentException(message: 'Secret not found', code: 404);
        }

        if ($secret->getOwnerId() !== $userId) {
            throw new InvalidArgumentException(message: 'Only the secret owner may create a re-request', code: 403);
        }

        // Reject if a pending request is already open — fence-posts both
        // double-submits and the spec invariant "one pending request per
        // secret at a time".
        try {
            $this->mapper->findPendingBySecretId($secretId);
            throw new InvalidArgumentException(
                message: 'A pending request already exists for this secret',
                code: 409,
            );
        } catch (DoesNotExistException) {
            // expected — no pending request, continue.
        }

        $persisted = $this->create(
            secretId: $secretId,
            encryptionSuiteId: $secret->getEncryptionSuiteId(),
            requestedFields: $requestedFields,
            isReRequest: true,
            expiresAt: $expiresAt,
            userId: $userId,
        );

        $this->dispatchAudit(
            AuditEvent::forUser(
                actorId: $userId,
                eventType: AuditEventTypes::REQUEST_RE_REQUESTED,
                objectType: 'secret_request',
                objectId: $persisted->getId(),
            )
        );

        return $persisted;
    }//end createReRequest()

    /**
     * Approve (mark fulfilled) a pending secret request.
     *
     * The caller (controller) is responsible for the encryption-blob writes
     * to the linked Secret row before flipping the status. This scaffold
     * only enforces the lifecycle transition and the expiry/ownership
     * checks.
     *
     * @param string $requestId The request ID
     * @param string $userId    The Nextcloud user approving the request
     *
     * @return SecretRequest
     *
     * @throws InvalidArgumentException
     */
    public function approve(string $requestId, string $userId): SecretRequest
    {
        $entity = $this->findOwnedRequest($requestId, $userId);

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
        $entity = $this->findOwnedRequest($requestId, $userId);

        if ($entity->getStatus() !== SecretRequest::STATUS_PENDING) {
            throw new InvalidArgumentException(message: 'Request is not pending');
        }

        $entity->setStatus(SecretRequest::STATUS_DECLINED);

        $this->logger->info(
            'Declined secret request '.$requestId,
            ['app' => 'doriath']
        );

        $updated = $this->mapper->update($entity);

        $this->dispatchAudit(
            AuditEvent::forUser(
                actorId: $userId,
                eventType: AuditEventTypes::REQUEST_REVOKED,
                objectType: 'secret_request',
                objectId: $requestId,
            )
        );

        return $updated;
    }//end decline()

    /**
     * List secret requests created by a given user.
     *
     * @param string $userId The Nextcloud user ID
     *
     * @return SecretRequest[]
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
        if ($this->secretMapper === null) {
            // Defensive: the bind is optional only to preserve test-mock
            // call sites that do not exercise this path. When invoked
            // without the mapper, refuse rather than skip the ownership
            // check (fail closed).
            throw new InvalidArgumentException(message: 'Ownership lookup unavailable');
        }

        try {
            $secret = $this->secretMapper->findById($secretId);
        } catch (DoesNotExistException) {
            throw new InvalidArgumentException(message: 'Secret not found');
        }

        if ($secret->getOwnerType() !== 'user' || $secret->getOwnerId() !== $userId) {
            throw new InvalidArgumentException(message: 'Not authorized for this secret');
        }

        return $this->mapper->findBySecretId($secretId);
    }//end listBySecret()

    /**
     * Cascade-delete all secret requests for a Secret.
     *
     * @param string $secretId The Secret ID
     *
     * @return void
     */
    public function deleteAllForSecret(string $secretId): void
    {
        $this->mapper->deleteBySecretId($secretId);
    }//end deleteAllForSecret()

    /**
     * Look up a request and verify the caller is its creator.
     *
     * @param string $requestId The request ID
     * @param string $userId    The Nextcloud user
     *
     * @return SecretRequest
     *
     * @throws InvalidArgumentException
     */
    private function findOwnedRequest(string $requestId, string $userId): SecretRequest
    {
        try {
            $entity = $this->mapper->findById($requestId);
        } catch (DoesNotExistException) {
            throw new InvalidArgumentException(message: 'Request not found');
        }

        if ($entity->getCreatedBy() !== $userId) {
            throw new InvalidArgumentException(message: 'Not authorized for this request');
        }

        return $entity;
    }//end findOwnedRequest()
}//end class
