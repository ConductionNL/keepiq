---
kind: code
---

# Proposal: Encrypted file attachments on secrets

## Why

A secret is frequently more than a string. License keys arrive as `.lic` files, TLS material as `.pem`/`.pfx`, recovery-code sheets and 2FA backup codes as PDFs — the credential and its file belong together. Doriath cannot store any of them today: `docs/FEATURES.md` runs a full competitive matrix and roadmap and mentions attachments **nowhere** (`grep -in "attach\|file upload" docs/FEATURES.md` returns nothing), and no attachment symbol exists in the codebase (`lib/Db/` has no attachment entity; the migration set stops at `Version000018Date20260707000000.php`).

Encrypted attachments are 2026 table stakes for self-hosted vaults:

- **Vaultwarden** (`docs/FEATURES.md:25`, 42K+ stars) ships attachments for free in a single binary — the exact "lightweight Bitwarden-compatible" tier Doriath competes with.
- **Psono** (`docs/FEATURES.md:28`, "Enterprise team password manager") lists **file encryption** as a headline feature; Passman (the incumbent Nextcloud password app) supports file fields.
- **Nextcloud Passwords** — the app Doriath positions against (`docs/FEATURES.md:5`, "The existing 'Passwords' app… lacks enterprise-grade encryption") — has had attachment support open as issue #176 **since 2019** and still lacks it, and its importer silently drops attachments on migration. This is a concrete, durable gap a Nextcloud-native vault can win on.

The feature is also a pure fit for Doriath's model: attachment content is just another blob to encrypt client-side and store as ciphertext the server cannot read, and re-sharing an attachment is the same per-recipient key-wrapping the `user-sharing` spec already performs for secret fields (`openspec/specs/user-sharing/spec.md:82-89`). No new cryptographic primitive is required — only a blob envelope layered on the existing RSA/AES suite (ADR-003).

No existing OpenSpec change covers this (checked all active changes' `proposal.md` "Why"/"What Changes"; none mention attachments, blobs, or file upload).

## What Changes

- Add **encrypted attachments** on a secret: a user uploads one or more files against a secret they own. The browser encrypts each file's bytes **before upload** with a fresh random AES-256-GCM **file key**; the server only ever receives and stores the ciphertext blob (which it cannot read) plus non-content metadata.
- Adopt an **envelope model** (not per-recipient blob duplication): the file's bytes are encrypted **once** under the random file key; that file key is RSA-wrapped under the owner's EncryptionSuite public certificate. The filename and content-type are themselves AES-GCM-encrypted under the same file key, so the server sees no plaintext filename either. This mirrors — but is cheaper than — the `user-sharing` per-recipient full-copy model: only the small wrapped key and metadata are per-recipient, never the (potentially large) blob.
- Store **ciphertext at rest in Nextcloud app data** (`OCP\Files\IAppData`), with attachment metadata + wrapped file keys in Doriath's **own database tables** (ADR-001). The blob is content-addressed and deduplicated across an owner's copy and every recipient copy; a reference count over the wrapped-key grants decides when the physical blob is removed.
- Enforce a **per-attachment size limit** and a **per-user storage quota**, both admin-configurable (`attachment_max_bytes`, default 25 MiB; `attachment_user_quota_bytes`, default 100 MiB), checked server-side on upload against the sum of the user's stored ciphertext sizes.
- **Include attachments in sharing**: when a secret is shared (and on the existing sync-on-update fan-out), the owner's browser re-wraps each attachment's file key under the recipient's active EncryptionSuite public key and POSTs the resulting grant — the blob itself is never re-uploaded. Revoking a share / recipient suite revocation removes that recipient's grant (and the blob once its last grant is gone).
- **Include attachments in GDPR export and encrypted backup export**: attachment metadata and wrapped keys travel in the server-metadata half of the GDPR package (`openspec/specs/gdpr-compliance/spec.md:6-12`); decrypted filenames and file bytes travel in the client-assembled vault half. The encrypted-backup payload (`openspec/specs/secret-export/spec.md:6-10`) carries the attachment blobs and their file keys re-wrapped under the backup KDF key so a backup is self-contained and suite-independent.
- **Deletion cascade**: deleting a secret deletes its attachment grants and — via the grant reference count — the physical blobs; deleting a single attachment removes its grant and reclaims quota. Account-deletion and folder-cascade deletion inherit this cascade (`openspec/specs/gdpr-compliance/spec.md:37`).
- Dispatch **typed audit events** (`AttachmentUploadedEvent`, `AttachmentDownloadedEvent`, `AttachmentDeletedEvent`) via `OCP\EventDispatcher`, carrying only non-sensitive identifiers (attachment id, secret id, ciphertext size, timestamps) — never filename plaintext, file key, or content — so the separately-specced `secret-audit-trail` change can persist them exactly as it does emergency-access and export events.
- **Out of scope for v1**: inline preview/thumbnailing of attachment content (would require server-side decryption — forbidden by ADR-003); virus scanning of ciphertext (server cannot read it); attachments on link shares and secret requests (those are point-in-time / write-only flows — deferred).

## Impact

- **New DB tables**: `doriath_attachments` (blob + encrypted metadata), `doriath_attachment_grants` (per-copy wrapped file key). New migration.
- **New service**: `AttachmentService` (upload, list, download-blob, delete, quota accounting, share re-wrap hook, cascade). New `AttachmentController` + routes.
- **Modified**: `SecretService` delete + folder-cascade + `AccountDeletionService` cascade gain an attachment step; `ShareService` sync-on-update gains an attachment re-wrap step; `SettingsService` gains the two config keys; GDPR and export flows gain the attachment sections.
- **Frontend**: attachment panel on the secret detail view (WebCrypto encrypt-before-upload / download-then-decrypt), admin settings inputs for the two limits.
- **Storage**: `IAppData` folder for ciphertext blobs.
- No OpenConnector impact (application vaults may hold attachments via the same owner-scoped path but no new machine HTTP surface is added).
