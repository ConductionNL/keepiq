---
kind: code
---

# An administrator defines item types and the fields they carry

## Why

Custom secret types exist in the API (`appinfo/routes.php:74-77`) and in the store (`src/store/modules/secretType.js:73` `createType`), but no page lets anyone create one, and a type is only `name`, `label`, `scope` and `ownerId` (`lib/Db/SecretType.php`): it cannot say which fields an item of that type carries. The row has a feature request from the Passbolt community and Keeper rates yes (a record template with labelled and required fields, published to roles). A request plus one competitor yes is a build under the decision rule.

One row, one change.

### Matrix rows (`keepiq` `openspec/parity/capabilities.json`)

| row | capability | Today |
|---|---|---|
| `admin-18` | An administrator defines new item types and the fields they carry. | `no`: `no`: the secret-type routes and store exist, no page creates a type, and a type has no field definition at all |

### Demand

- `admin-18`: featureRequest, https://community.passbolt.com/t/as-an-administrator-i-can-create-new-secret-types-and-define-their-associated-input-fields/19

### Competitors rated yes

- `admin-18`, keeper: "https://docs.keeper.io/enterprise-guide/creating-new-record-types : an admin with 'Manage Record Types in Vault' creates a record template with labelled and required fields and publishes it to roles."

## What Changes

- Add a `fields` definition to a secret type: an ordered list of `{key, label, kind, required}` with kinds `text`, `hidden`, `url`, `email`.
- Add an admin page, Item types, to create, relabel, edit fields and delete global types.
- Make the create and edit dialogs render the fields of the chosen type, storing values in the encrypted additional fields blob under the field label.

## Capabilities

### New Capabilities

- `admin-secret-types`

### Modified Capabilities

- None in delta form.

## Impact

- **Database**: one migration adds a `fields` JSON text column to the secret types table; `<version>` bump.
- **Backend**: `SecretType` entity, `SecretTypeService::createType` and `updateType` validate the field list.
- **Frontend**: new admin view and route, changes in `SecretCreateDialog.vue` and `SecretEditDialog.vue`.
- **Cross-row**: reuses the hidden field kind from `vault-custom-field-kinds-and-ssh-key`; the two land in either order because a `hidden` kind falls back to text until that change lands.
