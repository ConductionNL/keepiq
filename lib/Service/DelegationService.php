<?php

/**
 * Doriath Delegation Service
 *
 * Business logic for secret ownership delegation: create, reclaim, list and make permanent.
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
use OCA\Doriath\Db\SecretDelegation;
use OCA\Doriath\Db\SecretDelegationMapper;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\SecretShareMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Business logic for secret ownership delegation.
 */
class DelegationService
{
    /**
     * Constructor for DelegationService.
     *
     * @param SecretDelegationMapper $delegationMapper The secret delegation mapper
     * @param SecretShareMapper      $shareMapper      The secret share mapper
     * @param SecretMapper           $secretMapper     The secret mapper
     * @param LoggerInterface        $logger           The logger interface
     *
     * @return void
     */
    public function __construct(
        private SecretDelegationMapper $delegationMapper,
        private SecretShareMapper $shareMapper,
        private SecretMapper $secretMapper,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Create a delegation of a secret to another user.
     *
     * The delegatee must already hold a share of the secret (validated via
     * SecretShareMapper). The delegation is temporary (isPermanent = false) by default.
     *
     * @param string $secretId    The secret ID to delegate
     * @param string $delegateTo  The Nextcloud user ID to delegate to
     * @param string $initiatedBy The Nextcloud user ID initiating the delegation
     *
     * @return SecretDelegation
     *
     * @throws InvalidArgumentException When the delegatee does not hold a share of the secret
     * @throws DoesNotExistException    When the secret does not exist
     */
    public function createDelegation(
        string $secretId,
        string $delegateTo,
        string $initiatedBy,
    ): SecretDelegation {
        $secret = $this->secretMapper->findById(id: $secretId);

        // Validate the delegatee holds a share.
        try {
            $this->shareMapper->findBySourceSecretAndTargetUser(
                sourceSecretId: $secretId,
                targetUserId: $delegateTo
            );
        } catch (DoesNotExistException) {
            throw new InvalidArgumentException(
                "User '{$delegateTo}' does not hold a share of secret '{$secretId}'"
            );
        }

        $delegation = new SecretDelegation();
        $delegation->setId(Uuid::uuid4()->toString());
        $delegation->setSecretId($secretId);
        $delegation->setOriginalOwnerId($secret->getOwnerId());
        $delegation->setDelegatedTo($delegateTo);
        $delegation->setDelegatedAt(new DateTime());
        $delegation->setInitiatedBy($initiatedBy);
        $delegation->setIsPermanent(false);

        $this->delegationMapper->insert($delegation);

        $this->logger->info(
            "Doriath: Delegation created for secret {$secretId} to user {$delegateTo} by {$initiatedBy}"
        );

        return $delegation;
    }//end createDelegation()

    /**
     * Reclaim all temporary delegations for a secret (delete them).
     *
     * @param string $secretId The secret ID
     * @param string $ownerId  The secret owner's Nextcloud user ID
     *
     * @return void
     *
     * @throws DoesNotExistException When the secret does not exist
     */
    public function reclaimDelegation(string $secretId, string $ownerId): void
    {
        $delegations = $this->delegationMapper->findBySecret(secretId: $secretId);

        foreach ($delegations as $delegation) {
            if ($delegation->getIsPermanent() === true) {
                continue;
            }

            $this->delegationMapper->delete($delegation);
        }//end foreach

        $this->logger->info(
            "Doriath: Delegations reclaimed for secret {$secretId} by {$ownerId}"
        );
    }//end reclaimDelegation()

    /**
     * Return all delegations for a given secret.
     *
     * @param string $secretId The secret ID
     *
     * @return SecretDelegation[]
     */
    public function getDelegationsForSecret(string $secretId): array
    {
        return $this->delegationMapper->findBySecret(secretId: $secretId);
    }//end getDelegationsForSecret()

    /**
     * Make all temporary delegations for the given original owner permanent.
     *
     * @param string $originalOwnerId The original owner's Nextcloud user ID
     *
     * @return int The number of delegations updated
     */
    public function makePermanent(string $originalOwnerId): int
    {
        $count = $this->delegationMapper->makePermanentByOriginalOwner(
            originalOwnerId: $originalOwnerId
        );

        $this->logger->info(
            "Doriath: {$count} delegation(s) made permanent for original owner {$originalOwnerId}"
        );

        return $count;
    }//end makePermanent()
}//end class
