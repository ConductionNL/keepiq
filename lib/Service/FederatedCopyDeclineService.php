<?php

/**
 * Keepiq Federated Copy Decline Service
 *
 * The recipient's side of deleting a read-only copy from another
 * organisation (sharing-federated-recipients task 4.4, decision of 4 Oct
 * 2026). Moving the copy to the trash, or purging it, declines the share it
 * came from: the inbound row is marked declined and the owner's instance
 * gets an OCM `SHARE_DECLINED`, after which the owner's share shows
 * "declined" and no more changes are sent.
 *
 * The notification carries the share's shared secret itself: the owner's
 * instance keeps only its SHA-256 and checks the secret against it, and
 * Nextcloud verifies the signature against the recipient that secret
 * belongs to (ISignedCloudFederationProvider).
 *
 * The decline is sent once. When it did not arrive, the owner's next
 * `SHARE_UPDATED` for the declined share sends it again
 * (FederatedRemoteChangeService), so a lost decline heals at the owner's
 * next change without a retry queue here.
 *
 * Restoring the copy from the trash takes the share back (decision of
 * 4 Oct 2026): the owner's instance gets the standard OCM `SHARE_ACCEPTED`,
 * signed and with the shared secret like the decline, and when it takes it
 * the inbound share is accepted again and the copy pulls the current value.
 * When the owner revoked or replaced the share meanwhile, the copy comes
 * back read-only as it was and the restore says the share has ended. The
 * trash keeps the inbound share's link to the copy for this; purging the
 * copy drops it.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Service;

use DateTime;
use OCA\Keepiq\Db\FederatedInbound;
use OCA\Keepiq\Db\FederatedInboundMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCP\Federation\ICloudFederationFactory;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\Federation\ICloudIdManager;
use OCP\Security\ICrypto;
use Throwable;

/**
 * Declines the share behind a deleted read-only copy, and takes it back when
 * the copy is restored.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Declining and taking back
 *   one share joins the inbound row, the stored shared secret, the owner's
 *   cloud id, the signed OCM notification, the copy's pull and the audit;
 *   both directions share the one notification path.
 *
 * @spec openspec/specs/federated-sharing/spec.md#scenario-bob-deletes-his-copy
 * @spec openspec/specs/federated-sharing/spec.md#scenario-bob-restores-his-copy
 */
class FederatedCopyDeclineService {
	/**
	 * The OCM notification that the recipient declined a share.
	 *
	 * @var string
	 */
	public const SHARE_DECLINED = 'SHARE_DECLINED';

	/**
	 * The OCM notification that the recipient accepted a share, here: took
	 * a declined one back by restoring the copy.
	 *
	 * @var string
	 */
	public const SHARE_ACCEPTED = 'SHARE_ACCEPTED';

	/**
	 * A restored copy follows the owner again.
	 *
	 * @var string
	 */
	public const RESTORE_RESUMED = 'resumed';

	/**
	 * A restored copy whose share the owner revoked or replaced: it stays
	 * read-only and is no longer updated.
	 *
	 * @var string
	 */
	public const RESTORE_ENDED = 'ended';

	/**
	 * A restored copy whose owner's instance could not be reached: the share
	 * stays declined.
	 *
	 * @var string
	 */
	public const RESTORE_UNREACHABLE = 'unreachable';

	/**
	 * Constructor for FederatedCopyDeclineService.
	 *
	 * @param FederatedInboundMapper $inboundMapper Inbound share rows
	 * @param ICrypto $crypto Opens the stored shared secret
	 * @param ICloudFederationProviderManager $providerManager OCM delivery
	 * @param ICloudFederationFactory $factory OCM notification objects
	 * @param ICloudIdManager $cloudIdManager The owner's instance
	 * @param FederatedShareAuditTrail $audit Identifier-only audit
	 * @param FederatedCopyService|null $copies Pulls the current value into a restored copy
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private FederatedInboundMapper $inboundMapper,
		private ICrypto $crypto,
		private ICloudFederationProviderManager $providerManager,
		private ICloudFederationFactory $factory,
		private ICloudIdManager $cloudIdManager,
		private FederatedShareAuditTrail $audit,
		private ?FederatedCopyService $copies = null,
	) {
	}//end __construct()

	/**
	 * The user moved a secret to the trash or purged it. When it is their
	 * read-only copy of an accepted federated share, decline that share and
	 * tell the owner's instance. Any other secret is left alone.
	 *
	 * @param Secret $secret The secret being deleted
	 * @param string $userId The user deleting it
	 *
	 * @return void
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-bob-deletes-his-copy
	 */
	public function copyDeleted(Secret $secret, string $userId): void {
		if ($secret->getReadOnly() !== true) {
			return;
		}

		foreach ($this->inboundMapper->findBySecretId(secretId: $secret->getId()) as $row) {
			if ($row->getRecipientUid() !== $userId || $row->getStatus() !== FederatedInbound::STATUS_ACCEPTED) {
				continue;
			}

			// The link to the copy stays while the copy is in the trash, so
			// a restore can take the share back; purging drops it.
			$row->setStatus(FederatedInbound::STATUS_DECLINED);
			$row->setUpdatedAt(new DateTime());
			$this->inboundMapper->update(entity: $row);
			$this->audit->recordInbound(eventType: AuditEventTypes::FEDERATED_SHARE_DECLINED, row: $row, actorId: $userId);

			$this->tellOwner(row: $row);
		}
	}//end copyDeleted()

	/**
	 * The user purged a secret for good. Decline the share behind it when
	 * that did not happen at the trash, then drop the link to the copy.
	 *
	 * @param Secret $secret The secret being purged
	 * @param string $userId The user purging it
	 *
	 * @return void
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-bob-deletes-his-copy
	 */
	public function copyPurged(Secret $secret, string $userId): void {
		$this->copyDeleted(secret: $secret, userId: $userId);
		if ($secret->getReadOnly() !== true) {
			return;
		}

		foreach ($this->inboundMapper->findBySecretId(secretId: $secret->getId()) as $row) {
			if ($row->getRecipientUid() !== $userId) {
				continue;
			}

			$row->setSecretId(null);
			$row->setUpdatedAt(new DateTime());
			$this->inboundMapper->update(entity: $row);
		}
	}//end copyPurged()

	/**
	 * The user restored a secret from the trash. When it is their read-only
	 * copy of a share they declined by trashing it, take the share back:
	 * tell the owner's instance, accept the inbound share again and pull the
	 * current value. Returns what became of the share, or null for any other
	 * secret.
	 *
	 * @param Secret $secret The restored secret
	 * @param string $userId The user restoring it
	 *
	 * @return string|null One of the RESTORE_ constants, or null
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-bob-restores-his-copy
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-the-owner-revoked-the-share-meanwhile
	 */
	public function copyRestored(Secret $secret, string $userId): ?string {
		if ($secret->getReadOnly() !== true || $secret->getFederatedSource() === null) {
			return null;
		}

		foreach ($this->inboundMapper->findBySecretId(secretId: $secret->getId()) as $row) {
			if ($row->getRecipientUid() !== $userId) {
				continue;
			}

			if ($row->getStatus() === FederatedInbound::STATUS_DECLINED) {
				return $this->takeBack(row: $row, userId: $userId);
			}

			if ($row->getStatus() === FederatedInbound::STATUS_ACCEPTED) {
				return self::RESTORE_RESUMED;
			}
		}

		// Revoked, or a copy whose link a revocation or an older decline
		// dropped: nothing to take back.
		return self::RESTORE_ENDED;
	}//end copyRestored()

	/**
	 * Ask the owner's instance to take a declined share back, and follow
	 * its answer.
	 *
	 * @param FederatedInbound $row The declined share
	 * @param string $userId The recipient
	 *
	 * @return string One of the RESTORE_ constants
	 */
	private function takeBack(FederatedInbound $row, string $userId): string {
		$status = $this->notifyOwner(row: $row, type: self::SHARE_ACCEPTED, message: 'The recipient restored their copy');
		if ($status === null || $status >= 500) {
			return self::RESTORE_UNREACHABLE;
		}

		$row->setUpdatedAt(new DateTime());
		if ($status !== 201) {
			// The owner's instance does not know the share any more, or
			// refuses it: the share has ended. The copy keeps its last value.
			$row->setStatus(FederatedInbound::STATUS_REVOKED);
			$this->inboundMapper->update(entity: $row);
			return self::RESTORE_ENDED;
		}

		$row->setStatus(FederatedInbound::STATUS_ACCEPTED);
		$row = $this->inboundMapper->update(entity: $row);
		$this->audit->recordInbound(eventType: AuditEventTypes::FEDERATED_SHARE_ACCEPTED, row: $row, actorId: $userId);

		try {
			$this->copies?->refresh(row: $row);
		} catch (Throwable) {
			// The share is live again; the owner's next change pulls anyway.
		}

		return self::RESTORE_RESUMED;
	}//end takeBack()

	/**
	 * Send `SHARE_DECLINED` for a declined share to the owner's instance.
	 * Returns whether it took the notification.
	 *
	 * @param FederatedInbound $row The declined share
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-bob-deletes-his-copy
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-bob-declines-a-pending-share
	 */
	public function tellOwner(FederatedInbound $row): bool {
		return $this->notifyOwner(row: $row, type: self::SHARE_DECLINED, message: 'The recipient removed their copy') === 201;
	}//end tellOwner()

	/**
	 * Send one signed notification about a share to the owner's instance,
	 * with the shared secret, and return the HTTP status it answered, or
	 * null when it could not be reached.
	 *
	 * @param FederatedInbound $row The share
	 * @param string $type SHARE_DECLINED or SHARE_ACCEPTED
	 * @param string $message A short human-readable note
	 *
	 * @return int|null
	 */
	private function notifyOwner(FederatedInbound $row, string $type, string $message): ?int {
		try {
			$sharedSecret = $this->crypto->decrypt($row->getSharedSecretEnc());
			$remote = $this->cloudIdManager->resolveCloudId($row->getSenderCloudId())->getRemote();
		} catch (Throwable) {
			return null;
		}

		$notification = $this->factory->getCloudFederationNotification();
		$notification->setMessage(
			$type,
			FederatedShareService::RESOURCE_TYPE,
			$row->getRemoteShareId(),
			[
				'sharedSecret' => $sharedSecret,
				// The share id lets the owner's Nextcloud find whose
				// signature this is (ISignedCloudFederationProvider).
				'providerId' => $row->getRemoteShareId(),
				'message' => $message,
			]
		);

		try {
			return $this->providerManager->sendCloudNotification($remote, $notification)->getStatusCode();
		} catch (Throwable) {
			return null;
		}
	}//end notifyOwner()
}//end class
