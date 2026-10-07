# Tasks: hidden custom fields and a real SSH key item

## 1. Custom field kinds

- [ ] 1.1 Add `kind` to the field model in `AdditionalFieldsEditor.vue` with a kind select, and mask hidden values with reveal and copy controls. Verify: vitest on the editor emitting `{name, value, kind}` and on an old blob reading as text.
- [ ] 1.2 Render hidden fields masked in `SecretDetailSidebar.vue`. Verify: vitest that a hidden value is not in the DOM text until Reveal, and a Playwright flow hide, reveal, copy.
- [ ] 1.3 Carry the kind through `src/cxf/cxf.js` export and import. Verify: vitest round trip with one hidden and one text field.

## 2. SSH key item

- [ ] 2.1 Build the SSH key section (private key, public key, fingerprint) and show it for type `ssh_key` in the create and edit dialogs. Verify: vitest that the fingerprint is derived and a malformed key is refused.
- [ ] 2.2 Add Generate key pair and Import from file. Verify: vitest generating a pair and parsing the OpenSSH public key; Playwright flow create, save, open, copy public key.
- [ ] 2.3 Update the CXF mapping for the new SSH fields. Verify: vitest round trip.

## 3. Close out

- [ ] 3.1 Add strings to every shipped locale through the writing skill, never `test:l10n:write`. Verify: `npm run test:l10n`.
- [ ] 3.2 Set rows `vault-11` and `vault-14` to built with evidence paths and lines, and archive the change. Verify: parity_verify --strict.

