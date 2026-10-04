<?php

/**
 * Keepiq Secret Trash Service
 *
 * The trash and the archive of a user's vault (vault-trash-and-archive):
 * a delete moves a secret to the trash and ends everybody else's access at
 * once; the owner restores it or deletes it for good (the daily purge past
 * the retention is PurgeTrashedSecretsJob). Archiving
 * takes a secret out of everyday sight without deleting it.
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
use InvalidArgumentException;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Trash, restore, purge, archive and unarchive a user-owned secret.

 */
class SecretTrashService {
	/**
	 * Default days a trashed secret is kept (design D4).
	 *
	 * @var int
	 */
	public const RETENTION_DEFAULT = 30;

	/**
	 * Smallest retention an administrator may set.
	 *
	 * @var int
	 */
	public const RETENTION_MIN = 1;

	/**
	 * Largest retention an administrator may set.
	 *
	 * @var int
	 */
	public const RETENTION_MAX = 365;

	/**
	 * Builds the audit events this service records.
	 *
	 * @var AuditEventFactory
	 */
	private AuditEventFactory $auditEvents;

	/**
	 * Constructor for SecretTrashService.
	 *
	 * @param SecretMapper $mapper The secret mapper
	 * @param SecretService $secretService The secret service (the full delete cascade)
	 * @param SecretSharingRevoker $sharingRevoker Ends everybody else's access
	 * @param AuditService $auditService The audit recorder
	 * @param LoggerInterface $logger The logger
	 * @param FederatedCopyDeclineService|null $federatedDeclines Declines the share behind a deleted read-only copy
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private SecretMapper $mapper,
		private SecretService $secretService,
		private SecretSharingRevoker $sharingRevoker,
		private AuditService $auditService,
		private LoggerInterface $logger,
		private ?FederatedCopyDeclineService $federatedDeclines = null,
	) {
		$this->auditEvents = new AuditEventFactory();
	}//end __construct()

	/**
	 * Move a secret to the trash and end everybody else's access to it now.
	 *
	 * Link shares, open secret requests, user shares, group shares and
	 * delegations are revoked; the ciphertext, attachments, versions and
	 * rotation flags stay, so a restore gives the secret back whole (D2).
	 * A secret already in the trash is left as it is.
	 *
	 * @param string $id The secret ID
	 * @param string $userId The owner
	 * @param string|null $baseUpdatedAt The version an offline delete was made from
	 *
	 * @return Secret
	 *
	 * @throws \OCA\Keepiq\Exception\StaleWriteException When the secret changed since that version
	 *
	 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-deleting-a-secret-moves-it-to-the-trash
	 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-concurrent-server-changes-are-never-overwritten-silently
	 */
	public function trash(string $id, string $userId, ?string $baseUpdatedAt=null): Secret {
		// An offline delete names the version it was made from; a secret
		// that changed since stays put (offline-edit-queue).
		$secret = $this->secretService->findOwned($id, $userId, $baseUpdatedAt);
		if ($secret->getTrashedAt() !== null) {
			return $secret;
		}

		$this->sharingRevoker->revokeAll(secretId: $id);
		// A read-only copy from another organisation: the share it came
		// from is declined and its owner told (sharing-federated-recipients 4.4).
		$this->federatedDeclines?->copyDeleted(secret: $secret, userId: $userId);

		$secret->setTrashedAt(new DateTime());
		$secret->setArchivedAt(null);
		$this->mapper->update($secret);

		$this->record(userId: $userId, type: AuditEventTypes::SECRET_TRASHED, secret: $secret);

		return $secret;
	}//end trash()

	/**
	 * Take a secret out of the trash. It comes back unshared.
	 *
	 * @param string $id The secret ID
	 * @param string $userId The owner
	 *
	 * @return Secret
	 *
	 * @throws InvalidArgumentException When the secret is not in the trash
	 *
	 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-restoring-and-purging-trashed-secrets
	 */
	public function restore(string $id, string $userId): Secret {
		$secret = $this->loadTrashed(id: $id, userId: $userId);
		$secret->setTrashedAt(null);
		$this->mapper->update($secret);

		$this->record(userId: $userId, type: AuditEventTypes::SECRET_RESTORED, secret: $secret);

		return $secret;
	}//end restore()

	/**
	 * Delete a trashed secret for good, with the full delete cascade.
	 *
	 * @param string $id The secret ID
	 * @param string $userId The owner
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the secret is not in the trash
	 *
	 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-restoring-and-purging-trashed-secrets
	 */
	public function purge(string $id, string $userId): void {
		$secret = $this->loadTrashed(id: $id, userId: $userId);
		// A copy trashed before its share could be declined (task 4.4).
		$this->federatedDeclines?->copyDeleted(secret: $secret, userId: $userId);
		$this->secretService->delete($id, $userId, 'owner');
	}//end purge()

	/**
	 * Archive a secret: it leaves the vault list, search, autofill and the
	 * health report, and keeps its shares (D5).
	 *
	 * @param string $id The secret ID
	 * @param string $userId The owner
	 *
	 * @return Secret
	 *
	 * @throws InvalidArgumentException When the secret is in the trash
	 *
	 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-archiving-a-secret
	 */
	public function archive(string $id, string $userId): Secret {
		$secret = $this->secretService->findOwned($id, $userId);
		if ($secret->getTrashedAt() !== null) {
			throw new InvalidArgumentException('A secret in the trash cannot be archived');
		}

		if ($secret->getArchivedAt() === null) {
			$secret->setArchivedAt(new DateTime());
			$this->mapper->update($secret);
			$this->record(userId: $userId, type: AuditEventTypes::SECRET_ARCHIVED, secret: $secret);
		}

		return $secret;
	}//end archive()

	/**
	 * Bring an archived secret back into the vault.
	 *
	 * @param string $id The secret ID
	 * @param string $userId The owner
	 *
	 * @return Secret
	 *
	 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-archiving-a-secret
	 */
	public function unarchive(string $id, string $userId): Secret {
		$secret = $this->secretService->findOwned($id, $userId);
		if ($secret->getArchivedAt() !== null) {
			$secret->setArchivedAt(null);
			$this->mapper->update($secret);
			$this->record(userId: $userId, type: AuditEventTypes::SECRET_UNARCHIVED, secret: $secret);
		}

		return $secret;
	}//end unarchive()

	/**
	 * Load an owned secret that must be in the trash.
	 *
	 * @param string $id The secret ID
	 * @param string $userId The requester
	 *
	 * @return Secret
	 *
	 * @throws InvalidArgumentException When the secret is not in the trash
	 *
	 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-restoring-and-purging-trashed-secrets
	 */
	private function loadTrashed(string $id, string $userId): Secret {
		$secret = $this->secretService->findOwned($id, $userId);
		if ($secret->getTrashedAt() === null) {
			throw new InvalidArgumentException('The secret is not in the trash');
		}

		return $secret;
	}//end loadTrashed()

	/**
	 * Record a user event about a secret: ids and the item name only.
	 *
	 * @param string $userId The actor
	 * @param string $type The event type
	 * @param Secret $secret The secret
	 *
	 * @return void
	 *
	 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-restoring-and-purging-trashed-secrets
	 */
	private function record(string $userId, string $type, Secret $secret): void {
		try {
			$this->auditService->record(
				$this->auditEvents->forUser(
					actorId: $userId,
					eventType: $type,
					objectType: 'secret',
					objectId: $secret->getId(),
					objectName: $secret->getName(),
				)
			);
		} catch (Throwable $e) {
			// Fail-soft: an audit failure never undoes the change.
			$this->logger->error('Keepiq: audit entry could not be recorded: '.$e->getMessage(), ['exception' => $e]);
		}
	}//end record()
}//end class
