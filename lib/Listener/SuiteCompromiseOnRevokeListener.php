<?php

/**
 * Keepiq SuiteCompromiseOnRevokeListener
 *
 * Listens for EncryptionSuiteRevokedEvent and — only when the revocation was
 * flagged as a compromise (administrator force-revoke, ADR-005) — stamps every
 * secret sealed under the revoked suite as possibly compromised, raises a
 * suite_compromise rotation flag per secret and notifies the affected owners.
 * A sibling of SuiteCompromiseListener, but driven by the revoke event rather
 * than a completed migration: there is no migration here, and the blast radius
 * is the revoked suite itself.
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

use DateTime;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Event\EncryptionSuiteRevokedEvent;
use OCA\Keepiq\Service\NotificationService;
use OCA\Keepiq\Service\RotationPolicyService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Run the compromise cascade over a revoked suite's blast radius.
 *
 * @implements IEventListener<EncryptionSuiteRevokedEvent>
 *
 * @spec openspec/changes/admin-suite-revocation/specs/encryption-suites/spec.md#requirement-administrator-force-revocation
 */
class SuiteCompromiseOnRevokeListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param SecretMapper $secretMapper The Secret mapper (blast-radius lookup + stamp)
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
	 * Handle the EncryptionSuiteRevokedEvent.
	 *
	 * Only reacts when the revocation was flagged as a compromise; the owner
	 * path leaves the flag false and this listener is a no-op there — the whole
	 * cascade is gated on the administrator's explicit decision (ADR-005 D2).
	 *
	 * @param Event $event The dispatched event
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-suite-revocation/specs/encryption-suites/spec.md#requirement-administrator-force-revocation
	 */
	public function handle(Event $event): void {
		if ($event instanceof EncryptionSuiteRevokedEvent === false) {
			return;
		}

		if ($event->getCompromised() === false) {
			// Not a compromise revocation (the owner path, or an administrator
			// who left markCompromised off) — no cascade runs.
			return;
		}

		try {
			$notified = [];
			// Every Secret sealed under the revoked suite is in the blast
			// radius. Unlike the migration path, nothing has stamped
			// possibly_compromised_at yet, so this listener stamps it here.
			$secrets = $this->secretMapper->findByEncryptionSuiteId($event->getSuiteId());
			foreach ($secrets as $secret) {
				if ($secret->getPossiblyCompromisedAt() === null) {
					$secret->setPossiblyCompromisedAt(new DateTime());
					$this->secretMapper->update($secret);
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
						'suiteId' => $event->getSuiteId(),
						'revokedBy' => $event->getRevokedBy(),
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
				'Keepiq: SuiteCompromiseOnRevokeListener failed: ' . $exception->getMessage(),
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
