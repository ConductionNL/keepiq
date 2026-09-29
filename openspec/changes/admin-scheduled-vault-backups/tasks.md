## 1. Archive

- [ ] 1.1 Add `lib/Backup/BackupTableRegistry.php` with the 33 tables and a test that compares it with the migration's `TABLES` by reflection. Verify with that PHPUnit test.
- [ ] 1.2 Add the archive writer (zip, JSON lines per table, blobs, manifest with row counts, SHA-256 and schema fingerprint), streaming rows. Verify with a PHPUnit test that writes fixture rows and checks every checksum.
- [ ] 1.3 Add segmented AES-256-GCM archive encryption with the content key wrapped by RSA-OAEP-SHA256 under the configured public key. Verify with a PHPUnit round-trip test and a test that a wrong key fails.

## 2. Schedule and settings

- [ ] 2.1 Add the settings keys (`backup_enabled`, `backup_interval_hours`, `backup_retention_count`, `backup_recipient_public_key`) with validation in `AdminSettingsService`. Verify with a PHPUnit test for bounds and key parsing.
- [ ] 2.2 Add `ScheduledVaultBackupJob` (hourly, runs when due, retention clean-up, status in app config), register it in `appinfo/info.xml` and bump `<version>`. Verify with a PHPUnit test for due and not-due runs and retention.
- [ ] 2.3 Add the `BACKUP_CREATED`, `BACKUP_FAILED` and `BACKUP_RESTORED` audit events with whitelisted metadata. Verify with a PHPUnit test on the dispatched metadata.

## 3. Commands

- [ ] 3.1 Add `keepiq:backup:create` and `keepiq:backup:list` and a `<commands>` block in `appinfo/info.xml`. Verify manually with `occ keepiq:backup:create` and `occ keepiq:backup:list` on the dev instance.
- [ ] 3.2 Add `keepiq:backup:verify` with `--key-file`. Verify with a PHPUnit command test for a good archive, a tampered file and a wrong key.
- [ ] 3.3 Add `keepiq:backup:restore` with the maintenance mode check, schema fingerprint check, single transaction, blob replacement, `--dry-run` and the `--force` rule. Verify with a PHPUnit test that restores into SQLite and compares every table.
- [ ] 3.4 Print the restore warnings (old master password, lost later changes, instance secret probe, missing users). Verify with a PHPUnit command output test.

## 4. Admin UI

- [ ] 4.1 Add `VaultBackupSection.vue` with schedule, retention, public key upload, last result, archive list and "Back up now", and no download action. Verify with a vitest in `tests/components/`.
- [ ] 4.2 Prove a restored vault still unlocks. Verify manually on the dev instance: create a backup, change a secret, restore, unlock as `admin` with the master password from before, and see the old value.

## Acceptance criteria

- With backups on, an archive of every Keepiq table and attachment blob appears in the app data folder at the chosen interval, and old archives beyond the retention count are removed.
- No archive contains a plaintext secret value or a master password.
- With a backup public key set, an archive cannot be verified or restored without the matching private key.
- `occ keepiq:backup:restore` refuses to run outside maintenance mode and refuses an archive from a different schema.
- After a restore, each user unlocks with the master password valid at backup time and reads the values as they were then.
- The web interface offers no way to download an archive.
