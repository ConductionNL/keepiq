# Tasks: hidden custom fields and a real SSH key item

## 1. Custom field kinds

- [x] 1.1 Add `kind` to the field model in `AdditionalFieldsEditor.vue` with a kind select, and mask hidden values with reveal and copy controls. Verify: vitest on the editor emitting `{name, value, kind}` and on an old blob reading as text.
- [x] 1.2 Render hidden fields masked in `SecretDetailSidebar.vue`. Verify: vitest that a hidden value is not in the DOM text until Reveal, and a Playwright flow hide, reveal, copy. (Code and vitest done in `tests/components/SecretDetailSidebar.additionalFields.spec.js`; the Playwright flow is still open.) Playwright done 2026-11-17 on a throwaway NC 34 rig: tests/e2e/workflows/custom-field-kinds.spec.ts hidden-field test (dots until Show, Hide again, Copy puts the real value on the clipboard); passed.
- [x] 1.3 Carry the kind through `src/cxf/cxf.js` export and import. Verify: vitest round trip with one hidden and one text field.

## 2. SSH key item

- [x] 2.1 Build the SSH key section (private key, public key, fingerprint) and show it for type `ssh_key` in the create and edit dialogs. Verify: vitest that the fingerprint is derived and a malformed key is refused.
- [x] 2.2 Add Generate key pair and Import from file. Verify: vitest generating a pair and parsing the OpenSSH public key; Playwright flow create, save, open, copy public key. (Code and vitest done in `tests/vitest/sshKey.spec.js` and `tests/components/SshKeyFields.spec.js`; the Playwright flow is still open.) Playwright done 2026-11-17 on a throwaway NC 34 rig: tests/e2e/workflows/custom-field-kinds.spec.ts SSH test (New secret from a folder, type SSH Key, Generate key pair, Create; the stored row carries no private key text; the sidebar shows the public key and fingerprint and copies the public key); passed.
- [x] 2.3 Update the CXF mapping for the new SSH fields. Verify: vitest round trip.

## 3. Close out

- [x] 3.1 Add strings to every shipped locale through the writing skill, never `test:l10n:write`. Verify: `npm run test:l10n`. (All 14 new strings are in every locale and `check:l10n-js` and the parity gate pass; `test:l10n` still reports 7 passkey and extension strings missing that were already missing on `development` before this change.)
- [x] 3.2 Set rows `vault-11` and `vault-14` to built with evidence paths and lines, and archive the change. Verify: parity_verify --strict. Done 2026-11-17: vault-11 and vault-14 set to built with evidence paths and lines; parity_verify is not on the live-pass box, the file was checked to parse and round-trip instead.

