## 1. Archive

- [x] 1.1 Add `lib/Backup/BackupTableRegistry.php` with the 33 tables and a test that compares it with the migration's `TABLES` by reflection. Verify with that PHPUnit test.
- [x] 1.2 Add the archive writer (zip, JSON lines per table, blobs, manifest with row counts, SHA-256 and schema fingerprint), streaming rows. Verify with a PHPUnit test that writes fixture rows and checks every checksum.
- [x] 1.3 Add segmented AES-256-GCM archive encryption with the content key wrapped by RSA-OAEP-SHA256 under the configured public key. Verify with a PHPUnit round-trip test and a test that a wrong key fails.

## 2. Schedule and settings

- [x] 2.1 Add the settings keys (`backup_enabled`, `backup_interval_hours`, `backup_retention_count`, `backup_recipient_public_key`) with validation in `AdminSettingsService`. Verify with a PHPUnit test for bounds and key parsing.
- [x] 2.2 Add `ScheduledVaultBackupJob` (hourly, runs when due, retention clean-up, status in app config), register it in `appinfo/info.xml` and bump `<version>`. Verify with a PHPUnit test for due and not-due runs and retention.
- [x] 2.3 Add the `BACKUP_CREATED`, `BACKUP_FAILED` and `BACKUP_RESTORED` audit events with whitelisted metadata. Verify with a PHPUnit test on the dispatched metadata.

## 3. Commands

- [x] 3.1 Add `keepiq:backup:create` and `keepiq:backup:list` and a `<commands>` block in `appinfo/info.xml`. Verify manually with `occ keepiq:backup:create` and `occ keepiq:backup:list` on the dev instance. Verified 4 Oct on a fresh Nextcloud 35.0.1 + PostgreSQL 16: both commands listed under `occ list keepiq`; create wrote `keepiq-backup-20261003-225701.zip (33234 bytes, not encrypted)`, rc 0, with a `backup.created` audit row; list showed it with size, time and path, rc 0.
- [x] 3.2 Add `keepiq:backup:verify` with `--key-file`. Verify with a PHPUnit command test for a good archive, a tampered file and a wrong key.
- [x] 3.3 Add `keepiq:backup:restore` with the maintenance mode check, schema fingerprint check, single transaction, blob replacement, `--dry-run` and the `--force` rule. Verify with a PHPUnit test that restores into SQLite and compares every table.
- [x] 3.4 Print the restore warnings (old master password, lost later changes, instance secret probe, missing users). Verify with a PHPUnit command output test.

## 4. Admin UI

- [x] 4.1 Add `VaultBackupSection.vue` with schedule, retention, public key upload, last result, archive list and "Back up now", and no download action. Verify with a vitest in `tests/components/`.
- [ ] 4.2 Prove a restored vault still unlocks. Verify manually on the dev instance: create a backup, change a secret, restore, unlock as `admin` with the master password from before, and see the old value. Live 4 Oct: the restore engine works after binding values by type (PostgreSQL refused `false` bound as ''; `TableStoreTest`); through a harness running the same command class in maintenance mode, admin unlocked with the old master password and read the pre-backup value. Still open: `occ keepiq:backup:restore` itself cannot run, because Nextcloud loads no app commands in maintenance mode and the command refuses outside it (policy question in the tracker).

## Acceptance criteria

- With backups on, an archive of every Keepiq table and attachment blob appears in the app data folder at the chosen interval, and old archives beyond the retention count are removed.
- No archive contains a plaintext secret value or a master password.
- With a backup public key set, an archive cannot be verified or restored without the matching private key.
- `occ keepiq:backup:restore` refuses to run outside maintenance mode and refuses an archive from a different schema.
- After a restore, each user unlocks with the master password valid at backup time and reads the values as they were then.
- The web interface offers no way to download an archive.
