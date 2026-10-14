<?php

/**
 * Keepiq Secret Version Service
 *
 * Business logic for secret version history (secret-version-history §2/§3):
 * pre-update snapshots (ciphertext copied verbatim — never decrypted),
 * metadata listing, blob reads gated exactly like head reads, and restore
 * (snapshot the current head first, then set the head to the selected
 * version). restore() changes one row only; the web app restores through
 * share-save and calls it only for a copy holder without a write grade.
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

use DateTime;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretVersion;
use OCA\Keepiq\Db\SecretVersionMapper;
use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Exception\StaleWriteException;
use OCP\AppFramework\Db\TTransactional;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Business logic for the secret-version lifecycle.
 *
 * Read authorization — ownership and the revoked-suite read gate — lives in
 * SecretVersionAccessGuard so the "may this caller read this version"
 * decision has exactly one home.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A restore joins the version,
 *   the head, the read gate, the stale check and the audit in one step.
 */
class SecretVersionService {
	use TTransactional;

	/**
	 * Constructor for SecretVersionService.
	 *
	 * @param SecretVersionMapper $mapper The version mapper
	 * @param SecretMapper $secretMapper The secret mapper
	 * @param SecretVersionAccessGuard $accessGuard The read-authorization guard
	 * @param LoggerInterface $logger The logger
	 * @param IDBConnection $db The restore's transaction
	 * @param IEventDispatcher|null $eventDispatcher The audit event dispatcher
	 * @param AuditEventFactory $auditEvents The audit-event factory
	 * @param OfflineEditGuard $editGuard Refuses a restore over a head that moved on
	 *
	 * @return void
	 */
	public function __construct(
		private SecretVersionMapper $mapper,
		private SecretMapper $secretMapper,
		private SecretVersionAccessGuard $accessGuard,
		private LoggerInterface $logger,
		private IDBConnection $db,
		private ?IEventDispatcher $eventDispatcher = null,
		private AuditEventFactory $auditEvents = new AuditEventFactory(),
		private OfflineEditGuard $editGuard = new OfflineEditGuard(),
	) {
	}//end __construct()

	/**
	 * Snapshot a secret's PRE-UPDATE state as an immutable version. The
	 * ciphertext fields are copied verbatim — no decryption anywhere.
	 *
	 * @param Secret $preUpdate The secret row BEFORE the head overwrite
	 * @param string $actorType The actor type (`user`|`application`)
	 * @param string $actorId The actor id
	 *
	 * @return SecretVersion
	 *
	 * @spec openspec/changes/secret-version-history/specs/secret-version-history/spec.md
	 */
	public function snapshot(Secret $preUpdate, string $actorType, string $actorId): SecretVersion {
		$version = new SecretVersion();
		$version->setId(Uuid::uuid4()->toString());
		$version->setSecretId($preUpdate->getId());
		$version->setVersionNumber($this->mapper->nextVersionNumber(secretId: $preUpdate->getId()));
		$version->setName($preUpdate->getName());
		$version->setUrl($preUpdate->getUrl());
		$version->setKey($preUpdate->getKey());
		$version->setLogin($preUpdate->getLogin());
		$version->setAdditionalFields($preUpdate->getAdditionalFields());
		$version->setEncryptionSuiteId($preUpdate->getEncryptionSuiteId());
		$version->setActorType($actorType);
		$version->setActorId($actorId);
		$version->setCreatedAt(new DateTime());

		return $this->mapper->insert($version);
	}//end snapshot()

	/**
	 * List a secret's versions (metadata only), newest first — owner-only;
	 * an inaccessible secret yields an empty list (no existence oracle).
	 *
	 * @param string $secretId The secret UUID
	 * @param string $userId The caller
	 *
	 * @return SecretVersion[]
	 *
	 * @spec openspec/changes/secret-version-history/specs/secret-version-history/spec.md
	 */
	public function list(string $secretId, string $userId): array {
		if ($this->accessGuard->isOwned(secretId: $secretId, userId: $userId) === false) {
			return [];
		}

		return $this->mapper->findBySecret(secretId: $secretId);
	}//end list()

	/**
	 * One version WITH its ciphertext blobs for client-side decrypt.
	 * Refused when the version's wrapping suite is revoked/compromised —
	 * matching the head-read posture.
	 *
	 * @param string $versionId The version UUID
	 * @param string $userId The caller
	 *
	 * @return SecretVersion
	 *
	 * @throws \InvalidArgumentException On not found / not owned / suite blocked
	 *
	 * @spec openspec/changes/secret-version-history/specs/secret-version-history/spec.md
	 */
	public function getVersion(string $versionId, string $userId): SecretVersion {
		return $this->accessGuard->requireReadableVersion(versionId: $versionId, userId: $userId);
	}//end getVersion()

	/**
	 * Restore a version on the head row alone, after snapshotting the head.
	 * Copies are not written here: a restore that must reach them is a
	 * share-save with `restoredFrom`. With a base, the head is moved off it
	 * by a compare-and-set inside the transaction, as a share-save does, so a
	 * save that commits after the check is refused here rather than overwritten.
	 *
	 * @param string $versionId The version UUID
	 * @param string $userId The caller (must own the secret)
	 * @param string|null $baseUpdatedAt The head version the caller restores over; null skips the check
	 *
	 * @return Secret The updated head
	 *
	 * @throws \InvalidArgumentException On not found / not owned / suite blocked / a malformed base
	 * @throws StaleWriteException When the head moved on from `$baseUpdatedAt`
	 *
	 * @spec openspec/specs/secret-version-history/spec.md#requirement-list-view-and-restore-versions
	 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-concurrent-server-changes-are-never-overwritten-silently
	 */
	public function restore(string $versionId, string $userId, ?string $baseUpdatedAt = null): Secret {
		$version = $this->getVersion(versionId: $versionId, userId: $userId);
		$secret = $this->secretMapper->findById($version->getSecretId());
		$this->editGuard->assertUnchangedSince(secret: $secret, baseUpdatedAt: $baseUpdatedAt);

		// The check passed, so the stored version is the base the caller named.
		$base = null;
		if ($baseUpdatedAt !== null && $baseUpdatedAt !== '') {
			$base = $secret->getUpdatedAt();
		}

		$restored = $this->atomic(
			fn: fn (): Secret => $this->restoreHead(secret: $secret, version: $version, userId: $userId, base: $base),
			db: $this->db
		);

		// Logged only once the restore has committed.
		$this->logRestored(secret: $restored, version: $version, userId: $userId);

		return $restored;
	}//end restore()

	/**
	 * The restore's transaction body: move the head off its base, snapshot
	 * it, write the version onto it and audit the restore.
	 *
	 * @param Secret $secret The head as read before the transaction
	 * @param SecretVersion $version The version to restore
	 * @param string $userId The caller
	 * @param DateTime|null $base The head version the caller restores over; null skips the check
	 *
	 * @return Secret The updated head
	 *
	 * @throws StaleWriteException When the head moved on from `$base` after it was read
	 */
	private function restoreHead(Secret $secret, SecretVersion $version, string $userId, ?DateTime $base): Secret {
		$savedAt = new DateTime();
		if ($base !== null) {
			// Always after the base: the column and the stale check count whole seconds.
			$savedAt = new DateTime('@'.max(time(), $base->getTimestamp() + 1));
			if ($this->secretMapper->advanceVersion(id: $secret->getId(), base: $base, savedAt: $savedAt) === 0) {
				throw new StaleWriteException(current: $this->secretMapper->findById($secret->getId()));
			}
		}

		// Snapshot the current head so the restore itself is undoable.
		$this->snapshot(preUpdate: $secret, actorType: 'user', actorId: $userId);

		$secret->setName($version->getName());
		$secret->setUrl($version->getUrl());
		$secret->setKey($version->getKey());
		$secret->setLogin($version->getLogin());
		$secret->setAdditionalFields($version->getAdditionalFields());
		$secret->setKeyUpdatedAt(new DateTime());
		$secret->setUpdatedAt($savedAt);
		$this->secretMapper->update($secret);

		$this->recordRestored(secret: $secret, version: $version, userId: $userId);

		return $secret;
	}//end restoreHead()

	/**
	 * Audit that a secret was restored to a version. Called inside the
	 * restore's or the share-save's transaction, so the audit row commits
	 * (or rolls back) with the restore.
	 *
	 * @param Secret $secret The restored head
	 * @param SecretVersion $version The version it was restored to
	 * @param string $userId The caller
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-version-history/spec.md#requirement-restores-are-auditable
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-restore-reaches-every-copy-in-the-same-transaction
	 */
	public function recordRestored(Secret $secret, SecretVersion $version, string $userId): void {
		$this->eventDispatcher?->dispatchTyped(
			$this->auditEvents->forUser(
				actorId: $userId,
				eventType: AuditEventTypes::SECRET_VERSION_RESTORED,
				objectType: 'secret',
				objectId: $secret->getId(),
				objectName: $secret->getName(),
				metadata: ['versionNumber' => $version->getVersionNumber()],
			)
		);
	}//end recordRestored()

	/**
	 * Log that a secret was restored to a version. The log is not rolled
	 * back, so it is called only after the restore's transaction committed.
	 *
	 * @param Secret $secret The restored head
	 * @param SecretVersion $version The version it was restored to
	 * @param string $userId The caller
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-version-history/spec.md#requirement-restores-are-auditable
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-restore-reaches-every-copy-in-the-same-transaction
	 */
	public function logRestored(Secret $secret, SecretVersion $version, string $userId): void {
		$this->logger->info(
			'Keepiq: secret ' . $secret->getId() . ' restored to version '
			. $version->getVersionNumber() . ' by ' . $userId,
			['app' => 'keepiq']
		);
	}//end logRestored()

	/**
	 * Delete every version of a secret (delete cascade; idempotent).
	 *
	 * @param string $secretId The secret UUID
	 *
	 * @return void
	 *
	 * @spec openspec/changes/secret-version-history/specs/secret-version-history/spec.md
	 */
	public function deleteForSecret(string $secretId): void {
		$this->mapper->deleteBySecret(secretId: $secretId);
	}//end deleteForSecret()
}//end class
