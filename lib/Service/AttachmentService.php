<?php

/**
 * Keepiq Attachment Service
 *
 * Business logic for encrypted attachments (encrypted-attachments §2):
 * ciphertext blobs in IAppData, metadata + per-copy RSA-wrapped file keys
 * in Keepiq's own tables. The server brokers where bytes live and who
 * holds a wrapped key but never sees plaintext bytes, the plaintext
 * filename, or the file key (ADR-003). One physical blob per file; a
 * blob is unlinked only when its LAST grant is deleted.
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
use OCA\Keepiq\Db\Attachment;
use OCA\Keepiq\Db\AttachmentGrant;
use OCA\Keepiq\Db\AttachmentGrantMapper;
use OCA\Keepiq\Db\AttachmentMapper;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Kernel\AppIdentity;
use OCA\Keepiq\Kernel\Contracts\AttachmentServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\IAppConfig;
use Ramsey\Uuid\Uuid;

/**
 * Business logic for the encrypted-attachment lifecycle.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The service threads the
 *   attachment/grant/secret/suite mappers plus app-data and config so the
 *   blob + grant invariants live in one place.
 */
class AttachmentService implements AttachmentServiceInterface {
	/**
	 * The IAppData folder holding attachment ciphertext blobs.
	 */
	private const BLOB_FOLDER = 'attachments';

	/**
	 * The IAppData namespace the blob folder lives under.
	 *
	 * Follows the app id. Blobs written under the pre-rename namespace are
	 * relocated by OCA\Keepiq\Repair\MoveAttachmentBlobs before this is
	 * read — see blobFolder().
	 */
	private const BLOB_APP_DATA_NAMESPACE = AppIdentity::APP_ID;

	/**
	 * The pre-rename AppData namespace, still consulted on READ.
	 *
	 * MoveAttachmentBlobs relocates blobs out of here, but it is deliberately
	 * incapable of aborting an upgrade: a pre-migration step that throws leaves
	 * the app half-migrated. That safety has a cost — a blob it declines to
	 * move (a collision, a short copy, a storage error) keeps its bytes here
	 * while reads look only in the new namespace, so preserving the source
	 * would protect the data and still make the attachment unavailable.
	 *
	 * So reads fall back here. Availability then does not depend on the
	 * migration having completed, which is what makes the migration safe to
	 * make non-fatal. Writes never come here; this is a read-only tail that
	 * goes away with the rest of the pre-stable compatibility, per
	 * AppIdentity::PRE_STABLE_COMPAT_REMOVED_IN.
	 *
	 * @var string
	 */
	private const LEGACY_BLOB_APP_DATA_NAMESPACE = 'doriath';

	/**
	 * Constructor for AttachmentService.
	 *
	 * @param AttachmentMapper $mapper The attachment mapper
	 * @param AttachmentGrantMapper $grantMapper The grant mapper
	 * @param SecretMapper $secretMapper The secret mapper (authorization)
	 * @param EncryptionSuiteMapper $suiteMapper The suite mapper (grant provenance)
	 * @param IAppDataFactory $appDataFactory The app-data factory (blob storage)
	 * @param IAppConfig $appConfig The app config (limits)
	 * @param WriteLockService $writeLockService The compromise-recovery write lock
	 * @param IEventDispatcher|null $eventDispatcher The audit event dispatcher
	 * @param AuditEventFactory $auditEvents The audit-event factory
	 *
	 * @return void
	 */
	public function __construct(
		private AttachmentMapper $mapper,
		private AttachmentGrantMapper $grantMapper,
		private SecretMapper $secretMapper,
		private EncryptionSuiteMapper $suiteMapper,
		private IAppDataFactory $appDataFactory,
		private IAppConfig $appConfig,
		private WriteLockService $writeLockService,
		private ?IEventDispatcher $eventDispatcher = null,
		private AuditEventFactory $auditEvents = new AuditEventFactory(),
	) {
	}//end __construct()

	/**
	 * Dispatch an audit event when a dispatcher is wired.
	 *
	 * @param AuditEvent $event The audit event
	 *
	 * @return void
	 */
	private function dispatchAudit(AuditEvent $event): void {
		$this->eventDispatcher?->dispatchTyped($event);
	}//end dispatchAudit()

	/**
	 * The app-data namespace holding attachment ciphertext blobs.
	 *
	 * DELIBERATELY THE OLD APP ID, `doriath`, AFTER THE doriath -> keepiq
	 * RENAME. `IAppDataFactory::get($appId)` resolves to the on-disk folder
	 * `appdata_<instanceid>/<appId>/`, so this string is a STORAGE LOCATION,
	 * not a label. Every attachment lives at
	 * `appdata_<instanceid>/<namespace>/attachments/<blob_ref>`, addressed by
	 * the `blob_ref` column in `keepiq_attachments` — and the bytes are
	 * AES-GCM ciphertext whose file key is RSA-wrapped per recipient, so they
	 * cannot be re-created from anything the server holds.
	 *
	 * It follows the app id only because the blobs were MOVED to match. Doing
	 * one without the other points the app at an empty folder: uploads keep
	 * working, every existing attachment 404s on download, and nothing logs an
	 * error. OCA\Keepiq\Repair\MoveAttachmentBlobs performs the relocation as
	 * a pre-migration step, verifying each copy before removing its source.
	 *
	 * @return \OCP\Files\SimpleFS\ISimpleFolder
	 */
	private function blobFolder(): \OCP\Files\SimpleFS\ISimpleFolder {
		$appData = $this->appDataFactory->get(self::BLOB_APP_DATA_NAMESPACE);
		try {
			return $appData->getFolder(self::BLOB_FOLDER);
		} catch (NotFoundException) {
			return $appData->newFolder(self::BLOB_FOLDER);
		}
	}//end blobFolder()

	/**
	 * Locate one blob for reading or deleting, new namespace first.
	 *
	 * Falls back to the pre-rename namespace so an attachment MoveAttachmentBlobs
	 * declined to relocate stays readable. Without this, a skipped blob is
	 * bytes-intact and unreachable, which is indistinguishable from lost.
	 *
	 * @param string $blobRef The blob file name.
	 *
	 * @return \OCP\Files\SimpleFS\ISimpleFile The blob.
	 *
	 * @throws NotFoundException When neither namespace holds it.
	 */
	private function blobFileForRead(string $blobRef): \OCP\Files\SimpleFS\ISimpleFile {
		try {
			return $this->blobFolder()->getFile($blobRef);
		} catch (NotFoundException) {
			// Not in the current namespace — it may not have been relocated.
		}

		return $this->appDataFactory
			->get(self::LEGACY_BLOB_APP_DATA_NAMESPACE)
			->getFolder(self::BLOB_FOLDER)
			->getFile($blobRef);
	}//end blobFileForRead()

	/**
	 * Upload a client-encrypted attachment against an owned secret.
	 *
	 * The blob is AES-GCM ciphertext produced in the browser; the caller
	 * supplies the encrypted `{filename, contentType}` metadata and the
	 * owner's RSA-wrapped file key. Size limit and per-user quota are
	 * enforced server-side in ciphertext bytes BEFORE persisting.
	 *
	 * @param AttachmentUpload $upload The secret, the caller, the ciphertext, its metadata and the wrapped key
	 *
	 * @return array{attachment: Attachment, grant: AttachmentGrant}
	 *
	 * @throws InvalidArgumentException On validation/authorization failure
	 *
	 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-client-side-encrypted-attachment-upload
	 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-per-attachment-size-limit-and-per-user-quota
	 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-attachment-operations-are-auditable
	 */
	public function upload(AttachmentUpload $upload): array {
		// A new attachment would be wrapped to whichever suite is current, while
		// the migration is walking the other one — so it would either be missed
		// or counted as outstanding work that the client never fetched. Reads
		// stay open; only the write is refused.
		$this->writeLockService->assertNotWriteLocked(ownerId: $upload->userId);

		$secret = $this->loadOwnedSecret(secretId: $upload->secretId, userId: $upload->userId);

		if ($upload->isComplete() === false) {
			throw new InvalidArgumentException('blob, encryptedMetadata, and wrappedFileKey are required');
		}

		$size = strlen($upload->blob);
		$this->assertWithinLimits(upload: $upload, size: $size);

		$suiteId = $this->activeSuiteId(ownerType: 'user', ownerId: $upload->userId);
		$attachment = $this->storeAttachment(upload: $upload, size: $size);

		$grant = $this->insertGrant(
			grant: new NewAttachmentGrant(
				attachmentId: $attachment->getId(),
				secretId: $upload->secretId,
				recipientType: 'user',
				recipientId: $upload->userId,
				wrappedFileKey: $upload->wrappedFileKey,
				suiteId: $suiteId,
			)
		);

		$this->auditUpload(secret: $secret, attachment: $attachment);

		return [
			'attachment' => $attachment,
			'grant' => $grant,
		];
	}//end upload()

	/**
	 * Refuse an upload over the per-attachment limit or the caller's quota,
	 * both counted in ciphertext bytes.
	 *
	 * @param AttachmentUpload $upload The upload
	 * @param int $size The ciphertext size in bytes
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a limit would be exceeded
	 *
	 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-per-attachment-size-limit-and-per-user-quota
	 */
	private function assertWithinLimits(AttachmentUpload $upload, int $size): void {
		$maxBytes = $this->appConfig->getValueInt(AppIdentity::APP_ID, 'attachment_max_bytes', 26214400);
		if ($size > $maxBytes) {
			throw new InvalidArgumentException(
				'Attachment exceeds the per-attachment limit of ' . $maxBytes . ' bytes'
			);
		}

		$quota = $this->appConfig->getValueInt(AppIdentity::APP_ID, 'attachment_user_quota_bytes', 104857600);
		$used = $this->mapper->sumBytesForOwner(ownerId: $upload->userId);
		if (($used + $size) > $quota) {
			throw new InvalidArgumentException(
				'Attachment quota exceeded (' . $used . ' of ' . $quota . ' bytes in use)'
			);
		}
	}//end assertWithinLimits()

	/**
	 * The id of an owner's active suite, recorded as the wrap's provenance;
	 * null when they have none, which does not block the write.
	 *
	 * @param string $ownerType The owner type
	 * @param string $ownerId The owner id
	 *
	 * @return string|null
	 */
	private function activeSuiteId(string $ownerType, string $ownerId): ?string {
		try {
			return $this->suiteMapper->findActiveByOwner(ownerType: $ownerType, ownerId: $ownerId)->getId();
		} catch (DoesNotExistException) {
			// Provenance only — a missing suite does not block the write.
			return null;
		}
	}//end activeSuiteId()

	/**
	 * Write the ciphertext blob and insert its attachment row.
	 *
	 * @param AttachmentUpload $upload The upload
	 * @param int $size The ciphertext size in bytes
	 *
	 * @return Attachment The inserted row
	 */
	private function storeAttachment(AttachmentUpload $upload, int $size): Attachment {
		$blobRef = Uuid::uuid4()->toString() . '.bin';
		$this->blobFolder()->newFile($blobRef)->putContent($upload->blob);

		$attachment = new Attachment();
		$attachment->setId(Uuid::uuid4()->toString());
		$attachment->setSourceSecretId($upload->secretId);
		$attachment->setBlobRef($blobRef);
		$attachment->setEncryptedMetadata($upload->encryptedMetadata);
		$attachment->setSizeBytes($size);
		$attachment->setCreatedAt(new DateTime());
		$attachment->setUpdatedAt(new DateTime());

		return $this->mapper->insert($attachment);
	}//end storeAttachment()

	/**
	 * The audit metadata of an attachment event: its secret and its size.
	 *
	 * @param Attachment $attachment The attachment
	 *
	 * @return array{secretId: string, sizeBytes: int}
	 */
	private function auditMetadata(Attachment $attachment): array {
		return [
			'secretId' => $attachment->getSourceSecretId(),
			'sizeBytes' => $attachment->getSizeBytes(),
		];
	}//end auditMetadata()

	/**
	 * Audit an upload: the owner uploaded this attachment to their secret.
	 *
	 * @param Secret $secret The owned secret, whose owner is the uploader
	 * @param Attachment $attachment The inserted attachment
	 *
	 * @return void
	 *
	 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-attachment-operations-are-auditable
	 */
	private function auditUpload(Secret $secret, Attachment $attachment): void {
		$this->dispatchAudit(
			event: $this->auditEvents->forUser(
				actorId: $secret->getOwnerId(),
				eventType: AuditEventTypes::ATTACHMENT_UPLOADED,
				objectType: 'attachment',
				objectId: $attachment->getId(),
				objectName: $secret->getName(),
				metadata: $this->auditMetadata(attachment: $attachment),
			)
		);
	}//end auditUpload()

	/**
	 * List a secret's attachments with the CALLER'S own wrapped file key
	 * per attachment. Authorized for the owner and for any recipient
	 * holding a grant; others receive an empty list.
	 *
	 * @param string $secretId The secret UUID (owner's or a copy's source)
	 * @param string $userId The caller
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-single-blob-envelope-with-per-recipient-key-wrapping
	 */
	public function listForSecret(string $secretId, string $userId): array {
		$out = [];
		foreach ($this->mapper->findBySourceSecret(sourceSecretId: $secretId) as $attachment) {
			try {
				$grant = $this->grantMapper->findForRecipient(
					attachmentId: $attachment->getId(),
					recipientId: $userId
				);
			} catch (DoesNotExistException) {
				continue;
			}

			$row = $attachment->jsonSerialize();
			$row['wrappedFileKey'] = $grant->getWrappedFileKey();
			$out[] = $row;
		}

		return $out;
	}//end listForSecret()

	/**
	 * Stream an attachment's ciphertext blob — only to a caller holding a
	 * grant for it.
	 *
	 * @param string $attachmentId The attachment UUID
	 * @param string $userId The caller
	 *
	 * @return string The ciphertext bytes
	 *
	 * @throws InvalidArgumentException When not found / no grant held
	 *
	 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-single-blob-envelope-with-per-recipient-key-wrapping
	 */
	public function downloadBlob(string $attachmentId, string $userId): string {
		$attachment = $this->loadAttachment(attachmentId: $attachmentId);

		try {
			$this->grantMapper->findForRecipient(attachmentId: $attachmentId, recipientId: $userId);
		} catch (DoesNotExistException) {
			throw new InvalidArgumentException('No grant held for this attachment');
		}

		try {
			$bytes = $this->blobFileForRead(blobRef: $attachment->getBlobRef())->getContent();
		} catch (NotFoundException) {
			throw new InvalidArgumentException('Attachment blob is missing');
		}

		$this->dispatchAudit(
			event: $this->auditEvents->forUser(
				actorId: $userId,
				eventType: AuditEventTypes::ATTACHMENT_DOWNLOADED,
				objectType: 'attachment',
				objectId: $attachmentId,
				objectName: '',
				metadata: $this->auditMetadata(attachment: $attachment),
			)
		);

		return $bytes;
	}//end downloadBlob()

	/**
	 * Add a grant for a recipient copy (share/sync re-wrap). The blob is
	 * never re-uploaded; only the tiny wrapped key is per-recipient.
	 * Idempotent per (attachment, copy).
	 *
	 * @param AttachmentGrantRequest $request The attachment, the caller and the recipient copy
	 *
	 * @return AttachmentGrant
	 *
	 * @throws InvalidArgumentException On validation/authorization failure
	 *
	 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-single-blob-envelope-with-per-recipient-key-wrapping
	 */
	public function addGrant(AttachmentGrantRequest $request): AttachmentGrant {
		$this->writeLockService->assertNotWriteLocked(ownerId: $request->userId);

		$attachment = $this->loadAttachment(attachmentId: $request->attachmentId);
		$this->loadOwnedSecret(secretId: $attachment->getSourceSecretId(), userId: $request->userId);

		$request->assertValid();

		// Idempotent per copy: an existing grant for this copy is returned.
		$existing = $this->existingGrant(request: $request);
		if ($existing !== null) {
			return $existing;
		}

		$suiteId = $this->activeSuiteId(ownerType: $request->recipientType, ownerId: $request->recipientId);

		return $this->insertGrant(
			grant: new NewAttachmentGrant(
				attachmentId: $request->attachmentId,
				secretId: $request->copySecretId,
				recipientType: $request->recipientType,
				recipientId: $request->recipientId,
				wrappedFileKey: $request->wrappedFileKey,
				suiteId: $suiteId,
			)
		);
	}//end addGrant()

	/**
	 * The copy's grant for this attachment, when it already has one.
	 *
	 * @param AttachmentGrantRequest $request The grant request
	 *
	 * @return AttachmentGrant|null
	 */
	private function existingGrant(AttachmentGrantRequest $request): ?AttachmentGrant {
		foreach ($this->grantMapper->findBySecret(secretId: $request->copySecretId) as $existing) {
			if ($existing->getAttachmentId() === $request->attachmentId) {
				return $existing;
			}
		}

		return null;
	}//end existingGrant()

	/**
	 * Delete an attachment entirely — owner-only. Removes all grants and
	 * the blob, reclaiming quota. Idempotent.
	 *
	 * @param string $attachmentId The attachment UUID
	 * @param string $userId The caller (must own the source secret)
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException On not found / not authorized
	 *
	 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-attachment-deletion-cascade
	 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-attachment-operations-are-auditable
	 */
	public function delete(string $attachmentId, string $userId): void {
		// Deliberately NOT write-locked. The lock exists to stop new ciphertext
		// appearing under a suite the migration has already walked past;
		// deleting only shrinks the work list, which is re-derived from the rows
		// on every pass, so it cannot strand a run. And a user who has just
		// declared their key compromised is precisely the user who may need to
		// revoke something immediately.
		$attachment = $this->loadAttachment(attachmentId: $attachmentId);
		$secret = $this->loadOwnedSecret(secretId: $attachment->getSourceSecretId(), userId: $userId);

		foreach ($this->grantMapper->findByAttachment(attachmentId: $attachmentId) as $grant) {
			$this->grantMapper->delete($grant);
		}

		$this->unlinkBlobIfOrphaned(attachment: $attachment);
		$this->mapper->delete($attachment);

		$this->dispatchAudit(
			event: $this->auditEvents->forUser(
				actorId: $userId,
				eventType: AuditEventTypes::ATTACHMENT_DELETED,
				objectType: 'attachment',
				objectId: $attachmentId,
				objectName: $secret->getName(),
				metadata: $this->auditMetadata(attachment: $attachment),
			)
		);
	}//end delete()

	/**
	 * Cascade: delete every attachment of a source secret (secret delete,
	 * folder cascade, account deletion). Idempotent; no authorization —
	 * the caller (SecretService/AccountDeletionService) already owns the
	 * decision.
	 *
	 * @param string $sourceSecretId The source secret UUID
	 *
	 * @return int Attachments removed
	 *
	 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-attachment-deletion-cascade
	 */
	public function deleteForSecret(string $sourceSecretId): int {
		$removed = 0;
		foreach ($this->mapper->findBySourceSecret(sourceSecretId: $sourceSecretId) as $attachment) {
			foreach ($this->grantMapper->findByAttachment(attachmentId: $attachment->getId()) as $grant) {
				$this->grantMapper->delete($grant);
			}

			$this->unlinkBlobIfOrphaned(attachment: $attachment);
			$this->mapper->delete($attachment);
			++$removed;
		}

		return $removed;
	}//end deleteForSecret()

	/**
	 * Revocation cascade: delete the grants attached to one Secret COPY
	 * (share revoke / recipient suite revocation). The owner's blob and
	 * remaining grants are untouched.
	 *
	 * @param string $copySecretId The Secret copy UUID
	 *
	 * @return int Grants removed
	 *
	 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-attachment-deletion-cascade
	 */
	public function deleteGrantsForSecretCopy(string $copySecretId): int {
		$removed = 0;
		foreach ($this->grantMapper->findBySecret(secretId: $copySecretId) as $grant) {
			$this->grantMapper->delete($grant);
			++$removed;

			// No blob GC on this path by design: revoking a COPY's grant
			// must never touch the owner's blob. The previous guarded call
			// here passed keepWhileRowExists: true, which made the callee
			// return before any unlink could happen — i.e. it was already
			// unconditionally a no-op. It is spelled out rather than
			// performed so the intent is readable.
		}

		return $removed;
	}//end deleteGrantsForSecretCopy()

	/**
	 * Insert a grant row.
	 *
	 * @param NewAttachmentGrant $grant The grant to insert
	 *
	 * @return AttachmentGrant
	 */
	private function insertGrant(NewAttachmentGrant $grant): AttachmentGrant {
		return $this->grantMapper->insert($grant->toRow());
	}//end insertGrant()

	/**
	 * Unlink the blob when no grant references the attachment any more.
	 * Never deletes a blob while a grant remains; missing blobs are
	 * tolerated (idempotent cascade).
	 *
	 * @param Attachment $attachment The attachment row
	 *
	 * @return void
	 */
	private function unlinkBlobIfOrphaned(Attachment $attachment): void {
		if ($this->grantMapper->countByAttachment(attachmentId: $attachment->getId()) > 0) {
			return;
		}

		try {
			$this->blobFileForRead(blobRef: $attachment->getBlobRef())->delete();
		} catch (NotFoundException) {
			// Already gone — idempotent.
		}
	}//end unlinkBlobIfOrphaned()

	/**
	 * Load an attachment by id.
	 *
	 * @param string $attachmentId The attachment UUID
	 *
	 * @return Attachment
	 *
	 * @throws InvalidArgumentException When missing
	 */
	private function loadAttachment(string $attachmentId): Attachment {
		try {
			return $this->mapper->findById(id: $attachmentId);
		} catch (DoesNotExistException) {
			throw new InvalidArgumentException('Attachment not found');
		}
	}//end loadAttachment()

	/**
	 * Load a Secret and assert user ownership.
	 *
	 * @param string $secretId The secret UUID
	 * @param string $userId The candidate owner
	 *
	 * @return Secret
	 *
	 * @throws InvalidArgumentException On missing secret / foreign owner
	 */
	private function loadOwnedSecret(string $secretId, string $userId): Secret {
		try {
			$secret = $this->secretMapper->findById($secretId);
		} catch (DoesNotExistException) {
			throw new InvalidArgumentException('Secret not found');
		}

		if ($secret->getOwnerType() !== 'user' || $secret->getOwnerId() !== $userId) {
			throw new InvalidArgumentException('Not authorized for this secret');
		}

		return $secret;
	}//end loadOwnedSecret()
}//end class
