# Tasks: one-time codes from a seed kept on a login

## 1. Web app

- [x] 1.1 Add a helper `seedFromAdditionalFields(fields)` in `src/totp/` that returns the `totp` value (or legacy `otp` / `otpauth`) and the remaining fields. Verify: vitest for each key name, an empty object and a non-seed value.
- [x] 1.2 Add the Authenticator key field to `SecretCreateDialog.vue` and `SecretEditDialog.vue` for login-type secrets, store it under `totp` in the encrypted additional fields, and keep it out of `AdditionalFieldsEditor.vue`'s free list. Verify: vitest that the saved blob holds `totp` (tests/dialogs/SecretDialogs.totpSeed.spec.js); the Playwright flow is task 5.2.
- [x] 1.3 Show the code row with `TotpDisplay` in `SecretDetailSidebar.vue` when a login carries a seed, and drop the raw seed from the Additional fields list. Verify: vitest (tests/components/SecretDetailSidebar.loginSeed.spec.js); the Playwright flow is task 5.3.
- [x] 1.4 Decode a QR image picked in the field, client-side, with the new QR dependency. Verify: vitest with a generated QR of a test URI, and the npm licence and audit checks.

## 2. Extension and import

- [x] 2.1 In `service-worker.js`, compute the code from the filled login's own seed before calling `totpCodeForHost()`. Verify: extension unit test with two logins on one host with different seeds.
- [x] 2.2 In `src/cxf/cxf.js`, attach a CXF TOTP credential to the login of the same item instead of making a separate row without an address. Verify: vitest on a CXF fixture with a login and a TOTP credential.

## 3. Docs

- [x] 3.1 Document the field in `docs/` next to the Authenticator item, with the note on keeping both factors in one item. Verify: docs build in CI (no local build, lane rule).

## 4. Refusals and seed data

- [x] 4.1 A seed follows the password's rules: another user's login, an application's secret and a missing id answer the same 404 and write nothing; signed out answers 401; a blocked suite withholds the blob with the password; the blob is never logged. Verify: tests/Unit/Controller/SecretSeedRefusalTest.php (real controller and services, mappers as doubles).
- [x] 4.2 Dev fixture: a login for `example.com` carrying the RFC 6238 test seed (lib/Repair/SeedDevelopmentSecrets.php).

## 5. Live instance (owed)

- [ ] 5.1 Run the dev seed repair step on a local instance (`occ maintenance:repair`, appstore off) and open the fixture login "Example (with one-time code)".
- [ ] 5.2 Playwright: edit a login, paste an `otpauth://totp/` URI into Authenticator key, save; the request body carries only the encrypted additional fields; reopening shows the key as set.
- [ ] 5.3 Playwright: open the fixture login; a 6-digit code and a countdown are shown and the seed text is not.
- [ ] 5.4 Extension: two logins for one host with different seeds; filling the second fills and copies the second login's code.

## Acceptance criteria

- A login with a seed shows a live code and a countdown in the detail sidebar, and the copy button copies the code.
- A Bitwarden import with seeds shows codes without a re-import.
- The extension fills the code of the login it filled, even with two logins for the same host.
- No seed or code reaches the server in any form.
