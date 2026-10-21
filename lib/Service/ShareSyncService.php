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
use OCA\Keepiq\Exception\ForbiddenException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\TTransactional;
use OCP\IDBConnection;

/**
 * Pushes an updated encrypted blob to every recipient of a secret.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) 13 against a threshold of 13:
 * telling a source from a received copy takes both outcomes of the share-row
 * lookup and a refusal of its own.
 */
class ShareSyncService {
	use TTransactional;

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
	) {
		$this->auditTrail = ($auditTrail ?? new ShareAuditTrail());
	}//end __construct()

	/**
	 * Push an updated encrypted blob to every recipient.
	 *
	 * The browser supplies one blob per recipient (the source secret's
	 * new value re-encrypted under each recipient's public key). The
	 * server validates the caller is the owner or an active delegate,
	 * applies an optimistic-locking check via the source Secret's
	 * `updatedAt`, moves that version and writes every recipient copy in a
	 * single transaction, and clears `possiblyCompromisedAt` from any copy
	 * where it was set. A sync by anyone but the owner is audited on the
	 * source in that transaction.
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
	 * @throws ForbiddenException When the secret is a read-only copy or a received copy
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-sync-on-update
	 * @spec openspec/specs/user-sharing/spec.md#requirement-view-only-copies
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

		$this->assertNotACopy(secret: $source);
		$isWriter = $this->resolveSyncWriter(source: $source, userId: $userId);
		$this->assertSyncSourceUnchanged(source: $source, expectedUpdatedAt: $expectedUpdatedAt);

		// The source is locked against the version just read, which is the
		// one the caller named when it named one.
		$base = $source->getUpdatedAt();

		// After the stored version even within the same second, as a share-save stamps.
		$savedAt = new DateTime('@'.max(time(), ($source->getUpdatedAt()?->getTimestamp() ?? 0) + 1));
		$allowedIds = $this->collectSyncTargets(source: $source);
		return $this->atomic(
			fn: function () use ($source, $updates, $allowedIds, $base, $savedAt, $isWriter, $userId): int {
				$this->lockSource(source: $source, base: $base, savedAt: $savedAt);
				$updated = $this->writeCopyBlobs(source: $source, updates: $updates, allowedIds: $allowedIds, savedAt: $savedAt);
				if ($updated > 0) {
					$this->recordNonOwnerSync(source: $source, userId: $userId, isWriter: $isWriter);
				}

				return $updated;
			},
			db: $this->db
		);
	}//end syncUpdate()

	/**
	 * Refuse a sync that starts from a received copy. A copy is owned by its
	 * holder, so the owner check would pass and the sync would write that copy
	 * alone, whether or not the holder may write its source.
	 *
	 * @param Secret $secret The secret the sync names
	 *
	 * @return void
	 *
	 * @throws ForbiddenException When the secret is a received copy
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-view-only-copies
	 */
	private function assertNotACopy(Secret $secret): void {
		try {
			$this->mapper->findByRecipientSecret(recipientSecretId: $secret->getId());
		} catch (DoesNotExistException) {
			return;
		} catch (MultipleObjectsReturnedException) {
			// More than one share row: still a copy.
		}

		throw new ForbiddenException(message: 'A sync starts from the source secret, not from a copy');
	}//end assertNotACopy()

	/**
	 * Attribute a sync by anyone but the owner to the writer on the source
	 * (§3.3), as a share-save through a copy is: a team writer as
	 * `team-write sync`, a delegate as `delegate sync`. Identifiers only,
	 * never key material.
	 *
	 * @param Secret $source The source secret being synced
	 * @param string $userId The requesting user
	 * @param bool $isWriter Whether the caller writes on a team-folder grade
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `$isWriter` is what resolveSyncWriter() found
	 *
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-grade-changes-and-non-owner-writes-are-audited
	 */
	private function recordNonOwnerSync(Secret $source, string $userId, bool $isWriter): void {
		$changedFields = 'team-write sync';
		if ($isWriter === false) {
			if ($source->getOwnerType() === 'user' && $source->getOwnerId() === $userId) {
				return;
			}

			$changedFields = 'delegate sync';
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
	 * material).
	 *
	 * @param string $secretId The secret (source or recipient copy) UUID
	 * @param string $userId The requesting user
	 *
	 * @return array{sourceSecretId:string, effectiveGrade:string, ownerCertificate:string|null, sourceUpdatedAt:string|null}
	 *
	 * @throws InvalidArgumentException When the secret does not exist
	 *
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-a-write-grade-member-may-update-a-folder-secret-for-all-recipients
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
		}

		$grade = 'none';
		$isOwner = ($this->auth->isOwnerOrDelegate(secret: $source, userId: $userId) === true);
		if ($isOwner === true) {
			$grade = 'owner';
		}

		if ($isOwner === false) {
			$resolved = $this->auth->resolveGrade(secret: $source, userId: $userId);
			if ($resolved !== null) {
				$grade = $resolved;
			}
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
	 * Authorization seam for a sync (folder-permission-grades §2.3): the
	 * owner and active delegates as before, OR a member holding a `write`
	 * grade on an ancestor team folder. Everyone else keeps the existing
	 * rejection.
	 *
	 * @param Secret $source The source secret being synced
	 * @param string $userId The requesting user
	 *
	 * @return bool True when the caller writes as a team-folder member rather than the owner.
	 *
	 * @throws InvalidArgumentException When the caller may not write the secret
	 *
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-a-write-grade-member-may-update-a-folder-secret-for-all-recipients
	 */
	public function resolveSyncWriter(Secret $source, string $userId): bool {
		if ($this->auth->isOwnerOrDelegate(secret: $source, userId: $userId) === true) {
			return false;
		}

		$grade = $this->auth->resolveGrade(secret: $source, userId: $userId);
		if (in_array($grade, TeamFolderMember::WRITE_GRADES, true) === false) {
			$this->auth->assertOwnerOrDelegate(secret: $source, userId: $userId);
		}

		return true;
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
	 * runs it inside its own, so it commits with the rest of the save.
	 *
	 * @param Secret $source The source secret being synced
	 * @param array<int,array<string,string|null>> $updates The per-recipient blobs
	 * @param array<string,bool> $allowedIds The secret ids this sync may write
	 * @param DateTime $savedAt The version every written row gets
	 *
	 * @return int Number of recipient copies updated.
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-sync-on-update
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-shared-secret-and-every-copy-save-in-one-transaction
	 */
	public function writeCopyBlobs(Secret $source, array $updates, array $allowedIds, DateTime $savedAt): int {
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

			$this->applyRecipientBlob(copy: $copy, update: $update, savedAt: $savedAt);

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
