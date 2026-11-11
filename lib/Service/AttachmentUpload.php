<?php

/**
 * Keepiq Attachment Upload
 *
 * One client-encrypted attachment a user uploads against a secret they own.
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

/**
 * The ciphertext, its encrypted metadata and the owner's wrapped file key for
 * one upload against one secret.
 *
 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-client-side-encrypted-attachment-upload
 */
final class AttachmentUpload {
	/**
	 * Constructor for AttachmentUpload.
	 *
	 * @param string $secretId The owning secret UUID
	 * @param string $userId The caller (must own the secret)
	 * @param string $blob The ciphertext bytes
	 * @param string $encryptedMetadata The AES-GCM metadata ciphertext
	 * @param string $wrappedFileKey The owner's RSA-wrapped file key
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $secretId,
		public readonly string $userId,
		public readonly string $blob,
		public readonly string $encryptedMetadata,
		public readonly string $wrappedFileKey,
	) {
	}//end __construct()

	/**
	 * Whether the blob, the metadata and the wrapped key are all present.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-client-side-encrypted-attachment-upload
	 */
	public function isComplete(): bool {
		return $this->blob !== '' && $this->encryptedMetadata !== '' && $this->wrappedFileKey !== '';
	}//end isComplete()
}//end class
