<?php

/**
 * Doriath Share Request Service
 *
 * Recipient-initiated request flow — a user already holding a share of a
 * secret asks the owner to share it with a third party. The request is
 * carried as a notification on the owner; if the owner approves the
 * notification, the standard share flow is invoked.
 *
 * This service intentionally does not persist a database row — the
 * notification body holds enough state (sourceSecretId + targetUserId +
 * requesterId) to fan back through the share creation path on approval,
 * keeping the surface narrow and avoiding a second write path.
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

use InvalidArgumentException;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\ShareTargetMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;

/**
 * Business logic for the share-request lifecycle.
 */
class ShareRequestService
{
    /**
     * Constructor for ShareRequestService.
     *
     * @param ShareTargetMapper   $shareTargetMapper   The share-target mapper (recipient check)
     * @param SecretMapper        $secretMapper        The Secret mapper (owner lookup)
     * @param NotificationService $notificationService The notification dispatcher
     * @param LoggerInterface     $logger              The logger
     *
     * @return void
     */
    public function __construct(
        private ShareTargetMapper $shareTargetMapper,
        private SecretMapper $secretMapper,
        private NotificationService $notificationService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Submit a share request — fires a 'share_request' notification at
     * the secret's owner; the notification payload carries the data the
     * approval path will need to fan back into createShare.
     *
     * @param string $sourceSecretId The owner's source Secret ID
     * @param string $targetUserId   The user the requester wants the secret shared with
     * @param string $requesterId    The user submitting the request (must hold a share)
     *
     * @return void
     *
     * @throws InvalidArgumentException When the requester does not hold a share or the secret is missing
     *
     * @spec openspec/changes/implement-user-sharing/tasks.md#5.1
     */
    public function submitShareRequest(
        string $sourceSecretId,
        string $targetUserId,
        string $requesterId,
    ): void {
        if ($targetUserId === '') {
            throw new InvalidArgumentException(message: 'targetUserId is required');
        }

        if ($targetUserId === $requesterId) {
            throw new InvalidArgumentException(message: 'Cannot request a share to yourself');
        }

        $secret = $this->loadSecret(secretId: $sourceSecretId);

        // Requester must already hold a share of the secret OR be the
        // recipient themselves. The owner cannot submit a request (they
        // share directly).
        if ($secret->getOwnerId() === $requesterId) {
            throw new InvalidArgumentException(
                message: 'Owners share directly — no request needed'
            );
        }

        try {
            $this->shareTargetMapper->findBySourceSecretAndTargetUser(
                sourceSecretId: $sourceSecretId,
                targetUserId: $requesterId
            );
        } catch (DoesNotExistException) {
            throw new InvalidArgumentException(
                message: 'Only existing share recipients can request additional shares'
            );
        }

        if ($targetUserId === $secret->getOwnerId()) {
            throw new InvalidArgumentException(
                message: 'Cannot request to share with the secret owner'
            );
        }

        $this->notificationService->notify(
            subject: 'share_request',
            recipientId: $secret->getOwnerId(),
            params: [
                'sourceSecretId' => $sourceSecretId,
                'secretName'     => $secret->getName(),
                'requesterId'    => $requesterId,
                'targetUserId'   => $targetUserId,
            ],
            objectType: 'secret',
            objectId: $sourceSecretId,
        );

        $this->logger->info(
            'Share-request submitted by '.$requesterId
            .' for '.$sourceSecretId.' -> '.$targetUserId,
            ['app' => 'doriath']
        );
    }//end submitShareRequest()

    /**
     * Approve a share request — returns the parameters the calling
     * controller hands to ShareService::createShare. The actual share
     * creation is the controller's responsibility because the browser
     * has to produce the recipient's RSA-encrypted Secret copy first.
     *
     * @param array<string,mixed> $params  The notification parameters {sourceSecretId, requesterId, targetUserId}
     * @param string              $ownerId The approver (must be the secret owner)
     *
     * @return array{sourceSecretId:string,requesterId:string,targetUserId:string}
     *
     * @throws InvalidArgumentException When the approver is not the owner
     *
     * @spec openspec/changes/implement-user-sharing/tasks.md#5.2
     */
    public function approveShareRequest(array $params, string $ownerId): array
    {
        $sourceSecretId = (string) ($params['sourceSecretId'] ?? '');
        $targetUserId   = (string) ($params['targetUserId'] ?? '');
        $requesterId    = (string) ($params['requesterId'] ?? '');

        if ($sourceSecretId === '' || $targetUserId === '' || $requesterId === '') {
            throw new InvalidArgumentException(message: 'Malformed share-request payload');
        }

        $secret = $this->loadSecret(secretId: $sourceSecretId);
        if ($secret->getOwnerId() !== $ownerId) {
            throw new InvalidArgumentException(
                message: 'Not authorized to approve share requests for this secret'
            );
        }

        return [
            'sourceSecretId' => $sourceSecretId,
            'requesterId'    => $requesterId,
            'targetUserId'   => $targetUserId,
        ];
    }//end approveShareRequest()

    /**
     * Deny a share request — fires a 'share_request_result' notification
     * at the requester so they know the answer.
     *
     * @param array<string,mixed> $params  The original notification parameters
     * @param string              $ownerId The denier (must be the secret owner)
     *
     * @return void
     *
     * @throws InvalidArgumentException When the denier is not the owner
     *
     * @spec openspec/changes/implement-user-sharing/tasks.md#5.3
     */
    public function denyShareRequest(array $params, string $ownerId): void
    {
        $sourceSecretId = (string) ($params['sourceSecretId'] ?? '');
        $requesterId    = (string) ($params['requesterId'] ?? '');
        $targetUserId   = (string) ($params['targetUserId'] ?? '');

        if ($sourceSecretId === '' || $requesterId === '') {
            throw new InvalidArgumentException(message: 'Malformed share-request payload');
        }

        $secret = $this->loadSecret(secretId: $sourceSecretId);
        if ($secret->getOwnerId() !== $ownerId) {
            throw new InvalidArgumentException(
                message: 'Not authorized to deny share requests for this secret'
            );
        }

        $this->notificationService->notify(
            subject: 'share_request_result',
            recipientId: $requesterId,
            params: [
                'sourceSecretId' => $sourceSecretId,
                'secretName'     => $secret->getName(),
                'targetUserId'   => $targetUserId,
                'result'         => 'denied',
            ],
            objectType: 'secret',
            objectId: $sourceSecretId,
        );
    }//end denyShareRequest()

    /**
     * Load a Secret by ID — surfaces misses as InvalidArgumentException.
     *
     * @param string $secretId The Secret ID
     *
     * @return Secret
     *
     * @throws InvalidArgumentException
     */
    private function loadSecret(string $secretId): Secret
    {
        try {
            return $this->secretMapper->findById($secretId);
        } catch (DoesNotExistException) {
            throw new InvalidArgumentException(message: 'Secret not found');
        }
    }//end loadSecret()
}//end class
