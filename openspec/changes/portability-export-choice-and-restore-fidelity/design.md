# Design: choose what goes into an export, and restore a backup without losing types

## Context

At development `4c214a9d`:

- `src/views/SecretList.vue:1224-1239` `decryptAllSecrets()` fetches the whole vault, decrypts each secret and skips, in silence, any it cannot decrypt; `openExport()` (`:1247`) hands the result to `ExportDialog.vue` (`:8-11`).
- `src/dialogs/ExportDialog.vue:250-257` builds scope options (entire vault or one folder); `:322-327` `buildScope()` returns `{ mode: 'vault' }` or `{ mode: 'folders', folderIds: [one] }`; the modes are encrypted backup, plaintext CSV and CXF (`src/store/modules/export.js:86`, `:122`, `:160`).
- `src/export/serializer.js:67-87` `collectSubtree()` and `:101-130` `serializeVault()`, which writes `type: secret.type ?? secret.typeId ?? 'login'` (`:120`).
- A decrypted secret is `{ ...secret }` with `typeId` (`src/store/modules/secret.js:306`, `lib/Db/Secret.php:307`); system type ids are deterministic UUID v5 of the type name (`lib/Repair/SeedSecretTypes.php:45-60`), custom type ids are random.
- `src/import/backupParser.js:23-35` `toRows()` copies `type` through and sets no `sourceRow`; CSV rows get one (`src/import/parsers/csv.js:83-102`).
- `src/store/modules/import.js:251-288` `encryptRow()` stamps `typeId` only for `totp`, `passkey`, `card` and `identity` by name; everything else is stored with the default type.
- `appinfo/routes.php:74-77` has `secretType#index` and `secretType#create` (user-scoped custom types).
- `tests/vitest/export-serializer.spec.js:24-26` uses `typeId: 'login'`, a name, not a UUID.

## Goals / Non-Goals

**Goals**
- Export exactly the part of the vault the user means.
- A backup restores every secret with its type, and every rejected row can be found.
- An export never drops a secret without saying so.

**Non-Goals**
- Attachments in the backup. They stay out, as today, and the dialog keeps saying so.
- A server-side or scheduled backup (`admin-scheduled-vault-backups` covers that).

## Decisions

**D1. Scope is a set of filters that combine.** `{ folderIds?, typeIds?, secretIds? }`; an empty scope is the entire vault. A selection scope (`secretIds`) comes from the bulk strip and cannot be combined with the others in the dialog, to keep the dialog simple. `serializeVault()` applies the filters in one pass after `collectSubtree()`.

**D2. Field choice is a deny list with fixed minimums.** The user may leave out `login`, `additionalFields` or the `totp` seed inside additional fields. Name and value always go in, because a backup without values is not a backup. The payload header gets `partial: true` and the list of left-out fields; the restore shows it before committing.

**D3. The export writes names, the restore resolves names.** Each exported secret gets `typeName` (the type's `name`) and, for a custom type, `typeLabel`, next to the existing `type`. Restore resolves in order: a system type by `typeName`; an existing custom type with the same name; a new user-scoped custom type created through `POST /api/v1/secret-types`; the default type with a row warning. For backups written before this change, where `type` holds a UUID, the restore tries the UUID against the instance's type ids first, which recovers system types because their ids are the same on every instance.

**D4. Source rows are positions in the payload.** `toRows()` sets `sourceRow` to the 1-based index, so the import's rejection list and duplicate step name the row.

**D5. The skipped count travels with the export.** `decryptAllSecrets()` returns `{ secrets, skipped }`; the dialog shows "N secrets could not be decrypted and are not in this export" and asks the user to continue.

## Security and zero-knowledge

All of it runs in the browser on data the user already decrypted. Type names, labels and the partial marker sit inside the encrypted backup payload. Creating a custom type on restore sends only the type's name and label, the same data the existing type editor sends. The plaintext CSV keeps its master password check and warning (`ExportDialog.vue:130`, `src/crypto/reauth.js`).

## Risks / Trade-offs

- Restoring creates custom types the user may not want. The restore summary lists them, and the user can delete them afterwards.
- A partial backup can be mistaken for a full one. The header marker and the restore notice exist for that.

## Seed data

Keepiq owns its tables (keepiq ADR-001) and has no OpenRegister register. The dev fixture vault gets one API key, one authenticator and one secret of a custom type "VPN profile", so a round trip shows every type branch.

## Migration

None. Old backups keep restoring through D3's fallback.
