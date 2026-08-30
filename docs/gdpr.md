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

- Columns: `name,url,login,password,notes,folder,type` (RFC 4180), round-trippable
  through the generic CSV import.
- **Gated twice**: an explicit "this file is unencrypted, delete it after use"
  warning that must be acknowledged, then **fresh master-password re-entry**
  even on an unlocked session. Re-entry is verified client-side by decrypting
  the stored private-key blob; the password never leaves the browser. This
  blocks the unattended-unlocked-session dump without weakening E2E.

### Scope

Both modes export the whole vault or a selected folder subtree. Scope never
changes the security gating of the chosen mode.

## GDPR data export (Art. 15, right of access)

One machine-readable `keepiq-gdpr-export.json` package, assembled in the
browser from two halves:

- **Server metadata** (`GET /api/v1/gdpr/metadata`, session user only):
  encryption-suite records (certificate, status, audit fields — the encrypted
  private-key blob is **excluded**, with the exclusion documented inside the
  package), shares given/received, delegations, link-share metadata (no
  snapshots), secret requests, and user settings.
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
settings are all removed. Every step is idempotent and keyed by user ID, so an
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
