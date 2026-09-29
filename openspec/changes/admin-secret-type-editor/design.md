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
