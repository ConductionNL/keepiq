<?php

/**
 * Keepiq Share Sync Service
 *
 * The multi-recipient re-encryption fan-out of the share lifecycle
 * (implement-user-sharing §3.4, folder-permission-grades §2.3): the write
 * context a client needs to prepare a sync, the optimistic-lock check,
 * the membership guard that confines a sync to the source row and its
 * ACTUAL recipient copies, and the transactional blob write itself.
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
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Db\TeamFolderMember;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
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
	 * @param EncryptionSuiteMapper $suiteMapper The EncryptionSuite mapper (owner certificate)
	 * @param IDBConnection $db The DB connection (sync transaction)
	 * @param ShareAuthorizationService $auth The share authorization service
	 * @param ShareAuditTrail|null $auditTrail The share audit trail
	 * @param SecretVersionService|null $versionService The version history (snapshot of a source written by anyone but its owner)
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; the sync behaviour carries the spec anchors.
	 */
	public function __construct(
		private ShareTargetMapper $mapper,
		private SecretMapper $secretMapper,
		private EncryptionSuiteMapper $suiteMapper,
		private IDBConnection $db,
		private ShareAuthorizationService $auth,
		?ShareAuditTrail $auditTrail = null,
		private ?SecretVersionService $versionService = null,
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
	 * with gets share-save's guards (assertGroupWriteGuards()).
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
		// A copy from another organisation is never written by its holder
		// (sharing-federated-recipients task 3.4).
		$source->assertNotReadOnly();
		if ($source->getUseOnly() === true) {
			// A use-only copy is never written by its holder (D4).
			throw new InvalidArgumentException(message: 'A use-only copy cannot be changed');
		}

		$writesOn = $this->resolveSyncWriter(source: $source, userId: $userId);
		if ($writesOn === self::GROUP_WRITE && $expectedUpdatedAt === '') {
			// A member never overwrites a value they have not seen.
			throw new InvalidArgumentException(message: 'expectedUpdatedAt is required when saving as a group member');
		}

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
					[$writesOn, $allowedIds] = $this->assertGroupWriteGuards(source: $source, updates: $updates, userId: $userId);
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
	 * Share-save's guards for a member of a group the secret is shared with,
	 * run under the source lock: the source is read again and refused when it
	 * became a read-only or use-only copy, the caller is checked again, so a
	 * leave or a revoke that committed after the first check refuses the sync,
	 * and the updates must name exactly the source and every copy whose access
	 * has not ended, so no holder is left on the old value. Only those rows are
	 * written. A named row that carries no value is left out, untouched, as
	 * share-save's `skipped` list leaves it, and only when its holder has no
	 * vault key: every other holder must get the new value.
	 *
	 * @param Secret $source The source secret being synced
	 * @param array<int,array<string,string|null>> $updates The per-recipient blobs
	 * @param string $userId The requesting user
	 *
	 * @return array{0:string|null,1:array<string,bool>} How the audit names the write now, and the ids to write
	 *
	 * @throws InvalidArgumentException When the caller lost the right to write, or a row is missing, unexpected or valueless
	 *
	 * @spec openspec/specs/sharing-group/spec.md#requirement-a-group-member-saves-for-everyone-it-is-shared-with
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-share-save-names-every-copy-or-is-refused
	 */
	private function assertGroupWriteGuards(Secret $source, array $updates, string $userId): array {
		// Read again, as share-save's assertStillWriter() does: becoming a
		// read-only or use-only copy does not move the version the lock checks.
		$current = $this->auth->loadSecret(secretId: $source->getId());
		$current->assertNotReadOnly();
		if ($current->getUseOnly() === true) {
			throw new InvalidArgumentException(message: 'A use-only copy cannot be changed');
		}

		$writesOn = $this->resolveSyncWriter(source: $current, userId: $userId);

		$required = [$source->getId() => true];
		$holders = [$source->getId() => [$source->getOwnerType(), $source->getOwnerId()]];
		$ignored = [];
		$targets = $this->mapper->findBySourceSecret($source->getId());
		$rows = $this->secretMapper->findByIds(ids: array_map(static fn ($target): string => $target->getSecretId(), $targets));
		$now = time();
		foreach ($targets as $target) {
			// A copy whose access ended, or whose row is gone, may be named but is not written.
			$copyId = $target->getSecretId();
			if (isset($rows[$copyId]) === false) {
				$ignored[$copyId] = true;
				continue;
			}

			$ends = $rows[$copyId]->getAccessExpiresAt();
			if ($ends !== null && $ends->getTimestamp() <= $now) {
				$ignored[$copyId] = true;
				continue;
			}

			$required[$copyId] = true;
			$holders[$copyId] = ['user', $target->getTargetUserId()];
		}

		$named = [];
		$valueless = [];
		foreach ($updates as $update) {
			$named[(string)($update['secretId'] ?? '')] = true;
			if ($this->carriesValue(update: $update) === false) {
				$valueless[(string)($update['secretId'] ?? '')] = true;
			}
		}

		if (array_diff_key($required, $named) !== [] || array_diff_key($named, $required, $ignored) !== []) {
			throw new InvalidArgumentException(
				message: 'Name the source and every copy whose access has not ended, and nothing else'
			);
		}

		foreach (array_keys(array_intersect_key($valueless, $required)) as $copyId) {
			[$ownerType, $ownerId] = $holders[$copyId];
			if ($this->suiteMapper->countActiveByOwner(ownerType: $ownerType, ownerId: $ownerId) > 0) {
				throw new InvalidArgumentException(
					message: 'Every holder with a vault key must get the new value'
				);
			}

			// No vault key to encrypt for: the row keeps its value and its version.
			unset($required[$copyId]);
		}

		return [$writesOn, $required];
	}//end assertGroupWriteGuards()

	/**
	 * Whether an update row carries ciphertext for its copy: a non-empty key,
	 * or a login or additional fields.
	 *
	 * @param array<string,string|null> $update The blob row
	 *
	 * @return bool
	 */
	private function carriesValue(array $update): bool {
		return (isset($update['key']) === true && $update['key'] !== '')
			|| array_key_exists('login', $update) === true
			|| array_key_exists('additionalFields', $update) === true;
	}//end carriesValue()

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
	 * The write context of a secret for the current user
	 * (folder-permission-grades §4): resolves a recipient copy back to
	 * its source and reports the caller's effective grade plus the
	 * owner-row material a write-grade member needs to run the
	 * re-encrypt fan-out (the owner's certificate is public key
	 * material). A member of a group the secret is shared with gets the
	 * `write` grade through their copy. Only a caller who reaches the
	 * source gets an answer: its owner, a delegate, a team-folder member
	 * with a grade on it, or the holder of a live copy of it. A caller who
	 * reaches no source through a live row of their own gets ownRowContext().
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
		$secret = $this->auth->loadSecret(secretId: $secretId);

		// Pivot a recipient copy back to its source.
		$source = $secret;
		try {
			$shareRow = $this->mapper->findByRecipientSecret(recipientSecretId: $secretId);
			$source = $this->auth->loadSecret(secretId: $shareRow->getSourceSecretId());
		} catch (DoesNotExistException) {
			// Not a copy — the secret is its own source.
		} catch (MultipleObjectsReturnedException) {
			return $this->ownRowContext(row: $secret, userId: $userId);
		}

		$grade = $this->standingOn(source: $source, userId: $userId)['grade'];
		if ($grade === null) {
			return $this->ownRowContext(row: $secret, userId: $userId);
		}

		$ownerCertificate = null;
		if ($grade === 'owner' || in_array($grade, TeamFolderMember::WRITE_GRADES, true) === true) {
			try {
				$ownerCertificate = $this->suiteMapper
					->findActiveByOwner(ownerType: $source->getOwnerType(), ownerId: $source->getOwnerId())
					->getCertificate();
			} catch (DoesNotExistException) {
				// Owner without an active suite — fan-out skips the source row.
			}
		}

		return [
			'sourceSecretId' => $source->getId(),
			'effectiveGrade' => $grade,
			'ownerCertificate' => $ownerCertificate,
			'sourceUpdatedAt' => $source->getUpdatedAt()?->format(DateTime::ATOM),
		];
	}//end writeContext()

	/**
	 * The write context of a row whose share rows lead to no source the
	 * caller stands on: two share rows for one copy or source, or a share row
	 * keyed to someone else. The holder of a live row still gets `none`, so
	 * their client saves that row alone. The answer names no source, as the
	 * share row may point at someone else's secret. Anyone else gets the
	 * answer of an unknown id.
	 *
	 * @param Secret $row The secret the caller asked about
	 * @param string $userId The requesting user
	 *
	 * @return array{sourceSecretId:null, effectiveGrade:string, ownerCertificate:null, sourceUpdatedAt:null}
	 *
	 * @throws InvalidArgumentException When the row is not the caller's, or its access ended
	 *
	 * @spec openspec/specs/sharing-group/spec.md#requirement-a-group-member-saves-for-everyone-it-is-shared-with
	 */
	private function ownRowContext(Secret $row, string $userId): array {
		$ends = $row->getAccessExpiresAt();
		if ($row->getOwnerType() !== 'user' || $row->getOwnerId() !== $userId
			|| ($ends !== null && $ends->getTimestamp() <= time())
		) {
			throw new InvalidArgumentException(message: 'Secret not found');
		}

		return [
			'sourceSecretId' => null,
			'effectiveGrade' => 'none',
			'ownerCertificate' => null,
			'sourceUpdatedAt' => null,
		];
	}//end ownRowContext()

	/**
	 * The caller's grade on a source and, when it lets them write every copy
	 * as someone other than the owner or a delegate, how the audit names that
	 * write: `owner` for the owner or a delegate, a team-folder write grade
	 * (TEAM_WRITE), `write` for a member of a group it is shared with who
	 * holds a live copy they may change (GROUP_WRITE), else their team-folder
	 * or copy grade. The grade is null when the caller does not reach the
	 * source.
	 *
	 * @param Secret $source The source secret
	 * @param string $userId The requesting user
	 *
	 * @return array{grade:string|null, writesOn:string|null}
	 */
	private function standingOn(Secret $source, string $userId): array {
		if ($this->auth->isOwnerOrDelegate(secret: $source, userId: $userId) === true) {
			return ['grade' => 'owner', 'writesOn' => null];
		}

		$grade = $this->auth->resolveGrade(secret: $source, userId: $userId);
		if (in_array($grade, TeamFolderMember::WRITE_GRADES, true) === true) {
			return ['grade' => $grade, 'writesOn' => self::TEAM_WRITE];
		}

		$copyGrade = $this->auth->copyGrade(source: $source, userId: $userId);
		if ($copyGrade === 'write') {
			return ['grade' => $copyGrade, 'writesOn' => self::GROUP_WRITE];
		}

		return ['grade' => ($grade ?? $copyGrade), 'writesOn' => null];
	}//end standingOn()

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
		$standing = $this->standingOn(source: $source, userId: $userId);
		if ($standing['grade'] === 'owner' || $standing['writesOn'] !== null) {
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
	 * anyone but the owner writes the source, its state before the write is
	 * kept as a version, as the owner's own update keeps it.
	 *
	 * @param Secret $source The source secret being synced
	 * @param array<int,array<string,string|null>> $updates The per-recipient blobs
	 * @param array<string,bool> $allowedIds The secret ids this sync may write
	 * @param DateTime $savedAt The version every written row gets
	 * @param string|null $writerId The caller; null takes no snapshot
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
		?string $writerId = null,
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
			if ($recipientSecretId === $source->getId()) {
				$this->snapshotSource(source: $source, preUpdate: $preUpdate, after: $copy, writerId: $writerId);
			}

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
	 * Keep the source's state before a write by anyone but its owner (a
	 * delegate, a team-folder writer or a member of a group it is shared with)
	 * as a version, with the writer as its actor: only when a value changes
	 * and the source held a value, as SecretService does on the owner's own
	 * update. The other copies are not kept: a recipient's history is its own
	 * copy's, and the owner's update keeps none of them either.
	 *
	 * @param Secret $source The source secret being synced
	 * @param Secret $preUpdate The source row as read under the lock
	 * @param Secret $after The source row with the write applied
	 * @param string|null $writerId The caller; null takes no snapshot
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-version-history/spec.md#requirement-prior-encrypted-values-are-retained-on-update
	 */
	private function snapshotSource(Secret $source, Secret $preUpdate, Secret $after, ?string $writerId): void {
		if ($this->versionService === null || $writerId === null
			|| ($source->getOwnerType() === 'user' && $source->getOwnerId() === $writerId)
		) {
			return;
		}

		$hadValues = (string)$preUpdate->getKey() !== ''
			|| (string)$preUpdate->getLogin() !== ''
			|| (string)$preUpdate->getAdditionalFields() !== '';
		$changed = $preUpdate->getKey() !== $after->getKey()
			|| $preUpdate->getLogin() !== $after->getLogin()
			|| $preUpdate->getAdditionalFields() !== $after->getAdditionalFields();
		if ($hadValues === true && $changed === true) {
			$this->versionService->snapshot(preUpdate: $preUpdate, actorType: 'user', actorId: $writerId);
		}
	}//end snapshotSource()

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
