# Tasks: a passphrase mode for the key generator

## 1. Backend

- [ ] 1.1 Add the EFF large word list under `lib/Resources/` with its licence in `LICENSES/` and `REUSE.toml`. Verify: the REUSE compliance check and a PHPUnit that the list loads 7,776 unique words.
- [ ] 1.2 Add the passphrase branch to `KeyGeneratorService` (word count 4 to 12, separator, capitalise, add digit) and the parameters to `KeyGeneratorController::generate()`. Verify: PHPUnit for bounds, separator handling and a 400 below 4 words.
- [ ] 1.3 Fit the passphrase to the organisation policy (D3) and add `generator_allow_passphrase`. Verify: PHPUnit with each required class and a length floor above the natural length, and a 400 when the mode is switched off.

## 2. Frontend

- [ ] 2.1 Add the Password or Passphrase switch and options to `KeyGeneratorModal.vue`. Verify: vitest on the request body per mode, and a Playwright flow generate a passphrase into a new secret.
- [ ] 2.2 Add the passphrase switch to `OrgPasswordPolicySection.vue`. Verify: vitest that the setting is saved, and `npm run lint`.

## 3. Docs

- [ ] 3.1 Document the passphrase mode and how the policy applies to it. Verify: docs build.

## Acceptance criteria

- A user generates a passphrase of 4 to 12 words with a chosen separator, capitals and a digit, and it lands in the secret's value field.
- With the organisation policy on, every passphrase meets the policy's floor and classes, and an administrator can switch the mode off.
