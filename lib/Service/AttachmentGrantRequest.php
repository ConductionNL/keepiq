<?php

/**
 * Keepiq Attachment Grant Request
 *
 * The owner's request to give a share recipient's copy access to an attachment.
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

use InvalidArgumentException;

/**
 * The attachment, the caller who owns its source secret, and the recipient
 * copy with the file key re-wrapped for it.
 *
 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-single-blob-envelope-with-per-recipient-key-wrapping
 */
final class AttachmentGrantRequest {
	/**
	 * Constructor for AttachmentGrantRequest.
	 *
	 * @param string $attachmentId The attachment UUID
	 * @param string $userId The caller (must own the source secret)
	 * @param string $copySecretId The recipient's Secret copy UUID
	 * @param string $recipientId The recipient user/application ID
	 * @param string $wrappedFileKey The file key wrapped under the recipient's cert
	 * @param string $recipientType The recipient type (`user`|`application`)
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $attachmentId,
		public readonly string $userId,
		public readonly string $copySecretId,
		public readonly string $recipientId,
		public readonly string $wrappedFileKey,
		public readonly string $recipientType = 'user',
	) {
	}//end __construct()

	/**
	 * Refuse a request without a copy, recipient or wrapped key, or for a
	 * recipient type other than user or application.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the request is incomplete or the type unknown
	 *
	 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-single-blob-envelope-with-per-recipient-key-wrapping
	 */
	public function assertValid(): void {
		if ($this->copySecretId === '' || $this->recipientId === '' || $this->wrappedFileKey === '') {
			throw new InvalidArgumentException('copySecretId, recipientId, and wrappedFileKey are required');
		}

		if (in_array($this->recipientType, ['user', 'application'], true) === false) {
			throw new InvalidArgumentException('recipientType must be user or application');
		}
	}//end assertValid()
}//end class
