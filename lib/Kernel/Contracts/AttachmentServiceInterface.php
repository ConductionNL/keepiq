<?php

/**
 * Contract of AttachmentService
 *
 * The public surface of AttachmentService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Keepiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/keepiq
 *
 * @spec openspec/specs/app-shell/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Kernel\Contracts;

use InvalidArgumentException;
use OCA\Keepiq\Db\Attachment;
use OCA\Keepiq\Db\AttachmentGrant;
use OCA\Keepiq\Service\AttachmentGrantRequest;
use OCA\Keepiq\Service\AttachmentUpload;

/**
 * Contract of AttachmentService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AttachmentServiceInterface {

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
	public function addGrant(AttachmentGrantRequest $request): AttachmentGrant;

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
	public function delete(string $attachmentId, string $userId): void;

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
	public function downloadBlob(string $attachmentId, string $userId): string;

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
	public function listForSecret(string $secretId, string $userId): array;

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
	public function upload(AttachmentUpload $upload): array;
}//end interface
