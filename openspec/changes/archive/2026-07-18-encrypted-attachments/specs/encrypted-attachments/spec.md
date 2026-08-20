---
status: proposed
---

# Encrypted Attachments

## Purpose

Let a user attach files (license keys, certificates, recovery-code sheets) to a secret, encrypted client-side under the same zero-knowledge model as secret fields: the bytes and filename are encrypted in the browser before upload, the server stores only ciphertext it cannot read, and attachments follow a shared secret to its recipients by re-wrapping a single file key per recipient rather than duplicating the blob.

## ADDED Requirements

### Requirement: Client-side encrypted attachment upload

Doriath SHALL allow the owner of a secret to attach a file whose bytes, filename, and content-type are encrypted in the browser before upload. The server MUST receive and persist only ciphertext (the AES-256-GCM-encrypted blob, the encrypted metadata, and the RSA-wrapped file key) and non-content metadata (ciphertext size, timestamps); it MUST NOT receive the plaintext bytes, the plaintext filename, or the file key.

#### Scenario: Upload stores ciphertext only

- **GIVEN** an owner with an unlocked vault viewing a secret they own
- **WHEN** they upload a file as an attachment
- **THEN** the browser MUST encrypt the file bytes and `{filename, contentType}` with a fresh random AES-256-GCM file key, RSA-wrap that file key under the owner's EncryptionSuite public certificate, and POST only the resulting ciphertext blob, encrypted metadata, and wrapped file key
- **AND** no HTTP request issued by the flow MUST contain the plaintext bytes, the plaintext filename, or the unwrapped file key

#### Scenario: Downloaded attachment is decrypted client-side

- **GIVEN** an attachment on a secret the caller can read, with the vault unlocked
- **WHEN** the caller downloads the attachment
- **THEN** the server MUST return the ciphertext blob only
- **AND** the browser MUST unwrap the file key with the in-session private key, AES-GCM-decrypt the bytes, and deliver the plaintext file as a local download without persisting it to `localStorage`, `sessionStorage`, or IndexedDB

### Requirement: Single-blob envelope with per-recipient key wrapping

Doriath SHALL encrypt an attachment's bytes exactly once under a random file key and store a single ciphertext blob shared across the owner's copy and every recipient copy. Access for each party MUST be represented by a per-copy grant carrying the file key RSA-wrapped under that copy's EncryptionSuite public certificate. The blob MUST NOT be duplicated per recipient.

#### Scenario: Sharing a secret re-wraps the file key, not the blob

- **GIVEN** an owner shares a secret that has an attachment with a recipient who has an active EncryptionSuite
- **WHEN** the share is created
- **THEN** the owner's browser MUST re-wrap the attachment's file key under the recipient's public certificate and POST a new grant for the recipient's copy
- **AND** the ciphertext blob MUST NOT be re-uploaded or duplicated — the recipient's grant MUST reference the existing blob

#### Scenario: Blob removed only when its last grant is gone

- **GIVEN** an attachment blob referenced by grants for the owner and one recipient
- **WHEN** the recipient's share is revoked and their grant is deleted
- **THEN** the physical blob MUST remain because the owner's grant still references it
- **AND** WHEN the owner subsequently deletes the attachment (removing the last grant) THEN the physical blob MUST be removed from storage

### Requirement: Per-attachment size limit and per-user quota

Doriath SHALL enforce an admin-configurable per-attachment maximum size (`attachment_max_bytes`, default 25 MiB) and an admin-configurable per-user storage quota (`attachment_user_quota_bytes`, default 100 MiB), measured in stored ciphertext bytes. Both limits MUST be enforced server-side at upload time; the client MAY surface them but MUST NOT be the authoritative check.

#### Scenario: Oversized attachment rejected

- **GIVEN** an admin has set the per-attachment limit to 25 MiB
- **WHEN** a user uploads an attachment whose ciphertext exceeds 25 MiB
- **THEN** the server MUST reject the upload with an error and persist nothing

#### Scenario: Quota exhaustion rejected

- **GIVEN** a user whose stored attachment ciphertext already totals at or near their quota
- **WHEN** they upload an attachment that would push their total over `attachment_user_quota_bytes`
- **THEN** the server MUST reject the upload with a quota error and persist nothing
- **AND** deleting an existing attachment MUST reclaim the freed bytes so a subsequent upload within quota succeeds

### Requirement: Attachments included in GDPR export and encrypted backup

Doriath SHALL include attachments in the GDPR personal-data export and the encrypted backup export. Attachment metadata and wrapped keys MUST appear in the server-metadata half of the GDPR package; decrypted filenames and file bytes MUST be assembled client-side in the vault half. The encrypted backup MUST carry the attachment blobs and their file keys re-wrapped under the backup KDF key so the backup remains self-contained and suite-independent.

#### Scenario: GDPR export carries attachment metadata and decrypted content

- **GIVEN** an unlocked user with attachments who requests their GDPR data export
- **WHEN** the package is assembled
- **THEN** the server-metadata half MUST list attachment records (id, secret reference, ciphertext size, timestamps) without plaintext content
- **AND** the client-assembled vault half MUST include the decrypted filenames and file bytes
- **AND** no decrypted attachment content MUST appear in any HTTP request

#### Scenario: Encrypted backup restores attachments

- **GIVEN** an encrypted backup produced from a vault containing attachments
- **WHEN** the backup is restored via the import wizard with the correct passphrase
- **THEN** each secret's attachments MUST be reproduced, decryptable from the backup passphrase alone without the original EncryptionSuite

### Requirement: Attachment deletion cascade

Doriath SHALL delete a secret's attachments (grants and reference-counted blobs) whenever the secret is deleted, whenever a containing folder is cascade-deleted, and whenever a user's account data is deleted. Revoking a recipient's share or EncryptionSuite MUST delete that recipient's grants (and any blob whose last grant is thereby removed) while leaving the owner's attachments intact.

#### Scenario: Deleting a secret removes its attachments

- **GIVEN** a secret with one or more attachments
- **WHEN** the owner deletes the secret
- **THEN** all of the secret's attachment grants MUST be deleted
- **AND** every blob whose last grant was removed MUST be deleted from storage
- **AND** the freed bytes MUST be reclaimed against the owner's quota

#### Scenario: Recipient suite revocation removes only their grants

- **GIVEN** a shared secret with an attachment, held by a recipient
- **WHEN** the recipient's EncryptionSuite is revoked
- **THEN** the recipient's attachment grants MUST be deleted
- **AND** the owner's attachment and the underlying blob MUST remain intact

### Requirement: Attachment operations are auditable

Doriath SHALL dispatch typed events for attachment upload, download, and deletion via `OCP\EventDispatcher`, carrying only non-sensitive identifiers so the audit-trail capability can persist them. Event payloads MUST NEVER contain the plaintext filename, the file key, or file content.

#### Scenario: Upload dispatches a typed event with no content

- **GIVEN** an attachment is uploaded
- **WHEN** the upload completes
- **THEN** an `AttachmentUploadedEvent` MUST be dispatched carrying only the attachment id, secret id, ciphertext size, and timestamp — never the plaintext filename, file key, or file bytes
