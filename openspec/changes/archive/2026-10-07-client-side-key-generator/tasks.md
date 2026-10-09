# Tasks: generate keys and passphrases in the browser

## 1. Generator module

- [x] 1.1 Port the password and regex generator with the policy clamp to `src/generator/generator.js`. Verify: `tests/vitest/generator.spec.js` mirrors `KeyGeneratorServiceTest`.
- [x] 1.2 Add the passphrase mode with the EFF large word list and its licence. Verify: vitest for bounds, separator, capitals, digit and the policy, and the REUSE annotation.

## 2. Web app

- [x] 2.1 Generate in `KeyGeneratorModal.vue` without a request, with the Password or Passphrase switch. Verify: `tests/dialogs/KeyGeneratorModal.spec.js` asserts no POST, and the e2e `tests/e2e/workflows/key-generator.spec.ts`.
- [x] 2.2 Add `generator_allow_passphrase` to the policy service and the admin section. Verify: PHPUnit `AdminSettingsServiceTest` and `SettingsServicePolicyTest`, vitest `OrgPasswordPolicySection.spec.js`.
- [x] 2.3 Mark `POST /api/v1/generate-key` deprecated. Verify: no caller left in `src/` or `browser-extension/`.

## 3. Close out

- [x] 3.1 Translate the new strings (English source, Dutch). Verify: `npm run test:l10n` and `npm run check:l10n-js`.
- [x] 3.2 Set row `health-08` to built and supersede `health-passphrase-generator`. Verify: the row and the removed change directory.
