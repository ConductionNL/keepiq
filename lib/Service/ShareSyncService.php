<?php

/**
 * Keepiq Share Sync Service
 *
 * The multi-recipient re-encryption fan-out of the share lifecycle
 * (implement-user-sharing §3.4, folder-permission-grades §2.3): who may
 * write a source for every copy, the optimistic-lock check, the membership
 * guard that confines a sync to the source row and its ACTUAL recipient
 * copies, and the transactional blob write itself.
 *
 * Extracted from ShareService — the fan-out is a distinct transaction
 * with its own authorization seam (a write-grade team member may sync a
 * secret they do not own) and its own failure mode.
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
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Service\ShareSync\ShareSyncGuards;
use OCA\Keepiq\Service\ShareSync\ShareWriteContext;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\TTransactional;
use OCP\IDBConnection;

/**
 * Pushes an updated encrypted blob to every recipient of a secret.
 */
class ShareSyncService {
	use TTransactional;

	/**
	 * How the audit names a write on a team-folder grade.
	 */
	public const TEAM_WRITE = 'team-write sync';

	/**
	 * How the audit names a write by a member of a group the secret is shared with.
	 */
	public const GROUP_WRITE = 'group-write sync';

	/**
	 * How the audit names a write by a delegate.
	 */
	public const DELEGATE_WRITE = 'delegate sync';

	/**
	 * The share audit trail.
	 *
	 * @var ShareAuditTrail
	 */
	private ShareAuditTrail $auditTrail;

	/**
	 * Constructor for ShareSyncService.
	 *
	 * @param ShareTargetMapper $mapper The share-target mapper
	 * @param SecretMapper $secretMapper The Secret mapper (recipient copies)
	 * @param IDBConnection $db The DB connection (sync transaction)
	 * @param ShareAuthorizationService $auth The share authorization service
	 * @param SecretVersionService $versionService The version history (snapshot of a source written by anyone but its owner)
	 * @param ShareSyncGuards $guards The checks a write for every copy passes
	 * @param ShareWriteContext $contexts The caller's standing on a source, and the write context
	 * @param ShareAuditTrail|null $auditTrail The share audit trail
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; the sync behaviour carries the spec anchors.
	 */
	public function __construct(
		private ShareTargetMapper $mapper,
		private SecretMapper $secretMapper,
		private IDBConnection $db,
		private ShareAuthorizationService $auth,
		private SecretVersionService $versionService,
		private ShareSyncGuards $guards,
		private ShareWriteContext $contexts,
		?ShareAuditTrail $auditTrail = null,
	) {
		$this->auditTrail = ($auditTrail ?? new ShareAuditTrail());
	}//end __construct()

	/**
	 * Push an updated encrypted blob to every recipient.
	 *
	 * The browser supplies one blob per recipient (the source secret's
	 * new value re-encrypted under each recipient's public key). The
	 * server validates the caller may write the source (resolveSyncWriter()),
	 * applies an optimistic-locking check via the source Secret's
	 * `updatedAt`, moves that version and writes every recipient copy in a
	 * single transaction, and clears `possiblyCompromisedAt` from any copy
	 * where it was set. A sync by anyone but the owner is audited on the
	 * source in that transaction. A member of a group the secret is shared
	 * with gets share-save's guards (ShareSyncGuards).
	 *
	 * @param string $secretId The source secret ID
	 * @param array<int,array<string,string|null>> $updates The per-recipient blobs; each row has
	 *                                                      secretId, key, login,
	 *                                                      additionalFields, updatedAtCheck
	 * @param string $expectedUpdatedAt The owner-side expected ISO timestamp for optimistic locking
	 * @param string $userId The requesting user
	 *
	 * @return int Number of recipient copies updated.
	 *
	 * @throws InvalidArgumentException When validation or optimistic-lock check fails
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-sync-on-update
	 * @spec openspec/specs/use-only-shares/spec.md#requirement-the-server-refuses-what-it-can-enforce
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-grade-changes-and-non-owner-writes-are-audited
	 */
	public function syncUpdate(
		string $secretId,
		array $updates,
		string $expectedUpdatedAt,
		string $userId,
	): int {
		$source = $this->auth->loadSecret(secretId: $secretId);
		$this->guards->assertWritable(source: $source);

		$writesOn = $this->resolveSyncWriter(source: $source, userId: $userId);
		$this->guards->assertBaseNamed(writesOn: $writesOn, expectedUpdatedAt: $expectedUpdatedAt);

		$this->assertSyncSourceUnchanged(source: $source, expectedUpdatedAt: $expectedUpdatedAt);

		// The source is locked against the version just read, which is the
		// one the caller named when it named one.
		$base = $source->getUpdatedAt();

		// After the stored version even within the same second, as a share-save stamps.
		$savedAt = new DateTime('@'.max(time(), ($source->getUpdatedAt()?->getTimestamp() ?? 0) + 1));
		$allowedIds = $this->collectSyncTargets(source: $source);
		return $this->atomic(
			fn: function () use ($source, $updates, $allowedIds, $base, $savedAt, $writesOn, $userId): int {
				$this->lockSource(source: $source, base: $base, savedAt: $savedAt);
				if ($writesOn === self::GROUP_WRITE) {
					[$writesOn, $allowedIds] = $this->guards->assertGroupWrite(sync: $this, source: $source, updates: $updates, userId: $userId);
				}

				$updated = $this->writeCopyBlobs(
					source: $source,
					updates: $updates,
					allowedIds: $allowedIds,
					savedAt: $savedAt,
					writerId: $userId
				);
				if ($updated > 0) {
					$this->recordNonOwnerSync(source: $source, userId: $userId, writesOn: $writesOn);
				}

				return $updated;
			},
			db: $this->db
		);
	}//end syncUpdate()

	/**
	 * Attribute a sync by anyone but the owner to the writer on the source
	 * (§3.3), as a share-save through a copy is: a team writer as
	 * `team-write sync`, a group member as `group-write sync`, a delegate as
	 * `delegate sync`. Identifiers only, never key material.
	 *
	 * @param Secret $source The source secret being synced
	 * @param string $userId The requesting user
	 * @param string|null $writesOn What resolveSyncWriter() found
	 *
	 * @return void
	 *
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-grade-changes-and-non-owner-writes-are-audited
	 * @spec openspec/specs/sharing-group/spec.md#requirement-a-group-member-saves-for-everyone-it-is-shared-with
	 */
	private function recordNonOwnerSync(Secret $source, string $userId, ?string $writesOn): void {
		$changedFields = $writesOn;
		if ($changedFields === null) {
			if ($source->getOwnerType() === 'user' && $source->getOwnerId() === $userId) {
				return;
			}

			$changedFields = self::DELEGATE_WRITE;
		}

		$this->auditTrail->recordTeamWriteSync(
			userId: $userId,
			secretId: $source->getId(),
			secretName: $source->getName(),
			changedFields: $changedFields,
		);
	}//end recordNonOwnerSync()

	/**
	 * The write context of a secret for the current user, as
	 * ShareWriteContext::writeContext() answers it.
	 *
	 * @param string $secretId The secret (source or recipient copy) UUID
	 * @param string $userId The requesting user
	 *
	 * @return array{sourceSecretId:string|null, effectiveGrade:string, ownerCertificate:string|null, sourceUpdatedAt:string|null}
	 *
	 * @throws InvalidArgumentException When the secret does not exist or the caller does not reach it
	 *
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-a-write-grade-member-may-update-a-folder-secret-for-all-recipients
	 * @spec openspec/specs/sharing-group/spec.md#requirement-a-group-member-saves-for-everyone-it-is-shared-with
	 */
	public function writeContext(string $secretId, string $userId): array {
		return $this->contexts->writeContext(secretId: $secretId, userId: $userId);
	}//end writeContext()

	/**
	 * Authorization seam for a sync (folder-permission-grades §2.3): the
	 * owner and active delegates, a member holding a `write` or `manage` grade
	 * on an ancestor team folder, or a member of a group the source is shared with
	 * who holds a copy of it they may change. Everyone else is refused.
	 *
	 * @param Secret $source The source secret being synced
	 * @param string $userId The requesting user
	 *
	 * @return string|null How the audit names the write (TEAM_WRITE or GROUP_WRITE); null for the owner or a delegate.
	 *
	 * @throws InvalidArgumentException When the caller may not write the secret
	 *
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-a-write-grade-member-may-update-a-folder-secret-for-all-recipients
	 * @spec openspec/specs/sharing-group/spec.md#requirement-a-group-member-saves-for-everyone-it-is-shared-with
	 */
	public function resolveSyncWriter(Secret $source, string $userId): ?string {
		$standing = $this->contexts->standingOn(source: $source, userId: $userId);
		if ($this->contexts->writesForEveryone(standing: $standing) === true) {
			return $standing['writesOn'];
		}

		throw new InvalidArgumentException(message: 'Not authorized to manage shares of this secret');
	}//end resolveSyncWriter()

	/**
	 * Optimistic lock — if the source has moved since the browser last
	 * fetched it, refuse the sync so the caller can re-encrypt against the
	 * current value.
	 *
	 * @param Secret $source The source secret being synced
	 * @param string $expectedUpdatedAt The owner-side expected ISO timestamp
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the source has changed since the sync was prepared
	 */
	private function assertSyncSourceUnchanged(Secret $source, string $expectedUpdatedAt): void {
		if ($expectedUpdatedAt === '' || $source->getUpdatedAt() === null) {
			return;
		}

		if ($source->getUpdatedAt()->format(DateTime::ATOM) !== $expectedUpdatedAt) {
			throw new InvalidArgumentException(
				message: 'Source secret has changed since the sync was prepared'
			);
		}
	}//end assertSyncSourceUnchanged()

	/**
	 * Move the source off the version it was read at, inside the sync's
	 * transaction, as a share-save does: a save that commits after the read
	 * refuses the sync here instead of having its copies overwritten.
	 *
	 * @param Secret $source The source secret being synced
	 * @param DateTime|null $base The version the source was read at; null (no version stored) skips the lock
	 * @param DateTime $savedAt The version every written row gets
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the source moved on since the read
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-shared-secret-and-every-copy-save-in-one-transaction
	 */
	private function lockSource(Secret $source, ?DateTime $base, DateTime $savedAt): void {
		if ($base === null) {
			return;
		}

		if ($this->secretMapper->advanceVersion(id: $source->getId(), base: $base, savedAt: $savedAt) === 0) {
			throw new InvalidArgumentException(
				message: 'Source secret has changed since the sync was prepared'
			);
		}
	}//end lockSource()

	/**
	 * Membership guard: a sync may only touch the source row itself and its
	 * ACTUAL recipient copies. Without this, any authorized caller could
	 * pass arbitrary secret ids and corrupt foreign ciphertext (pre-existing
	 * defect fixed with folder-permission-grades §2.3).
	 *
	 * @param Secret $source The source secret being synced
	 *
	 * @return array<string,bool> The secret ids this sync may write, keyed by id.
	 */
	private function collectSyncTargets(Secret $source): array {
		$allowedIds = [$source->getId() => true];
		foreach ($this->mapper->findBySourceSecret($source->getId()) as $shareRow) {
			$allowedIds[$shareRow->getSecretId()] = true;
		}

		return $allowedIds;
	}//end collectSyncTargets()

	/**
	 * Write every permitted recipient blob. Opens no transaction: the caller
	 * runs it inside its own, so it commits with the rest of the save. When
	 * anyone but the owner writes the source, or the writer writes a copy of
	 * their own, its state before the write is kept as a version
	 * (keepVersion()).
	 *
	 * @param Secret $source The source secret being synced
	 * @param array<int,array<string,string|null>> $updates The per-recipient blobs
	 * @param array<string,bool> $allowedIds The secret ids this sync may write
	 * @param DateTime $savedAt The version every written row gets
	 * @param string $writerId The caller
	 *
	 * @return int Number of recipient copies updated.
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-sync-on-update
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-shared-secret-and-every-copy-save-in-one-transaction
	 * @spec openspec/specs/secret-version-history/spec.md#requirement-prior-encrypted-values-are-retained-on-update
	 */
	public function writeCopyBlobs(
		Secret $source,
		array $updates,
		array $allowedIds,
		DateTime $savedAt,
		string $writerId,
	): int {
		$updated = 0;
		foreach ($updates as $update) {
			$recipientSecretId = (string)($update['secretId'] ?? '');
			if ($recipientSecretId === '' || isset($allowedIds[$recipientSecretId]) === false) {
				continue;
			}

			try {
				$copy = $this->secretMapper->findById($recipientSecretId);
			} catch (DoesNotExistException) {
				continue;
			}

			$preUpdate = clone $copy;
			$this->applyRecipientBlob(copy: $copy, update: $update, savedAt: $savedAt);
			$this->keepVersion(source: $source, preUpdate: $preUpdate, after: $copy, writerId: $writerId);

			// A key rewrite of the SOURCE row is a real rotation —
			// advance keyUpdatedAt so rotation proofs stay honest
			// (folder-permission-grades §2.3).
			if ($recipientSecretId === $source->getId() && isset($update['key']) === true) {
				$copy->setKeyUpdatedAt(clone $savedAt);
			}

			$this->secretMapper->update($copy);
			++$updated;
		}//end foreach

		return $updated;
	}//end writeCopyBlobs()

	/**
	 * Keep a written row's state before the write as a version, with the
	 * writer as its actor, for the source written by anyone but its owner (a
	 * delegate, a team-folder writer or a member of a group it is shared with)
	 * and for a copy of the writer's own, as share-save keeps the caller's
	 * row, under the rule of SecretVersionService::snapshotIfChanged(), as on
	 * any update. Other holders' copies are not kept: a recipient's
	 * history is their own, and the owner's update keeps none of them either.
	 *
	 * @param Secret $source The source secret being synced
	 * @param Secret $preUpdate The row as read under the lock
	 * @param Secret $after The row with the write applied
	 * @param string $writerId The caller
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-version-history/spec.md#requirement-prior-encrypted-values-are-retained-on-update
	 */
	private function keepVersion(Secret $source, Secret $preUpdate, Secret $after, string $writerId): void {
		// The source unless the writer owns it, a copy only when they do.
		$writersOwn = $preUpdate->getOwnerType() === 'user' && $preUpdate->getOwnerId() === $writerId;
		if (($preUpdate->getId() === $source->getId()) !== $writersOwn) {
			$this->versionService->snapshotIfChanged(preUpdate: $preUpdate, after: $after, actorType: 'user', actorId: $writerId);
		}
	}//end keepVersion()

	/**
	 * Copy one re-encrypted blob onto a recipient's Secret row.
	 *
	 * @param Secret $copy The recipient copy to mutate
	 * @param array<string,string|null> $update The blob row for this recipient
	 * @param DateTime $savedAt The copy's new version
	 *
	 * @return void
	 */
	private function applyRecipientBlob(Secret $copy, array $update, DateTime $savedAt): void {
		if (isset($update['key']) === true) {
			$copy->setKey((string)$update['key']);
		}

		if (array_key_exists('login', $update) === true) {
			$login = null;
			if ($update['login'] !== null) {
				$login = (string)$update['login'];
			}

			$copy->setLogin($login);
		}

		if (array_key_exists('additionalFields', $update) === true) {
			$additionalFields = null;
			if ($update['additionalFields'] !== null) {
				$additionalFields = (string)$update['additionalFields'];
			}

			$copy->setAdditionalFields($additionalFields);
		}

		$copy->setUpdatedAt(clone $savedAt);

		// If the copy was previously flagged as possibly compromised
		// (e.g. EncryptionSuite migration mid-air), the freshly
		// re-encrypted blob clears that warning for the recipient.
		if ($copy->getPossiblyCompromisedAt() !== null) {
			$copy->setPossiblyCompromisedAt(null);
		}
	}//end applyRecipientBlob()
}//end class
