# Tasks: one-time codes from a seed kept on a login

## 1. Web app

- [ ] 1.1 Add a helper `seedFromAdditionalFields(fields)` in `src/totp/` that returns the `totp` value (or legacy `otp` / `otpauth`) and the remaining fields. Verify: vitest for each key name, an empty object and a non-seed value.
- [ ] 1.2 Add the Authenticator key field to `SecretCreateDialog.vue` and `SecretEditDialog.vue` for login-type secrets, store it under `totp` in the encrypted additional fields, and keep it out of `AdditionalFieldsEditor.vue`'s free list. Verify: vitest that the saved blob holds `totp`, and a Playwright flow add a seed to a login.
- [ ] 1.3 Show the code row with `TotpDisplay` in `SecretDetailSidebar.vue` when a login carries a seed, and drop the raw seed from the Additional fields list. Verify: Playwright flow open the fixture login, a 6-digit code and a countdown are shown, the seed text is not.
- [ ] 1.4 Decode a QR image picked in the field, client-side, with the new QR dependency. Verify: vitest with a generated QR of a test URI, and the npm licence and audit checks.

## 2. Extension and import

- [ ] 2.1 In `service-worker.js`, compute the code from the filled login's own seed before calling `totpCodeForHost()`. Verify: extension unit test with two logins on one host with different seeds.
- [ ] 2.2 In `src/cxf/cxf.js`, attach a CXF TOTP credential to the login of the same item instead of making a separate row without an address. Verify: vitest on a CXF fixture with a login and a TOTP credential.

## 3. Docs

- [ ] 3.1 Document the field in `docs/` next to the Authenticator item, with the note on keeping both factors in one item. Verify: docs build.

## Acceptance criteria

- A login with a seed shows a live code and a countdown in the detail sidebar, and the copy button copies the code.
- A Bitwarden import with seeds shows codes without a re-import.
- The extension fills the code of the login it filled, even with two logins for the same host.
- No seed or code reaches the server in any form.
