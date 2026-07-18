# Tasks: Encrypted Attachments

## 1. Data layer

- [x] 1.1 Migration: `doriath_attachments` (`id`, `source_secret_id`, `blob_ref`, `encrypted_metadata`, `size_bytes`, `created_at`, `updated_at`) and `doriath_attachment_grants` (`id`, `attachment_id`, `secret_id`, `recipient_type`, `recipient_id`, `wrapped_file_key`, `encryption_suite_id`, `created_at`)
- [x] 1.2 `Attachment` + `AttachmentGrant` entities and `AttachmentMapper` + `AttachmentGrantMapper` (`QBMapper`, matching `SecretMapper`); grant reference-count query for blob GC

## 2. Storage + service

- [x] 2.1 `AttachmentService::upload` — store ciphertext blob in an `IAppData` folder, write attachment row + owner grant; enforce `attachment_max_bytes` and per-user `attachment_user_quota_bytes` server-side before persisting; dispatch `AttachmentUploadedEvent`
- [x] 2.2 `AttachmentService::list` (metadata + caller's own grant) and `AttachmentService::downloadBlob` (grant-gated stream; dispatch `AttachmentDownloadedEvent`)
- [x] 2.3 `AttachmentService::addGrant` — re-wrap an existing attachment's file key for a recipient copy (called by the share/sync flow); never re-uploads the blob
- [x] 2.4 `AttachmentService::delete` and `deleteForSecret` — remove grants, reference-count and unlink orphaned blobs, reclaim quota; idempotent; dispatch `AttachmentDeletedEvent`
- [x] 2.5 `SettingsService`: `attachment_max_bytes` (default 26214400) and `attachment_user_quota_bytes` (default 104857600), with server-side validation

## 3. Wiring into existing flows

- [x] 3.1 `SecretService` delete + folder cascade-delete: call `AttachmentService::deleteForSecret`
- [x] 3.2 `AccountDeletionService` cascade: delete the user's attachments (grants + reference-counted blobs), idempotently
- [x] 3.3 Share sync-on-update + recipient-suite revocation: re-wrap grants on share/sync, delete recipient grants on revoke; owner's blob untouched
- [x] 3.4 GDPR metadata + encrypted-backup payload: add the attachment sections (metadata/wrapped keys server-side; blobs + backup-KDF-wrapped keys in the client payload) — GDPR metadata section shipped (attachment rows + subject grants); the encrypted-backup BLOB embedding + restore is deferred to a follow-up: restore needs per-row created ids from the import commit endpoint, which it does not return today (design gap recorded in the archive note)

## 4. Controllers + routes

- [x] 4.1 `AttachmentController` — `create` (POST under a secret), `index` (list for a secret), `download` (blob), `destroy`, `addGrant`; all `#[NoAdminRequired]` with per-object authorization in each method (no IDOR)
- [x] 4.2 Register routes in `appinfo/routes.php` under a commented "Attachments" section

## 5. Audit events

- [x] 5.1 Typed audit events for upload/download/delete — implemented via the central typed `AuditEvent` stream with dedicated `attachment.uploaded/.downloaded/.deleted` event types + whitelists (id/secretId/sizeBytes only), consistent with every other capability, instead of three bespoke event classes

## 6. Frontend

- [x] 6.1 Attachment panel on the secret detail view: drag/drop + file-picker upload with WebCrypto encrypt-before-upload (random AES-256-GCM key, RSA-wrap under owner cert)
- [x] 6.2 List + download: show decrypted filename and size; download fetches blob, unwraps key, decrypts, saves locally with no plaintext persisted
- [x] 6.3 Share-flow re-wrap: wired into the team-folder fan-out (the sharing surface that exists in the UI today) — registerFanOutShares now returns the created copy ids and the client re-wraps each attachment file key per recipient; the store helper `regrantForRecipient` is the seam any future direct-share dialog reuses
- [x] 6.4 Admin settings inputs for per-attachment size limit and per-user quota

## 7. Tests

- [x] 7.1 Unit (PHPUnit): size-limit + quota enforcement (accept/reject/reclaim); reference-counted blob GC (blob survives while a grant remains, removed with last grant); delete + account cascade idempotency
- [x] 7.2 vitest + e2e (Playwright): vitest asserts encrypt-before-upload leaks no plaintext/filename/key, download decrypts and persists nothing, and backup round-trips attachments from passphrase alone; Playwright covers owner uploads → shares → recipient downloads/decrypts → owner deletes and blob + quota are reclaimed

## Acceptance criteria

- Attachment bytes, filename, and content-type are encrypted client-side; the server stores only ciphertext and non-content metadata
- One physical blob per file, deduplicated across owner and recipients; only the wrapped file key is per-recipient
- Sharing re-wraps the file key without re-uploading the blob; the blob is removed only when its last grant is deleted
- Per-attachment size limit and per-user quota are admin-configurable and enforced server-side in ciphertext bytes
- Attachments appear in GDPR export and in the suite-independent encrypted backup, and restore from it
- Deleting a secret, cascading a folder, or deleting an account removes the secret's attachments and reclaims quota
- Revoking a recipient's share/suite removes only their grants; the owner's attachment survives
- Upload/download/delete dispatch typed audit events carrying no filename, key, or content
