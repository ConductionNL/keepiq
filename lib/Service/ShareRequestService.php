<?php

/**
 * Keepiq Share Request Service
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
 * @package  OCA\Keepiq\Service
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

namespace OCA\Keepiq\Service;

use InvalidArgumentException;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;

/**
 * Business logic for the share-request lifecycle.
 */
class ShareRequestService {
	/**
	 * Constructor for ShareRequestService.
	 *
	 * @param ShareTargetMapper $shareTargetMapper The share-target mapper (recipient check)
	 * @param SecretMapper $secretMapper The Secret mapper (owner lookup)
	 * @param NotificationService $notificationService The notification dispatcher
	 * @param LoggerInterface $logger The logger
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
	 * approval path will need to register the share (register-batch, which
	 * mints the recipient copy server-side).
	 *
	 * @param string $sourceSecretId The owner's source Secret ID
	 * @param string $targetUserId The user the requester wants the secret shared with
	 * @param string $requesterId The user submitting the request (must hold a share)
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
		$this->assertRequestableTarget(targetUserId: $targetUserId, requesterId: $requesterId);
		$secret = $this->loadSecret(secretId: $sourceSecretId);

		// Requester must already hold a share of the secret OR be the
		// recipient themselves. The owner cannot submit a request (they
		// share directly).
		if ($secret->getOwnerId() === $requesterId) {
			throw new InvalidArgumentException(
				message: 'Owners share directly — no request needed'
			);
		}

		$this->assertHoldsShare(sourceSecretId: $sourceSecretId, requesterId: $requesterId);
		if ($targetUserId === $secret->getOwnerId()) {
			throw new InvalidArgumentException(
				message: 'Cannot request to share with the secret owner'
			);
		}

		$this->notifyOwnerOfRequest(
			secret: $secret,
			request: ['sourceSecretId' => $sourceSecretId, 'requesterId' => $requesterId, 'targetUserId' => $targetUserId]
		);
	}//end submitShareRequest()

	/**
	 * Approve a share request. The browser produces the recipient's
	 * RSA-encrypted copy and registers it first, then calls this to confirm:
	 * once the share exists the requester is told it was approved.
	 *
	 * @param array<string,mixed> $params The notification parameters {sourceSecretId, requesterId, targetUserId}
	 * @param string $ownerId The approver (must be the secret owner)
	 *
	 * @return array{sourceSecretId:string,requesterId:string,targetUserId:string,shared:bool}
	 *
	 * @throws InvalidArgumentException When the approver is not the owner
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#5.2
	 * @spec openspec/specs/user-sharing/spec.md#requirement-share-request-recipient-initiated
	 */
	public function approveShareRequest(array $params, string $ownerId): array {
		[$sourceSecretId, $targetUserId, $requesterId] = $this->requestParts(params: $params);
		if ($sourceSecretId === '' || $targetUserId === '' || $requesterId === '') {
			throw new InvalidArgumentException(message: 'Malformed share-request payload');
		}

		$secret = $this->loadSecret(secretId: $sourceSecretId);
		if ($secret->getOwnerId() !== $ownerId) {
			throw new InvalidArgumentException(
				message: 'Not authorized to approve share requests for this secret'
			);
		}

		// The browser encrypts and registers the copy first, then confirms
		// here. The requester hears "approved" only once the share exists, so
		// a fan-out that failed half way never reads as approved.
		$shared = $this->isShared(sourceSecretId: $sourceSecretId, targetUserId: $targetUserId);
		if ($shared === true) {
			$this->notifyRequester(
				secret: $secret,
				result: ['sourceSecretId' => $sourceSecretId, 'requesterId' => $requesterId, 'targetUserId' => $targetUserId, 'result' => 'approved']
			);
		}

		return [
			'sourceSecretId' => $sourceSecretId,
			'requesterId' => $requesterId,
			'targetUserId' => $targetUserId,
			'shared' => $shared,
		];
	}//end approveShareRequest()

	/**
	 * Deny a share request — fires a 'share_request_result' notification
	 * at the requester so they know the answer.
	 *
	 * @param array<string,mixed> $params The original notification parameters
	 * @param string $ownerId The denier (must be the secret owner)
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the denier is not the owner
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#5.3
	 */
	public function denyShareRequest(array $params, string $ownerId): void {
		[$sourceSecretId, $targetUserId, $requesterId] = $this->requestParts(params: $params);
		if ($sourceSecretId === '' || $requesterId === '') {
			throw new InvalidArgumentException(message: 'Malformed share-request payload');
		}

		$secret = $this->loadSecret(secretId: $sourceSecretId);
		if ($secret->getOwnerId() !== $ownerId) {
			throw new InvalidArgumentException(
				message: 'Not authorized to deny share requests for this secret'
			);
		}

		$this->notifyRequester(
			secret: $secret,
			result: ['sourceSecretId' => $sourceSecretId, 'requesterId' => $requesterId, 'targetUserId' => $targetUserId, 'result' => 'denied']
		);
	}//end denyShareRequest()

	/**
	 * Refuse a request without a target, or for the requester themselves.
	 *
	 * @param string $targetUserId The user the requester wants the secret shared with
	 * @param string $requesterId  The user submitting the request
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the target is missing or the requester
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#5.1
	 */
	private function assertRequestableTarget(string $targetUserId, string $requesterId): void {
		if ($targetUserId === '') {
			throw new InvalidArgumentException(message: 'targetUserId is required');
		}

		if ($targetUserId === $requesterId) {
			throw new InvalidArgumentException(message: 'Cannot request a share to yourself');
		}
	}//end assertRequestableTarget()

	/**
	 * Refuse a requester who holds no share of the secret.
	 *
	 * @param string $sourceSecretId The owner's source Secret ID
	 * @param string $requesterId    The user submitting the request
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the requester is no share recipient
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#5.1
	 */
	private function assertHoldsShare(string $sourceSecretId, string $requesterId): void {
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
	}//end assertHoldsShare()

	/**
	 * Tell the owner about a share request, and log it.
	 *
	 * @param Secret                $secret  The owner's source Secret
	 * @param array<string, string> $request sourceSecretId, requesterId and targetUserId
	 *
	 * @return void
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#5.1
	 */
	private function notifyOwnerOfRequest(Secret $secret, array $request): void {
		$this->notificationService->notify(
			subject: 'share_request',
			recipientId: $secret->getOwnerId(),
			params: [
				'sourceSecretId' => $request['sourceSecretId'],
				'secretName' => $secret->getName(),
				'requesterId' => $request['requesterId'],
				'targetUserId' => $request['targetUserId'],
			],
			objectType: 'secret',
			objectId: $request['sourceSecretId'],
		);

		$this->logger->info(
			'Share-request submitted by ' . $request['requesterId']
			. ' for ' . $request['sourceSecretId'] . ' -> ' . $request['targetUserId'],
			['app' => 'keepiq']
		);
	}//end notifyOwnerOfRequest()

	/**
	 * The source secret, target and requester a request's notification names,
	 * each '' when absent.
	 *
	 * @param array<string,mixed> $params The notification parameters
	 *
	 * @return array{0:string,1:string,2:string}
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-share-request-recipient-initiated
	 */
	private function requestParts(array $params): array {
		return [
			$this->requestPart(params: $params, key: 'sourceSecretId'),
			$this->requestPart(params: $params, key: 'targetUserId'),
			$this->requestPart(params: $params, key: 'requesterId'),
		];
	}//end requestParts()

	/**
	 * One part of a request's notification, '' when absent.
	 *
	 * @param array<string,mixed> $params The notification parameters
	 * @param string              $key    The part
	 *
	 * @return string
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-share-request-recipient-initiated
	 */
	private function requestPart(array $params, string $key): string {
		return (string)($params[$key] ?? '');
	}//end requestPart()

	/**
	 * Whether the target already holds a share of the secret.
	 *
	 * @param string $sourceSecretId The owner's source Secret ID
	 * @param string $targetUserId   The user the share is for
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-share-request-recipient-initiated
	 */
	private function isShared(string $sourceSecretId, string $targetUserId): bool {
		try {
			$this->shareTargetMapper->findBySourceSecretAndTargetUser(
				sourceSecretId: $sourceSecretId,
				targetUserId: $targetUserId
			);
		} catch (DoesNotExistException) {
			return false;
		}

		return true;
	}//end isShared()

	/**
	 * Tell the requester how the owner answered their request.
	 *
	 * @param Secret                $secret The owner's source Secret
	 * @param array<string, string> $result sourceSecretId, requesterId, targetUserId and the result
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-share-request-recipient-initiated
	 */
	private function notifyRequester(Secret $secret, array $result): void {
		$this->notificationService->notify(
			subject: 'share_request_result',
			recipientId: $result['requesterId'],
			params: [
				'sourceSecretId' => $result['sourceSecretId'],
				'secretName' => $secret->getName(),
				'targetUserId' => $result['targetUserId'],
				'result' => $result['result'],
			],
			objectType: 'secret',
			objectId: $result['sourceSecretId'],
		);
	}//end notifyRequester()

	/**
	 * Load a Secret by ID — surfaces misses as InvalidArgumentException.
	 *
	 * @param string $secretId The Secret ID
	 *
	 * @return Secret
	 *
	 * @throws InvalidArgumentException
	 */
	private function loadSecret(string $secretId): Secret {
		try {
			return $this->secretMapper->findById($secretId);
		} catch (DoesNotExistException) {
			throw new InvalidArgumentException(message: 'Secret not found');
		}
	}//end loadSecret()
}//end class
