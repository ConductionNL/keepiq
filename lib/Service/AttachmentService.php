<?php

/**
 * Doriath Attachment Service
 *
 * Business logic for encrypted attachments (encrypted-attachments §2):
 * ciphertext blobs in IAppData, metadata + per-copy RSA-wrapped file keys
 * in Doriath's own tables. The server brokers where bytes live and who
 * holds a wrapped key but never sees plaintext bytes, the plaintext
 * filename, or the file key (ADR-003). One physical blob per file; a
 * blob is unlinked only when its LAST grant is deleted.
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
use OCA\Doriath\AppInfo\Application;
use OCA\Doriath\Db\Attachment;
use OCA\Doriath\Db\AttachmentGrant;
use OCA\Doriath\Db\AttachmentGrantMapper;
use OCA\Doriath\Db\AttachmentMapper;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Event\Audit\AuditEventTypes;
use OCP\AppFramework\Db\DoesNotExistException;
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
class AttachmentService
{
    /**
     * The IAppData folder holding attachment ciphertext blobs.
     */
    private const BLOB_FOLDER = 'attachments';

    /**
     * Constructor for AttachmentService.
     *
     * @param AttachmentMapper      $mapper         The attachment mapper
     * @param AttachmentGrantMapper $grantMapper    The grant mapper
     * @param SecretMapper          $secretMapper   The secret mapper (authorization)
     * @param EncryptionSuiteMapper $suiteMapper    The suite mapper (grant provenance)
     * @param IAppDataFactory       $appDataFactory The app-data factory (blob storage)
     * @param IAppConfig            $appConfig      The app config (limits)
     * @param AuditTrail|null       $auditTrail     The audit trail
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
        private ?AuditTrail $auditTrail=null,
    ) {
    }//end __construct()

    /**
     * The blob folder inside Doriath's app data (created on demand).
     *
     * @return \OCP\Files\SimpleFS\ISimpleFolder
     */
    private function blobFolder(): \OCP\Files\SimpleFS\ISimpleFolder
    {
        $appData = $this->appDataFactory->get(Application::APP_ID);
        try {
            return $appData->getFolder(self::BLOB_FOLDER);
        } catch (NotFoundException) {
            return $appData->newFolder(self::BLOB_FOLDER);
        }
    }//end blobFolder()

    /**
     * Upload a client-encrypted attachment against an owned secret.
     *
     * The blob is AES-GCM ciphertext produced in the browser; the caller
     * supplies the encrypted `{filename, contentType}` metadata and the
     * owner's RSA-wrapped file key. Size limit and per-user quota are
     * enforced server-side in ciphertext bytes BEFORE persisting.
     *
     * @param string $secretId          The owning secret UUID
     * @param string $userId            The caller (must own the secret)
     * @param string $blob              The ciphertext bytes
     * @param string $encryptedMetadata The AES-GCM metadata ciphertext
     * @param string $wrappedFileKey    The owner's RSA-wrapped file key
     *
     * @return array{attachment: Attachment, grant: AttachmentGrant}
     *
     * @throws InvalidArgumentException On validation/authorization failure
     *
     * @spec openspec/changes/encrypted-attachments/specs/encrypted-attachments/spec.md#requirement-client-side-encryption-and-upload
     */
    public function upload(
        string $secretId,
        string $userId,
        string $blob,
        string $encryptedMetadata,
        string $wrappedFileKey,
    ): array {
        $secret = $this->loadOwnedSecret(secretId: $secretId, userId: $userId);

        if ($blob === '' || $encryptedMetadata === '' || $wrappedFileKey === '') {
            throw new InvalidArgumentException('blob, encryptedMetadata, and wrappedFileKey are required');
        }

        $size     = strlen($blob);
        $maxBytes = $this->appConfig->getValueInt(Application::APP_ID, 'attachment_max_bytes', 26214400);
        if ($size > $maxBytes) {
            throw new InvalidArgumentException(
                'Attachment exceeds the per-attachment limit of '.$maxBytes.' bytes'
            );
        }

        $quota = $this->appConfig->getValueInt(Application::APP_ID, 'attachment_user_quota_bytes', 104857600);
        $used  = $this->mapper->sumBytesForOwner(ownerId: $userId);
        if (($used + $size) > $quota) {
            throw new InvalidArgumentException(
                'Attachment quota exceeded ('.$used.' of '.$quota.' bytes in use)'
            );
        }

        $suiteId = null;
        try {
            $suiteId = $this->suiteMapper->findActiveByOwner(ownerType: 'user', ownerId: $userId)->getId();
        } catch (DoesNotExistException) {
            // Provenance only — a missing suite does not block the upload.
        }

        $blobRef = Uuid::uuid4()->toString().'.bin';
        $this->blobFolder()->newFile($blobRef)->putContent($blob);

        $attachment = new Attachment();
        $attachment->setId(Uuid::uuid4()->toString());
        $attachment->setSourceSecretId($secretId);
        $attachment->setBlobRef($blobRef);
        $attachment->setEncryptedMetadata($encryptedMetadata);
        $attachment->setSizeBytes($size);
        $attachment->setCreatedAt(new DateTime());
        $attachment->setUpdatedAt(new DateTime());
        $attachment = $this->mapper->insert($attachment);

        $grant = $this->insertGrant(
            attachmentId: $attachment->getId(),
            secretId: $secretId,
            recipientType: 'user',
            recipientId: $userId,
            wrappedFileKey: $wrappedFileKey,
            suiteId: $suiteId,
        );

        $this->auditTrail?->forUser(
            actorId: $userId,
            eventType: AuditEventTypes::ATTACHMENT_UPLOADED,
            objectType: 'attachment',
            objectId: $attachment->getId(),
            objectName: $secret->getName(),
            metadata: [
                'secretId'  => $secretId,
                'sizeBytes' => $size,
            ],
        );

        return [
            'attachment' => $attachment,
            'grant'      => $grant,
        ];
    }//end upload()

    /**
     * List a secret's attachments with the CALLER'S own wrapped file key
     * per attachment. Authorized for the owner and for any recipient
     * holding a grant; others receive an empty list.
     *
     * @param string $secretId The secret UUID (owner's or a copy's source)
     * @param string $userId   The caller
     *
     * @return array<int,array<string,mixed>>
     *
     * @spec openspec/changes/encrypted-attachments/specs/encrypted-attachments/spec.md#requirement-listing-and-download
     */
    public function listForSecret(string $secretId, string $userId): array
    {
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
     * @param string $userId       The caller
     *
     * @return string The ciphertext bytes
     *
     * @throws InvalidArgumentException When not found / no grant held
     *
     * @spec openspec/changes/encrypted-attachments/specs/encrypted-attachments/spec.md#requirement-listing-and-download
     */
    public function downloadBlob(string $attachmentId, string $userId): string
    {
        $attachment = $this->loadAttachment(attachmentId: $attachmentId);

        try {
            $this->grantMapper->findForRecipient(attachmentId: $attachmentId, recipientId: $userId);
        } catch (DoesNotExistException) {
            throw new InvalidArgumentException('No grant held for this attachment');
        }

        try {
            $bytes = $this->blobFolder()->getFile($attachment->getBlobRef())->getContent();
        } catch (NotFoundException) {
            throw new InvalidArgumentException('Attachment blob is missing');
        }

        $this->auditTrail?->forUser(
            actorId: $userId,
            eventType: AuditEventTypes::ATTACHMENT_DOWNLOADED,
            objectType: 'attachment',
            objectId: $attachmentId,
            objectName: '',
            metadata: [
                'secretId'  => $attachment->getSourceSecretId(),
                'sizeBytes' => $attachment->getSizeBytes(),
            ],
        );

        return $bytes;
    }//end downloadBlob()

    /**
     * Add a grant for a recipient copy (share/sync re-wrap). The blob is
     * never re-uploaded; only the tiny wrapped key is per-recipient.
     * Idempotent per (attachment, copy).
     *
     * @param string $attachmentId   The attachment UUID
     * @param string $userId         The caller (must own the source secret)
     * @param string $copySecretId   The recipient's Secret copy UUID
     * @param string $recipientId    The recipient user/application ID
     * @param string $wrappedFileKey The file key wrapped under the recipient's cert
     * @param string $recipientType  The recipient type (`user`|`application`)
     *
     * @return AttachmentGrant
     *
     * @throws InvalidArgumentException On validation/authorization failure
     *
     * @spec openspec/changes/encrypted-attachments/specs/encrypted-attachments/spec.md#requirement-sharing-follows-the-secret
     */
    public function addGrant(
        string $attachmentId,
        string $userId,
        string $copySecretId,
        string $recipientId,
        string $wrappedFileKey,
        string $recipientType='user',
    ): AttachmentGrant {
        $attachment = $this->loadAttachment(attachmentId: $attachmentId);
        $this->loadOwnedSecret(secretId: $attachment->getSourceSecretId(), userId: $userId);

        if ($copySecretId === '' || $recipientId === '' || $wrappedFileKey === '') {
            throw new InvalidArgumentException('copySecretId, recipientId, and wrappedFileKey are required');
        }

        if (in_array($recipientType, ['user', 'application'], true) === false) {
            throw new InvalidArgumentException('recipientType must be user or application');
        }

        // Idempotent per copy: an existing grant for this copy is returned.
        foreach ($this->grantMapper->findBySecret(secretId: $copySecretId) as $existing) {
            if ($existing->getAttachmentId() === $attachmentId) {
                return $existing;
            }
        }

        $suiteId = null;
        try {
            $suiteId = $this->suiteMapper->findActiveByOwner(ownerType: $recipientType, ownerId: $recipientId)->getId();
        } catch (DoesNotExistException) {
            // Provenance only.
        }

        return $this->insertGrant(
            attachmentId: $attachmentId,
            secretId: $copySecretId,
            recipientType: $recipientType,
            recipientId: $recipientId,
            wrappedFileKey: $wrappedFileKey,
            suiteId: $suiteId,
        );
    }//end addGrant()

    /**
     * Delete an attachment entirely — owner-only. Removes all grants and
     * the blob, reclaiming quota. Idempotent.
     *
     * @param string $attachmentId The attachment UUID
     * @param string $userId       The caller (must own the source secret)
     *
     * @return void
     *
     * @throws InvalidArgumentException On not found / not authorized
     *
     * @spec openspec/changes/encrypted-attachments/specs/encrypted-attachments/spec.md#requirement-deletion-cascades-and-quota
     */
    public function delete(string $attachmentId, string $userId): void
    {
        $attachment = $this->loadAttachment(attachmentId: $attachmentId);
        $secret     = $this->loadOwnedSecret(secretId: $attachment->getSourceSecretId(), userId: $userId);

        foreach ($this->grantMapper->findByAttachment(attachmentId: $attachmentId) as $grant) {
            $this->grantMapper->delete($grant);
        }

        $this->unlinkBlobIfOrphaned(attachment: $attachment);
        $this->mapper->delete($attachment);

        $this->auditTrail?->forUser(
            actorId: $userId,
            eventType: AuditEventTypes::ATTACHMENT_DELETED,
            objectType: 'attachment',
            objectId: $attachmentId,
            objectName: $secret->getName(),
            metadata: [
                'secretId'  => $attachment->getSourceSecretId(),
                'sizeBytes' => $attachment->getSizeBytes(),
            ],
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
     * @spec openspec/changes/encrypted-attachments/specs/encrypted-attachments/spec.md#requirement-deletion-cascades-and-quota
     */
    public function deleteForSecret(string $sourceSecretId): int
    {
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
     * @spec openspec/changes/encrypted-attachments/specs/encrypted-attachments/spec.md#requirement-sharing-follows-the-secret
     */
    public function deleteGrantsForSecretCopy(string $copySecretId): int
    {
        $removed = 0;
        foreach ($this->grantMapper->findBySecret(secretId: $copySecretId) as $grant) {
            $this->grantMapper->delete($grant);
            ++$removed;

            // Blob GC: a copy-grant delete can orphan a blob only when the
            // attachment row itself is already gone (owner deleted first).
            try {
                $attachment = $this->mapper->findById(id: $grant->getAttachmentId());
                $this->unlinkBlobIfOrphaned(attachment: $attachment, keepWhileRowExists: true);
            } catch (DoesNotExistException) {
                // Attachment row already removed.
            }
        }

        return $removed;
    }//end deleteGrantsForSecretCopy()

    /**
     * Insert a grant row.
     *
     * @param string      $attachmentId   The attachment UUID
     * @param string      $secretId       The copy UUID
     * @param string      $recipientType  The recipient type
     * @param string      $recipientId    The recipient id
     * @param string      $wrappedFileKey The wrapped file key
     * @param string|null $suiteId        The wrapping suite id
     *
     * @return AttachmentGrant
     */
    private function insertGrant(
        string $attachmentId,
        string $secretId,
        string $recipientType,
        string $recipientId,
        string $wrappedFileKey,
        ?string $suiteId,
    ): AttachmentGrant {
        $grant = new AttachmentGrant();
        $grant->setId(Uuid::uuid4()->toString());
        $grant->setAttachmentId($attachmentId);
        $grant->setSecretId($secretId);
        $grant->setRecipientType($recipientType);
        $grant->setRecipientId($recipientId);
        $grant->setWrappedFileKey($wrappedFileKey);
        $grant->setEncryptionSuiteId($suiteId);
        $grant->setCreatedAt(new DateTime());

        return $this->grantMapper->insert($grant);
    }//end insertGrant()

    /**
     * Unlink the blob when no grant references the attachment any more.
     * Never deletes a blob while a grant remains; missing blobs are
     * tolerated (idempotent cascade).
     *
     * @param Attachment $attachment         The attachment row
     * @param bool       $keepWhileRowExists When true, only unlink if the
     *                                       attachment row is ALSO gone
     *                                       (copy-side GC guard)
     *
     * @return void
     */
    private function unlinkBlobIfOrphaned(Attachment $attachment, bool $keepWhileRowExists=false): void
    {
        if ($this->grantMapper->countByAttachment(attachmentId: $attachment->getId()) > 0) {
            return;
        }

        if ($keepWhileRowExists === true) {
            return;
        }

        try {
            $this->blobFolder()->getFile($attachment->getBlobRef())->delete();
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
    private function loadAttachment(string $attachmentId): Attachment
    {
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
     * @param string $userId   The candidate owner
     *
     * @return Secret
     *
     * @throws InvalidArgumentException On missing secret / foreign owner
     */
    private function loadOwnedSecret(string $secretId, string $userId): Secret
    {
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
