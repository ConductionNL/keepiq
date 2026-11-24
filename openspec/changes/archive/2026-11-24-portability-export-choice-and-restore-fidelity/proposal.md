---
kind: code
---

# Choose what goes into an export, and restore a backup without losing types

## Why

The export dialog offers one choice of scope: the entire vault or one folder with its subfolders (`src/dialogs/ExportDialog.vue:250-257` `scopeOptions`, `:322-327` `buildScope`, `src/export/serializer.js:101` `serializeVault` with `collectSubtree`). A user who wants to hand over the logins of one project, or only the API keys, or the selection they just made in the vault list, exports everything or one folder. The bulk strip in the vault list can move, share and delete a selection but cannot export it (`src/views/SecretList.vue:294-333`).

Restoring a `.doriath-backup` loses the secret types. Reading the code at development `4c214a9d`: the export writes `type: secret.type ?? secret.typeId ?? 'login'` (`src/export/serializer.js:120`), and a decrypted secret carries only `typeId` (`src/store/modules/secret.js:306`, `lib/Db/Secret.php:307`), which is a UUID (`lib/Repair/SeedSecretTypes.php` derives system type ids as UUID v5). The restore passes `type` through unchanged (`src/import/backupParser.js:32`) and the import stamps a type only when `type` is the name `totp`, `passkey`, `card` or `identity` (`src/store/modules/import.js:271-288`). So a restored authenticator, passkey, card, identity, API key or custom-type secret comes back as the default type. The serializer test uses `typeId: 'login'`, a name (`tests/vitest/export-serializer.spec.js:24-26`), so it cannot see this. Restored rows also carry no source row number (`backupParser.js:23-35` sets none), so a rejected row cannot be traced back, and secrets that fail to decrypt are skipped from the export without a word (`src/views/SecretList.vue:1230-1236`).

### Matrix rows (keepiq `openspec/parity/capabilities.json`)

| row | capability | Keepiq today |
|---|---|---|
| `portability-09` | Export an encrypted backup that can be restored. | `partial`: client-side encrypted backup and restore through the import wizard; restored rows have no source row number and custom types come back as the default type |
| `portability-11` | Choose what goes into an export. | `partial`: whole vault or one folder subtree; no choice by type, selected items or fields |

For `portability-09` the missing half is a restore that keeps every secret type and each row's source position, and an export that reports what it could not include. For `portability-11` it is choosing by type, by selected items and by fields. The encrypted backup itself and the folder scope are built. `vault-19` (bulk export of a selection) was deferred on its own row and is covered here as part of `portability-11`.

### Demand

No demand row for either. `portability-09` has five competitors rating it yes and `portability-11` three.

### Competitors rated yes

- `portability-09`, Bitwarden: "libs/tools/export-vault-core/src/services/individual-vault-export.service.ts:61 encrypted_json; ... export.component.ts:236 fileEncryptionType (account restricted or file password :234); libs/importer/src/importers bitwarden encrypted JSON importer Note: Encrypted JSON export, account-bound or password-protected, re-importable."
- `portability-09`, Passbolt: "ExportResources.js:205 kdbx export formats; ... ExportResourcesCredentials.js:147 password (and key file) protecting the KDBX; ... resourcesKdbxImportParser.js re-import."
- `portability-09`, Keeper: "'Encrypted Keepass (.kdbx)' export protected by a chosen master password or key file, re-importable" (https://docs.keeper.io/user-guides/export-and-reports/vault-export).
- `portability-09`, HashiCorp Vault: "vault/logical_system_raft.go:142 sys/storage/raft/snapshot (save and restore) ... Integrated storage snapshots stay barrier-encrypted and can be downloaded and restored from the UI, even in CE."
- `portability-09`, Nextcloud Passwords: "src/vue/Components/Export.vue:7 'Database Backup' with optional backup password (:20-30, minlength 10); src/js/Manager/ExportManager.js:47-51 encrypts each section with options.password; ... src/lib/Command/BackupRestoreCommand.php:45."
- `portability-11`, Bitwarden: "export.component.ts:111 organizationId selection (personal vault or a chosen organisation), format choice json/csv/encrypted_json/zip ... with or without attachments; not a per-item or per-folder selection."
- `portability-11`, Passbolt: "ExportResources.js:82 exports the selected folders (with subfolders) and :177 selected resources Note: Users export a selection of passwords or folders, or everything."
- `portability-11`, Nextcloud Passwords: "src/vue/Components/Export.vue:42-60 choose passwords, folders, tags; :70 excludeShared; :81-84 CSV database choice ... You choose which object types go in, whether shared items are included, and CSV columns for custom CSV."

## What Changes

- **Choose by folders, types and selection.** The export dialog's scope becomes: entire vault, chosen folders (several, with subfolders), chosen secret types, or the current selection. Export selected is added to the vault list's bulk strip and opens the dialog with the selection as scope.
- **Choose fields.** For the plaintext CSV and the encrypted backup, the user can leave out usernames, additional fields or the one-time code seed. The name and the value are always included. A backup with fields left out is marked as partial in its header, and the restore says so.
- **Restore keeps types.** The export writes each secret's type name, and for a custom type its label, next to the type id. The restore maps a system type by name, maps a custom type to an existing type of the same name, and creates a user-scoped custom type through `POST /api/v1/secret-types` when none exists. Only when that fails does a row fall back to the default type, with a warning on that row.
- **Restore keeps positions.** Each restored row gets its 1-based position in the backup as its source row, so a rejected row can be found.
- **Nothing skipped in silence.** When secrets cannot be decrypted for an export, the dialog says how many were left out, before the file is written.

## Capabilities

### New Capabilities

- `export-selection-and-restore`: export scope by folders, types, selection and fields, and a lossless restore of types and row positions.

### Modified Capabilities

- None in delta form. `secret-export` and `secret-import` keep their requirements; this change adds requirements next to them.

## Impact

- **Backend**: none beyond the existing `POST /api/v1/secret-types` used by the restore.
- **Frontend**: `ExportDialog.vue` (scope and fields), `src/export/serializer.js` (type name and label, field filter, partial marker, skipped count), `src/import/backupParser.js` (type and source row), `src/store/modules/import.js` (type resolution), `SecretList.vue` (Export selected, skipped count).
- **Database**: none.
- **Security**: the backup format stays client-side, Argon2id plus AES-256-GCM (`src/export/backup.js`); type names and labels are inside the encrypted payload. The plaintext CSV keeps its master password check and warning.
- **Cross-app**: none. The CXF export (`cxf-import-export`) keeps its own mapping.
