<?php

/**
 * Doriath Share Sync Service
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
 * @package  OCA\Doriath\Service
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

namespace OCA\Doriath\Service;

use DateTime;
use InvalidArgumentException;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\ShareTargetMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use Throwable;

/**
 * Pushes an updated encrypted blob to every recipient of a secret.
 */
class ShareSyncService {

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
	 * `updatedAt`, writes every recipient copy in a single transaction,
	 * and clears `possiblyCompromisedAt` from any copy where it was set.
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
	 */
	public function syncUpdate(
		string $secretId,
		array $updates,
		string $expectedUpdatedAt,
		string $userId,
	): int {
		$source = $this->auth->loadSecret(secretId: $secretId);
		$isWriter = $this->resolveSyncWriter(source: $source, userId: $userId);
		$this->assertSyncSourceUnchanged(source: $source, expectedUpdatedAt: $expectedUpdatedAt);

		$updated = $this->applySyncUpdates(
			source: $source,
			updates: $updates,
			allowedIds: $this->collectSyncTargets(source: $source)
		);

		// A non-owner write is attributed to the writer (§3.3) —
		// identifiers only, never key material.
		if ($isWriter === true && $updated > 0) {
			$this->auditTrail->recordTeamWriteSync(
				userId: $userId,
				secretId: $source->getId(),
				secretName: $source->getName(),
			);
		}

		return $updated;
	}//end syncUpdate()

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
		if ($grade === 'write' || $grade === 'owner') {
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
	 */
	private function resolveSyncWriter(Secret $source, string $userId): bool {
		if ($this->auth->isOwnerOrDelegate(secret: $source, userId: $userId) === true) {
			return false;
		}

		$grade = $this->auth->resolveGrade(secret: $source, userId: $userId);
		if ($grade !== 'write') {
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
	 * Write every permitted recipient blob in a single transaction.
	 *
	 * @param Secret $source The source secret being synced
	 * @param array<int,array<string,string|null>> $updates The per-recipient blobs
	 * @param array<string,bool> $allowedIds The secret ids this sync may write
	 *
	 * @return int Number of recipient copies updated.
	 *
	 * @throws Throwable When the transaction fails; the write is rolled back first.
	 */
	private function applySyncUpdates(Secret $source, array $updates, array $allowedIds): int {
		$updated = 0;
		$this->db->beginTransaction();
		try {
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

				$this->applyRecipientBlob(copy: $copy, update: $update);

				// A key rewrite of the SOURCE row is a real rotation —
				// advance keyUpdatedAt so rotation proofs stay honest
				// (folder-permission-grades §2.3).
				if ($recipientSecretId === $source->getId() && isset($update['key']) === true) {
					$copy->setKeyUpdatedAt(new DateTime());
				}

				$this->secretMapper->update($copy);
				++$updated;
			}//end foreach

			$this->db->commit();
		} catch (Throwable $exception) {
			$this->db->rollBack();
			throw $exception;
		}//end try

		return $updated;
	}//end applySyncUpdates()

	/**
	 * Copy one re-encrypted blob onto a recipient's Secret row.
	 *
	 * @param Secret $copy The recipient copy to mutate
	 * @param array<string,string|null> $update The blob row for this recipient
	 *
	 * @return void
	 */
	private function applyRecipientBlob(Secret $copy, array $update): void {
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

		$copy->setUpdatedAt(new DateTime());

		// If the copy was previously flagged as possibly compromised
		// (e.g. EncryptionSuite migration mid-air), the freshly
		// re-encrypted blob clears that warning for the recipient.
		if ($copy->getPossiblyCompromisedAt() !== null) {
			$copy->setPossiblyCompromisedAt(null);
		}
	}//end applyRecipientBlob()
}//end class
