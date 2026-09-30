# Admin secret types Specification

**Status**: done

**OpenSpec changes:**
- [admin-secret-type-editor](../../changes/archive/2026-09-30-admin-secret-type-editor/) _(archived 2026-09-30)_

## Purpose
An administrator defines item types for everyone, with the fields an item of that type carries; users fill those fields in the create and edit dialogs and the values stay inside the encrypted additional fields. Parity row admin-18.

## Requirements

### Requirement: Item type definitions

The system MUST let an administrator create a global item type with a name, a label and an ordered list of fields, each with a label, a kind and a required flag, and MUST let the administrator edit and delete it. A type definition MUST be visible to every user as a choice in the create dialog. Deleting a type MUST leave its secrets readable and move them to the login type as the type service already does.

#### Scenario: An administrator creates a type

@e2e exclude The Playwright browser service is not available to this lane; covered by vitest tests/dialogs/SecretTypeEditorDialog.spec.js 'creates Server access as a global type with its fields in order', tests/components/ItemTypesSection.spec.js and PHPUnit SecretTypeControllerTest::testAdminCreatesTypeWithFields.

- **GIVEN** an administrator on the Item types admin page
- **WHEN** the administrator creates Server access with fields Host (url, required), Port (text) and Root password (hidden) and saves
- **THEN** the type appears in every user's create dialog

#### Scenario: A user fills a typed item

@e2e exclude Covered by vitest tests/dialogs/SecretCreateDialog.typedFields.spec.js 'blocks the save and marks Host when a required field is empty' and tests/dialogs/SecretEditDialog.typedFields.spec.js 'blocks the save while a required field is empty'.

- **GIVEN** a user choosing Server access in the create dialog
- **WHEN** the user leaves Host empty and saves
- **THEN** the dialog blocks the save and marks Host as required

#### Scenario: A regular user cannot define a global type

@e2e exclude An API scenario; covered by PHPUnit tests/Unit/Controller/SecretTypeControllerTest.php testRegularUserGetsForbiddenForGlobalType.

- **GIVEN** a user without the admin role
- **WHEN** the user posts a global type to the secret types route
- **THEN** the server answers 403

### Requirement: Typed fields storage

Values entered for the fields of a type MUST be stored inside the encrypted additional fields blob, and the server MUST NOT receive them in plain text.

#### Scenario: Values stay encrypted

@e2e exclude Covered by vitest tests/dialogs/SecretCreateDialog.typedFields.spec.js 'sends the typed values inside the additional fields the store encrypts' and tests/store/secret-additional-fields.spec.js, which pins that the store encrypts the additional fields before the request.

- **GIVEN** a user saving a Server access item
- **WHEN** the request is sent to the server
- **THEN** the request body holds only ciphertext for the field values
