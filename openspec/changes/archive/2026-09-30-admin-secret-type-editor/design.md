# Design: an administrator defines item types and the fields they carry

## Context

At development `156cd800`:

- `lib/Db/SecretType.php` has `name`, `label`, `scope`, `ownerId`, `createdAt` and no fields.
- `lib/Controller/SecretTypeController.php:99` `create` passes `$isAdmin` to `typeService->createType`; global scope needs the admin role.
- `src/store/modules/secretType.js:73-110` has `createType`, `updateType`, `deleteType` and no caller for the first.
- `lib/Repair/SeedSecretTypes.php` seeds the built-in types; built-ins have no field list and keep their current forms.
- `src/dialogs/SecretCreateDialog.vue` picks the form from the type name.

## Goals / Non-Goals

**Goals**
- An administrator can define a type and its fields without code.

**Non-Goals**
- Per-role publishing of a type.
- Field validation patterns.
- User-scope custom types beyond what the API already allows.

## Decisions

### D1: Fields are metadata, values are ciphertext

The definition (labels and kinds) is not secret and is stored in plain text on the type, like folder names. Values go into the existing encrypted blob, so the server never sees them.

### D2: Built-in types keep their forms

Only types with a non-empty `fields` list render the generic typed form, so nothing changes for login, note or SSH key.

### D3: The Item types page is an admin settings section (added at build, 2026-09-30)

The proposal said "an admin page". Keepiq's admin surfaces live on the Nextcloud admin settings page (`src/views/settings/Settings.vue`), and an admin view in the app's own router is forbidden (ADR-004, hydra gate admin-router). So Item types is a `CnSettingsSection` there, and the editor and the delete confirmation are dialogs in `src/dialogs/`.

### D4: Field keys are fixed, values are stored under the label (added at build)

A field's key is derived from its label when it is added and never changes, so a relabel keeps the field. Values are stored in the additional-fields blob under the label, because every other reader of the blob (CLI, extension, apps) shows members by name. Labels are therefore unique per type and may not be `key`, `login` or `url`, which route to built-in columns.

### D5: The migration ships with a version bump (added at build)

`Version001001Date20260930000000` adds the nullable `fields` column. Nextcloud only runs a migration when `<version>` in `appinfo/info.xml` rises, so this change bumps the timestamp part of the version (hydra gate-110); the release pipeline stamps its own version on release as before.
