---
kind: code
---

# Scheduled vault backups

## Why

Keepiq has no backup of its own. An instance relies on the Nextcloud database backup, and restoring that restores everything else too. Administrators want scheduled Keepiq backups they can restore on their own, from the command line.

| Row | Capability | What keepiq does today |
|---|---|---|
| admin-27 | Administrators schedule automatic encrypted backups of every vault on the server and restore them from the command line | There is no scheduled server-side backup of all vaults and no restore command; an instance relies on the Nextcloud database backup. |

Matrix: keepiq `openspec/parity/capabilities.json`

### Demand

- featureRequest: https://community.bitwarden.com/t/adjusting-timezone-and-database-backup-schedule-in-bitwarden/59341

### Competitors rated yes

- Nextcloud Passwords: "marius-wieschollek/passwords@2026.9.0 src/lib/Cron/BackupJob.php:47 backup/interval; src/lib/Helper/AppSettings/BackupSettingsHelper.php:35-37 interval, max files, auto-restore after update; src/lib/Command/BackupRestoreCommand.php:45 passwords:backup:restore ..."

### What a server backup can hold

The server never holds plaintext secret values or master passwords (ADR-003). A server-side backup of every vault can therefore only contain ciphertext plus the metadata the server already stores in plain form. Restoring it gives back ciphertext that still needs each user's own key.

## What Changes

- A background job writes a backup archive of every Keepiq table and every attachment blob on a schedule the administrator sets (default: daily, keep 7).
- The archive holds ciphertext and metadata exactly as stored, with a manifest of row counts and checksums.
- Optionally, the administrator uploads a backup public key; each archive is then encrypted to it, and only the matching private key, held off the server, can open it.
- Four `occ` commands: `keepiq:backup:create`, `keepiq:backup:list`, `keepiq:backup:verify` and `keepiq:backup:restore`. Restore needs maintenance mode, checks the schema version, and supports `--dry-run`.
- A "Vault backups" section in the admin settings: schedule, retention, public key, last result and the list of archives. Archives are not downloadable from the web.
- Backup runs, failures and restores are audited.

## Capabilities

### New Capabilities

- `vault-backups`: scheduled, ciphertext-only backups of every vault on the server, with verification and restore from the command line.

### Modified Capabilities

None.

## Impact

- **Backend**: `lib/Backup/` (table registry, archive writer and reader, archive encryption), `lib/BackgroundJob/ScheduledVaultBackupJob.php`, the first `lib/Command/` classes, settings keys in `AdminSettingsService`, three audit event types.
- **Frontend**: `VaultBackupSection.vue` in the admin settings.
- **Database**: none. Archives live in the app's data folder; status lives in app config.
- **Security**: an archive is as sensitive as the database it copies, so the optional public key encryption is recommended. No new plaintext exists anywhere. Restoring never needs or learns a master password.
- **Cross-app**: none. Application vaults are backed up like user vaults; an application still decrypts with its own key after a restore.
