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

use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Event\SuiteMigrationCompletedEvent;
use OCA\Keepiq\Kernel\Contracts\NotificationServiceInterface;
use OCA\Keepiq\Kernel\Contracts\RotationPolicyServiceInterface;
use OCA\Keepiq\Kernel\Contracts\ShareRowJudgeInterface;
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
	use MarksCompromisedSecrets;

	/**
	 * Constructor.
	 *
	 * @param SecretMapper $secretMapper The Secret mapper (lookup recipient copies)
	 * @param ShareRowJudgeInterface $rowJudge Judged share rows (ShareRowJudge); every lookup of a share row goes through it
	 * @param NotificationServiceInterface $notificationService The notification dispatcher
	 * @param LoggerInterface $logger The logger
	 * @param RotationPolicyServiceInterface|null $rotationService The rotation service (auto-flag)
	 *
	 * @return void
	 */
	public function __construct(
		private SecretMapper $secretMapper,
		private ShareRowJudgeInterface $rowJudge,
		private NotificationServiceInterface $notificationService,
		private LoggerInterface $logger,
		private ?RotationPolicyServiceInterface $rotationService = null,
	) {
	}//end __construct()

	/**
	 * Handle the SuiteMigrationCompletedEvent.
	 *
	 * @param Event $event The dispatched event
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-encryptionsuite-compromise-shared-copy-migration-and-owner-notification
	 * @spec openspec/specs/rotation-expiry-policies/spec.md#requirement-rotate-after-breach-and-rotate-after-compromise-flagging
	 */
	public function handle(Event $event): void {
		if ($event instanceof SuiteMigrationCompletedEvent === false) {
			return;
		}

		try {
			// Walk every Secret that uses the new suite and is flagged
			// 'possibly compromised'. For shared copies, resolve the
			// ShareTarget back to the source Secret and notify its owner.
			$notices = new CompromiseNotices(event: $event);
			foreach ($this->secretMapper->findByEncryptionSuiteId($event->getNewSuiteId()) as $secret) {
				$this->warnAbout(secret: $secret, notices: $notices);
			}
		} catch (Throwable $exception) {
			$this->logger->warning(
				'Keepiq: SuiteCompromiseListener failed: ' . $exception->getMessage(),
				['app' => 'keepiq']
			);
		}//end try
	}//end handle()

	/**
	 * Flag one possibly-compromised secret and tell the owner of what it
	 * resolves to, once per owner.
	 *
	 * @param Secret            $secret  A secret sealed under the new suite
	 * @param CompromiseNotices $notices The migration and the owners told so far
	 *
	 * @return void
	 */
	private function warnAbout(Secret $secret, CompromiseNotices $notices): void {
		if ($secret->getPossiblyCompromisedAt() === null) {
			return;
		}

		// Auto-raise a rotation flag per compromised secret
		// (rotation-expiry-policies §3.2; idempotent).
		$this->stampAndFlag(secret: $secret);

		$target = $this->resolveTarget(secret: $secret);
		$ownerId = (string)$target->getOwnerId();

		// The SOURCE of a shared copy is not sealed under the new
		// suite, so nothing else in the migration path marks it: stamp
		// and flag it here, as the revoke path does (keepiq#802).
		if ($target !== $secret) {
			$this->stampAndFlag(secret: $target);
		}

		if ($notices->skips(ownerId: $ownerId) === true) {
			return;
		}

		$this->notifyOwner(target: $target, notices: $notices);
		$notices->told(ownerId: $ownerId);
	}//end warnAbout()

	/**
	 * Tell the owner of a compromised secret.
	 *
	 * @param Secret            $target  The secret the owner can open
	 * @param CompromiseNotices $notices The migration and the owners told so far
	 *
	 * @return void
	 */
	private function notifyOwner(Secret $target, CompromiseNotices $notices): void {
		$this->notificationService->notify(
			subject: 'secret_compromised',
			recipientId: (string)$target->getOwnerId(),
			params: [
				'oldSuiteId' => $notices->event->getOldSuiteId(),
				'newSuiteId' => $notices->event->getNewSuiteId(),
				'migrationId' => $notices->event->getMigrationId(),
				'secret_id' => $target->getId(),
				'secret_name' => $target->getName(),
			],
			objectType: 'secret',
			objectId: $target->getId(),
		);
	}//end notifyOwner()
}//end class
