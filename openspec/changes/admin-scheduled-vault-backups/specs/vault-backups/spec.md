## ADDED Requirements

### Requirement: Administrator schedules vault backups

The system MUST let an administrator switch scheduled backups on in the Keepiq admin settings, choose the interval in hours (default 24, minimum 1) and the number of archives to keep (default 7). When on, a background job MUST write one archive per interval to the app data folder and MUST remove the oldest archives beyond the retention count after a successful run. The section MUST show the last run time, its result and the archive list. Every run MUST be audited as `BACKUP_CREATED` or `BACKUP_FAILED`.

#### Scenario: Daily backup appears

- **GIVEN** an administrator switched on "Vault backups" with interval 24 and retention 7 in the Keepiq admin settings
- **WHEN** 24 hours pass and Nextcloud cron runs
- **THEN** a new archive MUST appear in the "Vault backups" archive list
- **AND** a `BACKUP_CREATED` audit event MUST be recorded

### Requirement: Archives hold ciphertext and metadata only

Each archive MUST contain every Keepiq table and every attachment blob exactly as stored, plus a manifest with the format `keepiq-vault-backup-v1`, the app version, the schema fingerprint, and a row count and SHA-256 per file. An archive MUST NOT contain any plaintext secret value, master password or unwrapped private key. The table list MUST be checked against the schema by an automated test.

#### Scenario: Secret values stay ciphertext in the archive

- **GIVEN** vault owner `alice` has a secret whose value is `YOUR_TOKEN_HERE`
- **WHEN** an administrator runs `occ keepiq:backup:create` and inspects the archive
- **THEN** the archive MUST contain `alice`'s secret row with RSA ciphertext in its value fields
- **AND** the string `YOUR_TOKEN_HERE` MUST NOT occur anywhere in the archive

### Requirement: Archives can be encrypted to an administrator-held key

When a backup public key is configured, each archive MUST be encrypted with a random AES-256-GCM content key that is wrapped with RSA-OAEP-SHA256 under that public key. The server MUST NOT hold the matching private key. Verify and restore MUST require the private key through `--key-file`.

#### Scenario: Archive cannot be read without the private key

- **GIVEN** an administrator uploaded a backup public key and a backup ran
- **WHEN** they run `occ keepiq:backup:verify <file>` without `--key-file`
- **THEN** the command MUST fail and say the archive is encrypted

### Requirement: Archives are verified and restored from the command line

The system MUST offer `occ keepiq:backup:list`, `occ keepiq:backup:verify` and `occ keepiq:backup:restore`. Restore MUST refuse unless Nextcloud maintenance mode is on, MUST verify every checksum first, MUST refuse an archive whose schema fingerprint differs from the installed schema, and MUST replace all Keepiq tables in one database transaction before replacing the attachment blobs. `--dry-run` MUST print per-table current and archive row counts and change nothing. Restoring an archive older than the newest audit entry MUST require `--force`. Every restore MUST be audited as `BACKUP_RESTORED`.

#### Scenario: Restore outside maintenance mode is refused

- **GIVEN** maintenance mode is off
- **WHEN** an administrator runs `occ keepiq:backup:restore <file>`
- **THEN** the command MUST exit with an error and no table MUST change

#### Scenario: Dry run shows the difference

- **GIVEN** maintenance mode is on and a valid archive
- **WHEN** an administrator runs `occ keepiq:backup:restore <file> --dry-run`
- **THEN** the command MUST print current and archive row counts per table
- **AND** no table MUST change

### Requirement: A restore returns ciphertext that still needs each user's key

After a restore, every user MUST unlock with the master password that was valid when the archive was written, and MUST read the values as they were then. The restore command MUST state before it asks for confirmation that later changes are lost, that users need their master password from backup time, and that CA keys need the same Nextcloud instance secret. Restore MUST NOT ask for, derive or store any master password or user private key.

#### Scenario: User unlocks the restored vault

- **GIVEN** a backup ran, then vault owner `alice` changed a secret value
- **WHEN** an administrator restores that backup and `alice` unlocks on the lock screen at `/lock` with her master password from backup time
- **THEN** `alice` MUST see the secret value from before her change

### Requirement: Archives are not downloadable from the web

The admin settings MUST NOT offer a download of a backup archive, and no Keepiq HTTP endpoint MUST serve archive content.

#### Scenario: Admin section lists without download

- **GIVEN** an administrator on the "Vault backups" section with three archives
- **WHEN** they look at the archive list
- **THEN** each row MUST show name, size, time and whether it is encrypted
- **AND** no row MUST offer a download
