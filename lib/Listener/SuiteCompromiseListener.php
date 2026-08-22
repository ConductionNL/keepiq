<?php

/**
 * Keepiq SuiteCompromiseListener
 *
 * Listens for SuiteMigrationCompletedEvent and notifies the original
 * owner of every secret whose copies were flagged 'possibly compromised'
 * during the migration so the owner can investigate.
 *
 * @category Listener
 * @package  OCA\Keepiq\Listener
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

namespace OCA\Keepiq\Listener;

use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Event\SuiteMigrationCompletedEvent;
use OCA\Keepiq\Service\NotificationService;
use OCA\Keepiq\Service\RotationPolicyService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Notify owners after a compromise migration completes.
 *
 * @implements IEventListener<SuiteMigrationCompletedEvent>
 *
 * @spec openspec/changes/implement-user-sharing/tasks.md#8.4
 */
class SuiteCompromiseListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param SecretMapper $secretMapper The Secret mapper (lookup recipient copies)
	 * @param ShareTargetMapper $shareTargetMapper The share-target mapper (resolve owners)
	 * @param NotificationService $notificationService The notification dispatcher
	 * @param LoggerInterface $logger The logger
	 * @param RotationPolicyService|null $rotationService The rotation service (auto-flag)
	 *
	 * @return void
	 */
	public function __construct(
		private SecretMapper $secretMapper,
		private ShareTargetMapper $shareTargetMapper,
		private NotificationService $notificationService,
		private LoggerInterface $logger,
		private ?RotationPolicyService $rotationService = null,
	) {
	}//end __construct()

	/**
	 * Handle the SuiteMigrationCompletedEvent.
	 *
	 * @param Event $event The dispatched event
	 *
	 * @return void
	 */
	public function handle(Event $event): void {
		if ($event instanceof SuiteMigrationCompletedEvent === false) {
			return;
		}

		try {
			$notified = [];
			// Walk every Secret that uses the new suite and is flagged
			// 'possibly compromised'. For shared copies, resolve the
			// ShareTarget back to the source Secret and notify its owner.
			$secrets = $this->secretMapper->findByEncryptionSuiteId($event->getNewSuiteId());
			foreach ($secrets as $secret) {
				if ($secret->getPossiblyCompromisedAt() === null) {
					continue;
				}

				// Auto-raise a rotation flag per compromised secret
				// (rotation-expiry-policies §3.2; idempotent).
				$this->rotationService?->flag(
					secretId: $secret->getId(),
					reason: 'suite_compromise'
				);

				$ownerId = $this->resolveSourceOwner(
					recipientSecretId: $secret->getId(),
					fallbackOwnerId: $secret->getOwnerId()
				);

				if ($ownerId === '' || isset($notified[$ownerId]) === true) {
					continue;
				}

				$this->notificationService->notify(
					subject: 'secret_compromised',
					recipientId: $ownerId,
					params: [
						'oldSuiteId' => $event->getOldSuiteId(),
						'newSuiteId' => $event->getNewSuiteId(),
						'migrationId' => $event->getMigrationId(),
						'secretId' => $secret->getId(),
						'secretName' => $secret->getName(),
					],
					objectType: 'secret',
					objectId: $secret->getId(),
				);
				$notified[$ownerId] = true;
			}//end foreach
		} catch (Throwable $exception) {
			$this->logger->warning(
				'Keepiq: SuiteCompromiseListener failed: ' . $exception->getMessage(),
				['app' => 'keepiq']
			);
		}//end try
	}//end handle()

	/**
	 * Resolve a recipient Secret copy back to its source owner via the
	 * ShareTarget mapper. If the copy is not part of any share (a direct
	 * owner copy), fall back to the copy's own owner.
	 *
	 * @param string $recipientSecretId The recipient Secret ID
	 * @param string $fallbackOwnerId The fallback owner
	 *
	 * @return string
	 */
	private function resolveSourceOwner(
		string $recipientSecretId,
		string $fallbackOwnerId,
	): string {
		try {
			$row = $this->shareTargetMapper->findByRecipientSecret(
				recipientSecretId: $recipientSecretId
			);
			try {
				$source = $this->secretMapper->findById($row->getSourceSecretId());
				return $source->getOwnerId();
			} catch (DoesNotExistException) {
				return $fallbackOwnerId;
			}
		} catch (DoesNotExistException) {
			// Not a shared copy — fall back to the secret's own owner.
			return $fallbackOwnerId;
		} catch (Throwable) {
			return $fallbackOwnerId;
		}
	}//end resolveSourceOwner()
}//end class
