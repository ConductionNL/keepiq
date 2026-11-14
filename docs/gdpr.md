<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->

# Data export and GDPR rights

Keepiq is end-to-end encrypted (ADR-003): the server holds only ciphertext and
never sees a master password or a plaintext secret. That shapes how export,
GDPR access (Art. 15), and erasure (Art. 17) work — the readable parts are
produced in the browser, and deletion is a server-side cascade with a defined
blast radius for shared data.

## Export

Two user-initiated, one-shot export modes, both generated entirely in the
browser. Neither sends plaintext, a passphrase, or a derived key to the server.

### Encrypted backup (`.doriath-backup`)

- The browser decrypts the selected secrets, serializes secrets + folder paths
  to a versioned JSON payload, derives an AES-256 key from a user-chosen
  **backup passphrase** via Argon2id, and encrypts the payload with AES-256-GCM.
- The passphrase must meet a zxcvbn ≥ 3 strength floor. **Write it down** — a
  backup must survive a lost master password, so it is decryptable from the
  passphrase alone, independent of your encryption suite.
- The envelope stores its own KDF parameters and salt, so a future parameter
  bump never breaks old backups.
- Restore goes through the import wizard's backup-restore parser: passphrase
  prompt → client-side decrypt → the standard mapping/duplicate/commit steps.

### Plaintext CSV

- Columns: `name,url,login,password,notes,folder,type,totp` (RFC 4180), round-trippable
  through the generic CSV import. `totp` holds a login's one-time code; a login's passkeys have no column and are not in the CSV.
- **Gated twice**: an explicit "this file is unencrypted, delete it after use"
  warning that must be acknowledged, then **fresh master-password re-entry**
  even on an unlocked session. Re-entry is verified client-side by decrypting
  the stored private-key blob; the password never leaves the browser. This
  blocks the unattended-unlocked-session dump without weakening E2E.

### Scope

Every export mode (encrypted backup, plaintext CSV and CXF) can be limited to:

- **Entire vault** — every secret you can export.
- **Chosen folders and types** — one or more folders, each with its
  subfolders, and one or more secret types. With both chosen, only secrets
  that are in a chosen folder *and* of a chosen type are exported.
- **The selected secrets** — select secrets in the vault list and choose
  **Export selected** in the selection strip. The dialog opens on exactly that
  selection; it is not combined with folders or types.

Scope never changes the security gating of the chosen mode: a plaintext CSV
of three secrets still needs the warning and the master password.

### Leaving fields out

For the encrypted backup and the plaintext CSV you can leave out
**usernames**, **additional fields** or **one-time code seeds**. A secret's
name and value are always exported, because a backup without values is not a
backup. A backup with fields left out is marked as *partial* inside its
encrypted payload, and restoring it says which fields are missing before
anything is restored.

### Secrets that could not be decrypted

A secret under a revoked or blocked encryption suite cannot be decrypted in
the browser, so it cannot be exported. The dialog says how many secrets are
not in the export and asks you to continue without them before any file is
written.

### Types in a backup

Each secret in a backup carries its type's name and, for a custom type, its
label. That is what lets a backup restore with its types on another account
or instance; see [Restoring a Keepiq backup](importing.md#restoring-a-keepiq-backup).

## GDPR data export (Art. 15, right of access)

One machine-readable `keepiq-gdpr-export.json` package, assembled in the
browser from two halves:

- **Server metadata** (`GET /api/v1/gdpr/metadata`, session user only):
  encryption-suite records (certificate, status, audit fields — the encrypted
  private-key blob is **excluded**, with the exclusion documented inside the
  package), shares given/received (each flagged `quarantined`, quarantined share rows included), delegations, link-share metadata (no
  snapshots; the links the user holds and, flagged `handedOver`, the links they made that team offboarding handed to someone else), secret requests, user settings, and per secret the user's
  favourite star, tags and last-used time (`organisation`).
- **Client vault**: the decrypted secrets + folder structure.

If the vault is locked, the package is still produced with the server half only,
and the vault section states explicitly that the end-to-end encrypted vault was
not unlocked — the honest Art. 15 answer under E2E. The package downloads
locally and is never stored server-side.

## Account data deletion (Art. 17, right to erasure)

Two triggers run the same ordered, idempotent cascade
(`AccountDeletionService::deleteAllFor`):

- **In-app** (`DELETE /api/v1/gdpr/account-data`): gated by master-password
  re-entry (client-side proof of knowledge) **and** a typed confirmation phrase
  (`DELETE MY KEEPIQ DATA`). Deletes Keepiq data; the Nextcloud account
  remains.
- **Automatic**: a `UserDeletedEvent` listener runs the cascade when the
  Nextcloud account is removed, so Keepiq data never outlives its account.

### Shared-secret semantics

- **Delegated secrets — ownership transfer.** A secret with an active
  delegation transfers to the delegate: the delegation becomes permanent and
  the delegate becomes owner. Delegation is Keepiq's existing, explicit
  successor mechanism; deletion respects it rather than silently picking an heir.
  The link shares the deleted user holds on such a secret are deleted, not passed to the delegate.
- **Granted shares — detach with tombstone.** Each recipient's copy is already a
  full secret encrypted under the recipient's own suite. The share link is
  deleted (sync severed) and the copy is marked `tombstoned_at` +
  `tombstone_reason = 'owner-account-deleted'`. **No ID, display name, or other
  personal data of the deleted user is retained on the recipient copy.** The UI
  shows it as "shared by a deleted account — no longer synced". Tombstone fields
  are display metadata only and never restrict access.
- **Received shares — removed.** Copies in the deleted user's own vault and their
  links are hard-deleted; the original owners' secrets are untouched.

Link shares, secret requests, own secrets and folders, encryption suites
(including the encrypted private keys) and their migration records, and user
settings are all removed.
Link shares the user made that team offboarding handed to someone else stay with whoever holds them; the user's ID on them is replaced by a deleted-account marker that is not a valid Nextcloud user ID, so the holder's compromise revoke still leaves them.
Every step is idempotent and keyed by user ID, so an
interrupted cascade can be safely re-run; the completion event is emitted only
on a finished run.

## Audit events (emit-now, store-later)

Export and deletion dispatch typed events via `OCP\EventDispatcher`:
`SecretExportedEvent`, `GdprExportPerformedEvent`, `AccountDataDeletedEvent`.
Payloads carry **counts and modes only — never secret names, values, or
ciphertext**, and the deletion tombstones carry no personal data of the deleted
user.

This change is scoped to **event emission**; storage, retention, and admin UI
belong to the audit-trail feature, whose listener consumes these events. Because
export runs client-side, the server only learns of an export when the browser
reports it before offering the download — honest-client accountability for the
supported UI flows. A tampered client can already read every secret through the
normal API, so client-reported events cover the UI surface, which is what a UI
audit can ever promise under E2E.
