# Design: scheduled vault backups

## Context

Read at development `4c214a9d`.

- `lib/` has no `Command` directory and `appinfo/info.xml` declares no `<commands>`: Keepiq has no `occ` command yet.
- `appinfo/info.xml:110` to `:123` lists twelve background jobs. `lib/BackgroundJob/PurgeAuditLogJob.php:44` shows the `TimedJob` pattern (`setInterval()` at `:62`).
- `lib/Migration/Version001000Date20260908000000.php:53` lists the 33 Keepiq tables in a private `TABLES` constant.
- `lib/Service/AttachmentService.php:44` stores attachment ciphertext blobs in `IAppData` under the folder `attachments` (`:60`), namespace `keepiq` (`:69`).
- `lib/Service/CertificateAuthorityService.php:66` encrypts the CA private keys with Nextcloud's `ICrypto`, which is keyed to the instance `secret` in `config.php`. `lib/Db/SiemSink.php:109` stores the SIEM HMAC secret the same way.
- ADR-003: names and URLs are stored plain so search works; secret fields are RSA ciphertext; private keys are AES-wrapped with a key derived from the master password.
- The per-user encrypted export (`openspec/specs/secret-export/spec.md`, "Encrypted Backup Export"; `src/store/modules/export.js:86`) runs in the browser, by hand, for one vault.

## Goals / Non-Goals

**Goals:**

- A complete, restorable copy of every Keepiq vault on a schedule, without a Nextcloud-wide restore.
- An archive that proves its own integrity before a restore touches the database.
- An option to make archives unreadable on the server itself.

**Non-Goals:**

- Restoring one user's vault into a live instance. Shares, team folders and delegations link vaults; a partial restore would break those links. It can follow as its own change.
- Any plaintext in a backup. The server has none to write.
- Off-site transport. Administrators copy archives with their existing backup tooling; `keepiq:backup:list` prints the path.
- Web download of archives (see D6).

## Decisions

### D1: One archive per run, every table and every blob

`BackupTableRegistry` lists the 33 tables. A PHPUnit test reads the migration's `TABLES` constant by reflection and fails when the two lists differ, so a new table can never be left out silently. The archive is a zip (`ext-zip` is a Nextcloud requirement) with `manifest.json`, `tables/<table>.jsonl` (one row per line, written as rows are read) and `blobs/<attachment id>`. The manifest carries the format `keepiq-vault-backup-v1`, the app version, the schema fingerprint (sorted table and column names), the creation time, the instance id, and per file the row count and SHA-256.

Alternative considered: a SQL dump per table. Rejected: the dump dialect differs across PostgreSQL, MySQL and SQLite, all three of which Keepiq supports.

### D2: Optional encryption to an administrator-held public key

The admin settings accept a PEM certificate or public key (`backup_recipient_public_key`). When set, the writer streams the zip through segmented AES-256-GCM (1 MiB segments, a nonce and tag per segment) under a random content key, and wraps that key with RSA-OAEP-SHA256 under the recipient key, the same primitives ADR-003 uses. The private key stays with the administrator; `restore` and `verify` take it with `--key-file`. Without a key, the archive holds what a database dump holds.

Alternative considered: encrypt with Nextcloud's `ICrypto`. Rejected: the key sits in `config.php` on the same server, so it protects nothing an attacker on that server cannot read.

### D3: A timed job with an administrator schedule

`ScheduledVaultBackupJob` is a `TimedJob` that wakes hourly (`TIME_INSENSITIVE`) and runs when `backup_interval_hours` (default 24, minimum 1) has passed since `backup_last_run_at`. It is off until `backup_enabled` is true. Archives go to the `backups` folder in `IAppData`; the oldest beyond `backup_retention_count` (default 7) are removed after a successful run. The job records `backup_last_status` and `backup_last_error` in app config.

### D4: Four occ commands

| Command | Does |
|---|---|
| `keepiq:backup:create` | Runs a backup now, same code as the job |
| `keepiq:backup:list` | Name, size, time, encrypted or not, path on disk |
| `keepiq:backup:verify <file> [--key-file=]` | Decrypts if needed, checks every checksum and the format |
| `keepiq:backup:restore <file> [--key-file=] [--dry-run] [--force]` | Restores the archive |

`restore` switches Nextcloud maintenance mode on for itself, so no request writes while tables are replaced, and always switches it off again, also when the restore fails. It refuses when maintenance mode is already on, because switching it off at the end would cut short someone else's maintenance. It cannot ask the administrator to switch maintenance on first: Nextcloud loads no app commands in maintenance mode (decided 4 Oct after the live check). It verifies the archive first and refuses a schema fingerprint that differs from the installed one. In one database transaction it empties each Keepiq table and inserts the archive rows; then it replaces the attachment blobs. `--dry-run` prints current and archive row counts per table and changes nothing. `--force` is required when the archive is older than the newest row in `keepiq_audit_log`, so an administrator cannot roll back by accident. A `--dry-run` prints that rule as a notice instead, so the counts are visible before choosing `--force`.

### D5: Restore gives back ciphertext as it was

After a restore:

- every user unlocks with the master password that was valid when the backup ran, because their private key blob is wrapped with it;
- secrets written after the backup are gone, and key rotations after the backup are undone;
- CA keys and SIEM secrets are readable only with the same Nextcloud `secret`; the command probes one `ICrypto` value and warns when it fails;
- rows owned by users that no longer exist in Nextcloud are listed as a warning, not dropped.

The command prints these points before it asks for confirmation.

### D6: No web download

The admin section shows status, schedule, key and the archive list, and a "Back up now" button that queues the job. It offers no download. A download link would let anyone with the admin area copy every vault's ciphertext and metadata through the browser. Reading the archive needs shell access, which already implies database access.

## Security and zero-knowledge

- No plaintext secret value or master password exists on the server, so none can reach an archive.
- Stored encrypted in the archive: secret fields (RSA), private keys (AES under the master password), attachment blobs and their metadata (AES-GCM), CA keys and SIEM secrets (`ICrypto`). Stored plain in the archive: user ids, secret names and URLs, folder and team folder structure, audit entries, dates, statuses.
- With a recipient key set, the whole archive is ciphertext on the server.
- Restore never asks for, derives or stores a master password or a user private key.

## Risks / Trade-offs

- Archives double the disk use of Keepiq data per retained copy. The section shows the total size; retention is configurable.
- A long backup on a large instance holds a read over every table. Rows are streamed, not loaded, and the job runs time-insensitive.
- Restoring rolls back every vault, including changes users made after the backup. The dry run and the `--force` rule make that explicit.

## Seed data

None. PHPUnit tests write an archive from fixture rows into a temporary `IAppData` mock and restore it into SQLite. The seeded development vault (`lib/Repair/SeedDevelopmentData.php`) is enough for a manual `keepiq:backup:create` on the dev instance.

## Migration

No table or column. `appinfo/info.xml` gains the new `<background-jobs>` entry and a `<commands>` block, so `<version>` must be bumped for existing installs to register the job.
