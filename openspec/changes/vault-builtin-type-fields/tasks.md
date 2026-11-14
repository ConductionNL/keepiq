# Tasks: built-in item types with their own fields

## 1. The forms

- [x] 1.1 Add `src/utils/builtinTypeFields.js` with the Login, Certificate, API Key, Database, SSH Key and Secure Note definitions, and a multi-line kind to `TypedFieldsForm.vue`. Verify: `tests/vitest/builtin-type-fields.spec.js`.
- [x] 1.2 Use them in `SecretCreateDialog.vue` and `SecretEditDialog.vue`: the value field's label and input, the extra fields, and the left-out fields. Verify: `tests/dialogs/SecretCreateDialog.builtinFields.spec.js`.
- [x] 1.2a Call a Login's value "Password", and mark the value field as required in `SecretCreateDialog.vue`. Verify: `tests/dialogs/SecretCreateDialog.builtinFields.spec.js`.
- [x] 1.3 Show the value and extra fields with their translated labels in `SecretDetailSidebar.vue`: a certificate at once, multi-line values with their line breaks, and the type's masked and multi-line extra fields masked (`PasswordField.vue`). Verify: `tests/components/SecretDetailSidebar.builtinFields.spec.js`.

## 2. Requests

- [x] 2.1 List the type's fields in `SecretRequestCreateDialog.vue`, with the required value ticked and locked. Verify: the dialog spec.
- [x] 2.2 Add `secret_type` to the public request answer. Verify: `SecretRequestFillControllerTest`.
- [x] 2.3 Label, render and mask the fields by type on `SecretRequestFill.vue`. Verify: `tests/views/SecretRequestFill.spec.js`.

## 3. Close out

- [x] 3.1 Add the new strings to every shipped locale. Verify: `npm run test:l10n`.
