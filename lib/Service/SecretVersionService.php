<?php

/**
 * Keepiq Secret Version Service
 *
 * Business logic for secret version history (secret-version-history §2/§3):
 * pre-update snapshots (ciphertext copied verbatim — never decrypted),
 * metadata listing, blob reads gated exactly like head reads, and restore
 * (snapshot the current head first, then set the head to the selected
 * version). restore() changes one row only and refuses a copy its holder
 * may only view; the web app restores through share-save.
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
use InvalidArgumentException;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretVersion;
use OCA\Keepiq\Db\SecretVersionMapper;
use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Exception\StaleWriteException;
use OCP\AppFramework\Db\DoesNotExistException;
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
	 * @param ViewOnlyCopyGuard $viewOnlyCopies Refuses a restore of a copy its holder may not write
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
		private ViewOnlyCopyGuard $viewOnlyCopies,
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
		// `''`, not null, for an empty field: a restore tells "cleared then"
		// apart from a version made before these fields existed.
		$version->setTotp($preUpdate->getTotp() ?? '');
		$version->setPasskeys($preUpdate->getPasskeys() ?? '');
		$version->setEncryptionSuiteId($preUpdate->getEncryptionSuiteId());
		$version->setActorType($actorType);
		$version->setActorId($actorId);
		$version->setCreatedAt(new DateTime());

		return $this->mapper->insert($version);
	}//end snapshot()

	/**
	 * Snapshot a secret's pre-update state when the update is a revision: a
	 * versioned field changes and the row held a value. A placeholder's first
	 * fill is not one: there is no earlier value to return to, so the version
	 * would say "nothing" in the history panel.
	 *
	 * @param Secret $preUpdate The secret row BEFORE the head overwrite
	 * @param Secret $after The row about to be written
	 * @param string $actorType The actor type (`user`|`application`)
	 * @param string $actorId The actor id
	 *
	 * @return SecretVersion|null The version, or null when the update is no revision
	 *
	 * @spec openspec/specs/secret-version-history/spec.md#requirement-prior-encrypted-values-are-retained-on-update
	 * @spec openspec/specs/secrets/spec.md#requirement-unfilled-request-placeholder
	 */
	public function snapshotIfChanged(Secret $preUpdate, Secret $after, string $actorType, string $actorId): ?SecretVersion {
		$hadValues = (string)$preUpdate->getKey() !== ''
			|| (string)$preUpdate->getLogin() !== ''
			|| (string)$preUpdate->getAdditionalFields() !== ''
			|| (string)$preUpdate->getTotp() !== ''
			|| (string)$preUpdate->getPasskeys() !== '';
		if ($hadValues === false || $this->fieldsChanged(before: $preUpdate, after: $after) === false) {
			return null;
		}

		return $this->snapshot(preUpdate: $preUpdate, actorType: $actorType, actorId: $actorId);
	}//end snapshotIfChanged()

	/**
	 * Whether any versioned field differs between two states of a secret row
	 * (secret-version-history §2.2). Pure string comparison: ciphertext blobs
	 * are opaque, nothing is decrypted.
	 *
	 * @param Secret $before The pre-mutation clone
	 * @param Secret $after The mutated row
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/secret-version-history/spec.md#requirement-prior-encrypted-values-are-retained-on-update
	 */
	public function fieldsChanged(Secret $before, Secret $after): bool {
		return $before->getName() !== $after->getName()
			|| $before->getUrl() !== $after->getUrl()
			|| $before->getFolderId() !== $after->getFolderId()
			|| $before->getTypeId() !== $after->getTypeId()
			|| $before->getKey() !== $after->getKey()
			|| $before->getLogin() !== $after->getLogin()
			|| $before->getAdditionalFields() !== $after->getAdditionalFields()
			|| $before->getTotp() !== $after->getTotp()
			|| $before->getPasskeys() !== $after->getPasskeys();
	}//end fieldsChanged()

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
	 * share-save with `restoredFrom`. The head is moved off the version just
	 * read (the base, when one is given) by a compare-and-set inside the
	 * transaction, as a share-save does, so a save that commits after the
	 * read is refused here rather than overwritten unsnapshotted.
	 *
	 * @param string $versionId The version UUID
	 * @param string $userId The caller (must own the secret)
	 * @param string|null $baseUpdatedAt The head version the caller restores over; null skips the comparison with it, not the lock
	 *
	 * @return Secret The updated head
	 *
	 * @throws \InvalidArgumentException On not found / not owned / suite blocked / a malformed base
	 * @throws \OCA\Keepiq\Exception\ForbiddenException When the head is a copy its holder may only view, or a read-only copy from another organisation
	 * @throws \OCA\Keepiq\Exception\NotFoundException When the head is a copy whose source cannot be resolved
	 * @throws StaleWriteException When the head moved on from `$baseUpdatedAt`, or from the version read
	 *
	 * @spec openspec/specs/secret-version-history/spec.md#requirement-list-view-and-restore-versions
	 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-concurrent-server-changes-are-never-overwritten-silently
	 */
	public function restore(string $versionId, string $userId, ?string $baseUpdatedAt = null): Secret {
		$version = $this->getVersion(versionId: $versionId, userId: $userId);
		$secret = $this->reload(id: $version->getSecretId());
		// A read-only copy from another organisation has no share target, so the view-only guard passes it.
		$secret->assertNotReadOnly();
		$this->viewOnlyCopies->assertMayChangeValue(
			row: $secret,
			fields: ViewOnlyCopyGuard::VALUE_FIELDS,
			userId: $userId
		);
		$this->editGuard->assertUnchangedSince(secret: $secret, baseUpdatedAt: $baseUpdatedAt);

		$restored = $this->atomic(
			fn: fn (): Secret => $this->restoreHead(secret: $secret, version: $version, userId: $userId),
			db: $this->db
		);

		// Logged only once the restore has committed.
		$this->logRestored(secret: $restored, version: $version, userId: $userId);

		return $restored;
	}//end restore()

	/**
	 * The restore's transaction body: move the head off the version read,
	 * snapshot it, write the version onto it and audit the restore.
	 *
	 * @param Secret $secret The head as read before the transaction
	 * @param SecretVersion $version The version to restore
	 * @param string $userId The caller
	 *
	 * @return Secret The updated head
	 *
	 * @throws StaleWriteException When the head moved on after it was read
	 * @throws InvalidArgumentException When the head was deleted after it was read
	 */
	private function restoreHead(Secret $secret, SecretVersion $version, string $userId): Secret {
		// Always after the stored version: the column and the stale check
		// count whole seconds, so a restore must never keep the version an
		// edit made before it was based on.
		$stored = $secret->getUpdatedAt();
		$savedAt = new DateTime('@'.max(time(), ($stored?->getTimestamp() ?? 0) + 1));
		if ($stored !== null && $this->secretMapper->advanceVersion(id: $secret->getId(), base: $stored, savedAt: $savedAt) === 0) {
			throw new StaleWriteException(current: $this->reload(id: $secret->getId()));
		}

		// Snapshot the current head so the restore itself is undoable.
		$this->snapshot(preUpdate: $secret, actorType: 'user', actorId: $userId);

		$beforeRestore = clone $secret;
		$secret->setName($version->getName());
		$secret->setUrl($version->getUrl());
		$secret->setKey($version->getKey());
		$secret->setLogin($version->getLogin());
		$secret->setAdditionalFields($version->getAdditionalFields());
		// A version made before these fields existed holds null and the head
		// keeps its values; `''` was empty then and clears them.
		if ($version->getTotp() !== null) {
			$secret->setTotp($version->getTotp());
		}

		if ($version->getPasskeys() !== null) {
			$secret->setPasskeys($version->getPasskeys());
		}

		if ($secret->getTotp() === '') {
			$secret->setTotp(null);
		}

		if ($secret->getPasskeys() === '') {
			$secret->setPasskeys(null);
		}

		// Old ciphertext is no new value: a credential it changes has no
		// change time, so it reads as exposed and moves no rotation proof.
		(new CredentialFields())->stampRestore(after: $secret, before: $beforeRestore);

		$secret->setUpdatedAt($savedAt);
		$this->secretMapper->update($secret);

		$this->recordRestored(secret: $secret, version: $version, userId: $userId);

		return $secret;
	}//end restoreHead()

	/**
	 * The head, read for the base check and again inside the restore's transaction.
	 *
	 * @param string $id The secret ID
	 *
	 * @return Secret
	 *
	 * @throws \InvalidArgumentException When it was deleted meanwhile, as the route answers a missing version
	 */
	private function reload(string $id): Secret {
		try {
			return $this->secretMapper->findById($id);
		} catch (DoesNotExistException) {
			throw new InvalidArgumentException('Secret not found');
		}
	}//end reload()

	/**
	 * Audit that a secret was restored to a version. Called inside the
	 * restore's or the share-save's transaction, so the audit row commits
	 * (or rolls back) with the restore.
	 *
	 * @param Secret $secret The restored head
	 * @param SecretVersion $version The version it was restored to
	 * @param string $userId The caller
	 * @param string|null $via The path that wrote the value, when it is not this service
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-version-history/spec.md#requirement-restores-are-auditable
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-restore-reaches-every-copy-in-the-same-transaction
	 */
	public function recordRestored(Secret $secret, SecretVersion $version, string $userId, ?string $via = null): void {
		$metadata = ['versionNumber' => $version->getVersionNumber()];
		if ($via !== null) {
			$metadata['via'] = $via;
		}

		$this->eventDispatcher?->dispatchTyped(
			$this->auditEvents->forUser(
				actorId: $userId,
				eventType: AuditEventTypes::SECRET_VERSION_RESTORED,
				objectType: 'secret',
				objectId: $secret->getId(),
				objectName: $secret->getName(),
				metadata: $metadata,
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
