# Design: Encrypted Attachments

## Context

Doriath is zero-knowledge end-to-end (ADR-003): the server stores only ciphertext and never decrypts with entity context. Attachments must preserve that invariant — the server may broker where a file's bytes live and who holds a wrapped key, but must never see the plaintext bytes, the plaintext filename, or the file key. Doriath owns its own database tables and does not use OpenRegister (ADR-001), so attachment metadata is modelled as first-class Doctrine entities with a Nextcloud migration.

The existing `user-sharing` model gives every recipient a **full re-encrypted `Secret` row** and keeps copies in sync by re-encrypting per recipient on every update (`openspec/specs/user-sharing/spec.md:76-89`). Applying that literally to a 25 MiB attachment would mean re-uploading and storing the whole file once per recipient — wasteful. This design keeps the *blob* single-instance and makes only the *key* per-recipient.

## Goals / Non-Goals

**Goals:**
- Upload/download of files against a secret, encrypted client-side, ciphertext-only at rest.
- Attachments follow a shared secret to its recipients using the existing per-recipient key-wrapping seam — without duplicating blob storage.
- Attachments appear in GDPR export, encrypted backup, and every deletion cascade.
- Admin-configurable per-attachment size limit and per-user quota, enforced server-side.
- Reuse the existing RSA/AES suite and event/audit plumbing; introduce no new cryptographic primitive.

**Non-Goals:**
- Server-side preview, thumbnailing, indexing, or malware scanning of attachment content (impossible under ADR-003 without a decrypt path).
- Attachments on link shares or secret requests (point-in-time / write-only surfaces — deferred).
- Streaming/resumable upload of very large files (v1 caps size; a single POST is sufficient at ≤25 MiB default).

## Data Model

Two own tables (ADR-001). Blob bytes live in Nextcloud app data, not in the DB.

### `doriath_attachments` — one row per uploaded file (deduplicated across copies)

| Field | Type | Encrypted | Notes |
|-------|------|-----------|-------|
| `id` | UUID | No | Primary key |
| `source_secret_id` | FK | No | The owner's canonical `Secret` this file was uploaded against |
| `blob_ref` | string | No | Locator into the `IAppData` folder holding the AES-GCM ciphertext |
| `encrypted_metadata` | text | Yes | AES-256-GCM ciphertext of `{ filename, contentType }` under the file key |
| `size_bytes` | bigint | No | Ciphertext byte length — used for quota accounting (observable anyway) |
| `created_at` | datetime | No | |
| `updated_at` | datetime | No | |

The filename is **encrypted** (a filename like `aws-root-recovery-codes.pdf` is itself sensitive). Because the Doriath UI gates all routes behind the unlock screen, listing an attachment's name always happens with the file key unwrappable in-session — no plaintext filename is ever needed server-side.

### `doriath_attachment_grants` — per-copy wrapped file key (owner + each recipient)

| Field | Type | Encrypted | Notes |
|-------|------|-----------|-------|
| `id` | UUID | No | Primary key |
| `attachment_id` | FK | No | The `doriath_attachments` blob this grant unlocks |
| `secret_id` | FK | No | The specific `Secret` copy this grant belongs to (owner's copy or a recipient's share copy) |
| `recipient_type` | enum | No | `user` or `application` |
| `recipient_id` | string | No | Nextcloud user id or application id holding this grant |
| `wrapped_file_key` | text | Yes | The random AES file key, RSA-wrapped under this copy's EncryptionSuite public certificate |
| `encryption_suite_id` | FK | No | Which suite wrapped this grant's key — so a suite revocation/migration knows what to touch |
| `created_at` | datetime | No | |

A physical blob is removed when its **last grant** is deleted (reference count over `attachment_grants.attachment_id`). Deleting the source secret removes all its grants and therefore all its blobs.

## Endpoints

All authenticated, `#[NoAdminRequired]`, per-object authorization in the method body (upload/list/delete scoped to the owning secret; download scoped to a caller who holds a grant) — no IDOR, matching the `hydra-gate-no-admin-idor` posture.

- `POST /api/v1/secrets/{secretId}/attachments` — multipart body: ciphertext blob + `encrypted_metadata` + owner's `wrapped_file_key` + `size_bytes`. Server enforces `attachment_max_bytes` and the user's remaining `attachment_user_quota_bytes` before persisting, stores the blob in `IAppData`, writes the `doriath_attachments` row and the owner grant, dispatches `AttachmentUploadedEvent`.
- `GET /api/v1/secrets/{secretId}/attachments` — returns attachment metadata (`id`, `encrypted_metadata`, `size_bytes`, `created_at`) **plus the caller's own `wrapped_file_key`** for each attachment (only the grant addressed to the caller). No blob bytes.
- `GET /api/v1/attachments/{id}/blob` — streams the ciphertext blob; authorized only if the caller holds a grant for `{id}`. Dispatches `AttachmentDownloadedEvent`.
- `DELETE /api/v1/attachments/{id}` — owner-only; deletes all grants + the blob, reclaims quota, dispatches `AttachmentDeletedEvent`.
- Sharing re-wrap is not a new public endpoint: it rides the existing share/sync flow — the owner's browser fetches the recipient's public certificate, re-wraps each attachment's file key, and POSTs the new grants alongside the recipient's `Secret` copy creation (`POST /api/v1/secrets/{secretId}/attachments/{id}/grants`).

## Frontend surfaces

- **Attachment panel** on the secret detail view (Vue 2 + Pinia): drag-and-drop / file-picker upload → browser generates a random AES-256-GCM key, encrypts bytes and `{filename,contentType}`, RSA-wraps the key under the owner's public cert (WebCrypto, same module as secret-field encryption), POSTs. List shows decrypted filename + human size + download/delete. Download fetches the blob, unwraps the file key with the in-session `CryptoKey`, AES-GCM-decrypts, and triggers a local save — plaintext never touches storage.
- **Admin settings** (per `implement-dashboard-settings` conventions): numeric inputs for per-attachment size limit and per-user quota, with the server as the authoritative validator.
- **Share dialog**: when adding a recipient to a secret that has attachments, the browser performs the per-attachment re-wrap transparently, reusing the same recipient-public-cert fetch the secret-field share already does.

## Declarative-vs-imperative decision

Imperative PHP services and controllers over Doriath's own tables (ADR-001) — Doriath does not use OpenRegister, so there is no declarative object/register/schema seed. Attachment metadata is a Doctrine entity + `QBMapper`; blob bytes are files in `IAppData`. No OpenRegister seed data.

## Risks / Trade-offs

- **Blob orphaning on interrupted deletes.** If a grant delete succeeds but the blob unlink fails, a zero-grant blob could linger. → Mitigation: a periodic reconciliation in the delete path (and a guard that never deletes a blob while any grant references it); the cascade is idempotent so re-running is safe.
- **Quota is a ciphertext-byte count, not a decrypted-size count.** AES-GCM adds a small constant overhead; the admin limit is expressed and enforced in stored (ciphertext) bytes, which is what actually consumes disk. Documented so admins size limits against real storage.
- **Stale wrapped keys after suite migration.** A recipient's grant is a file key wrapped under a suite that compromise-recovery may rotate. → Mitigation: suite migration re-wraps attachment grants for the migrated copies exactly as it re-encrypts secret fields, and flags unresolved grants the same way secrets carry `migration_error`.
- **Large-vault share fan-out cost.** Re-wrapping only the (tiny) file key per recipient is cheap; the blob is untouched. This is strictly cheaper than the existing per-recipient full-secret-copy model, so it adds no new performance class.

## Decisions made under uncertainty

- **Storage backend = Nextcloud app data (`IAppData`), not a DB blob column.** Ciphertext bytes are stored as files under a Doriath `IAppData` folder; only metadata + wrapped keys live in the DB. Rationale: keeps large binaries out of Postgres, uses Nextcloud's native file storage (works with primary/object storage backends), and the DB stays queryable and small. *Uncertainty:* a DB `bytea` column would be simpler to back up atomically with the metadata; revisit if operational backup coupling proves painful.
- **Envelope (blob-once, key-per-recipient), not per-recipient blob duplication.** Chosen for storage efficiency and to keep sync-on-update cheap. *Uncertainty:* it means one physical blob is shared across recipients via reference-counted grants rather than being fully independent per recipient as secret-field copies are; if a future requirement demands truly independent per-recipient blobs (e.g. per-recipient watermarking), this must change.
- **Filename + content-type are encrypted** (under the file key), not stored plaintext like secret `name`/`url`. Rationale: filenames leak intent; the unlock-gated UI never needs them server-side. *Uncertainty:* this forecloses any future server-side filename search — judged acceptable (the parent secret's plaintext name/url remain searchable).
- **Default limits: 25 MiB per attachment, 100 MiB per user.** Picked to match Vaultwarden's common defaults and keep single-POST uploads comfortable. Both admin-configurable; revisit defaults after real usage.
- **v1 scopes attachments to secrets only** (not link shares / secret requests). Rationale: those surfaces are point-in-time or write-only and would each need their own key-delivery design. Deferred, not refused.
