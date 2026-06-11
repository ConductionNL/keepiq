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
use OCA\Doriath\Db\SecretRequest;
use OCA\Doriath\Db\SecretRequestMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

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
     * @param SecretRequestMapper $mapper The mapper
     * @param LoggerInterface     $logger The logger
     *
     * @return void
     */
    public function __construct(
        private SecretRequestMapper $mapper,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

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

        return $this->mapper->insert($entity);
    }//end create()

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

        return $this->mapper->update($entity);
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
