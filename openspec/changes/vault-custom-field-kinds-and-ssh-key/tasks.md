# Tasks: custom fields with a schema and a real SSH key item

## 1. Custom field schemas

- [x] 1.1 Add the schema model in `src/utils/fieldSchema.js`, used by `src/utils/additionalFields.js`: read and write `@schema`, refuse `@schema` as a field name, the presets and their schemas, and one `schemaError()` check with a message per rule. Verify: vitest on an old blob reading as string, on every preset round trip, and on each limit refusing and accepting a value.
- [x] 1.2 Skip `@schema` in every reader that lists fields: sidebar, editor, request dialog, CSV and backup export, importers, request merge. Verify: vitest per reader on a blob with `@schema`; grep for blob readers that do not go through the helper.
- [x] 1.3 Add the preset select and the Advanced part (type, format, limits, Hidden) to `AdditionalFieldsEditor.vue`, with an input per schema and the JSON viewer for objects. Verify: vitest on the editor emitting `{name, value, schema}`, Custom after an Advanced change, and Create/Save blocked on a value or schema change that does not fit.
- [ ] 1.4 Render each field by its schema in `SecretDetailSidebar.vue`, masked when hidden, with a warning for a value that does not fit. Verify: vitest that a hidden value is not in the DOM text until Reveal, a URL is a link and an object uses the JSON viewer; a Playwright flow hide, reveal, copy.
- [x] 1.5 Describe the built-in type fields in `src/utils/builtinTypeFields.js` with the same schemas. Verify: vitest that a Database Port refuses 70000 and a certificate's private key stays masked and multi-line.
- [x] 1.6 Let importers keep a value that does not fit and drop its schema. Verify: vitest on an import with a number field holding "n/a".
- [x] 1.7 Carry each requested field's schema in the secret request: store it in a new `field_schemas` column and return it (`FieldSchemaShape`, `SecretRequestService`, `SecretRequestController`, `SecretRequestFillController`) with a shape check, offer it in `SecretRequestCreateDialog.vue`, enforce it in `SecretRequestFill.vue`, and merge it into `@schema`. Verify: PHPUnit on the stored and returned schema and on a malformed schema refused; vitest on the fill page blocking a PIN that does not match its pattern and on the merge.
- [x] 1.8 Carry the schema through `src/cxf/cxf.js` export and import. Verify: vitest round trip with one hidden text field and one integer field with limits.
- [x] 1.9 Document the `@schema` member in the application API docs. Verify: the docs page names the member, its shape and that applications skip it.

## 2. SSH key item

- [ ] 2.1 Build the SSH key section (private key, public key, fingerprint) and show it for type `ssh_key` in the create and edit dialogs. Verify: vitest that the fingerprint is derived and a malformed key is refused.
- [ ] 2.2 Add Generate key pair and Import from file. Verify: vitest generating a pair and parsing the OpenSSH public key; Playwright flow create, save, open, copy public key.
- [ ] 2.3 Update the CXF mapping for the new SSH fields. Verify: vitest round trip.

## 3. Close out

- [ ] 3.1 Add strings to every shipped locale through the writing skill, never `test:l10n:write`. Verify: `npm run test:l10n`.
- [ ] 3.2 Set rows `vault-11` and `vault-14` to built with evidence paths and lines, and archive the change. Verify: parity_verify --strict.

