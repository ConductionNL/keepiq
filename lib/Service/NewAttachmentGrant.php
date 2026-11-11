<?php

/**
 * Keepiq New Attachment Grant
 *
 * One per-recipient grant row to insert for an attachment.
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
use OCA\Keepiq\Db\AttachmentGrant;
use Ramsey\Uuid\Uuid;

/**
 * The attachment, the secret (or copy) and the recipient a grant row gives
 * the wrapped file key to, with the suite it was wrapped under.
 *
 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-single-blob-envelope-with-per-recipient-key-wrapping
 */
final class NewAttachmentGrant {
	/**
	 * Constructor for NewAttachmentGrant.
	 *
	 * @param string $attachmentId The attachment UUID
	 * @param string $secretId The secret or copy UUID
	 * @param string $recipientType The recipient type
	 * @param string $recipientId The recipient id
	 * @param string $wrappedFileKey The wrapped file key
	 * @param string|null $suiteId The wrapping suite id
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $attachmentId,
		public readonly string $secretId,
		public readonly string $recipientType,
		public readonly string $recipientId,
		public readonly string $wrappedFileKey,
		public readonly ?string $suiteId,
	) {
	}//end __construct()

	/**
	 * The grant row, with a fresh id and creation time.
	 *
	 * @return AttachmentGrant
	 *
	 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-single-blob-envelope-with-per-recipient-key-wrapping
	 */
	public function toRow(): AttachmentGrant {
		$grant = new AttachmentGrant();
		$grant->setId(Uuid::uuid4()->toString());
		$grant->setAttachmentId($this->attachmentId);
		$grant->setSecretId($this->secretId);
		$grant->setRecipientType($this->recipientType);
		$grant->setRecipientId($this->recipientId);
		$grant->setWrappedFileKey($this->wrappedFileKey);
		$grant->setEncryptionSuiteId($this->suiteId);
		$grant->setCreatedAt(new DateTime());

		return $grant;
	}//end toRow()
}//end class
