# Tasks: one-time codes and passkeys on a login

## 1. Entering a seed

- [x] 1.1 Add `src/totp/authenticatorKey.js`: normalise an entered key, refuse one the TOTP parser cannot read, and accept only a parseable `otpauth://totp/` URI from QR text. Verify: `tests/vitest/authenticator-key.spec.js`.
- [x] 1.2 Add `src/totp/qrImage.js`, refusing an image over 10 MB and decoding a picked image in the browser with `jsqr` loaded in its own chunk. Verify: `tests/vitest/authenticator-key.spec.js` and `tests/components/AuthenticatorKeyField.spec.js`, and the npm licence and audit checks for `jsqr`.
- [x] 1.3 Add `AuthenticatorKeyField.vue` to `SecretCreateDialog.vue` and `SecretEditDialog.vue` for the Login type only: a saved key is never loaded into the form, Replace and Remove, and an untouched field sends nothing. Verify: `tests/dialogs/SecretCreateDialog.totp.spec.js`, `tests/dialogs/SecretEditDialog.totp.spec.js`.
- [x] 1.4 Encrypt `totp` and `passkeys` in the secret store on create and update, `''` to clear and never an encrypted empty string. Verify: `tests/store/secret-value-fields.spec.js`.

## 2. Showing the code and passkeys

- [x] 2.1 Show the live code with `TotpDisplay` in `SecretDetailSidebar.vue` from the `totp` field, never the seed. Verify: `tests/components/SecretDetailSidebar.loginTotpPasskeys.spec.js`.
- [x] 2.2 Show the read-only code and passkey rows for any type whose row holds a value, not only logins, and offer Remove only for a saved key on a non-login row in the edit dialog. Verify: `tests/components/SecretDetailSidebar.loginTotpPasskeys.spec.js`, `tests/dialogs/SecretEditDialog.totp.spec.js`.
- [x] 2.3 Add `PasskeyList.vue` and `PasskeyDeleteDialog.vue`: metadata only, no private key in any mode, a confirmed Delete where the user may edit the login that saves the remaining entries or `''`. Verify: `tests/components/PasskeyList.spec.js`.
- [x] 2.4 Use-only copies show and copy the code and list passkeys; use-only, view-only, read-only and federated read-only copies get no Delete. Verify: `tests/components/SecretDetailSidebar.loginTotpPasskeys.spec.js`.
- [x] 2.5 Add `LoginExtrasMarkers.vue` to the list, card and table views, from the presence of ciphertext only, with a text alternative per icon and the markers named in the row's accessible name in the list and card views.
- [x] 2.6 Move focus to the replacing control after Replace, Remove or keeping the saved key, and to the passkey list (else the Edit button, else the detail) after a passkey Delete. Verify: `tests/components/AuthenticatorKeyField.spec.js`, `tests/components/SecretDetailSidebar.loginTotpPasskeys.spec.js`. Verify: `tests/components/SecretListItem.spec.js`, `tests/views/SecretList.listViews.spec.js`.

## 3. Carrying both fields

- [x] 3.1 Carry both fields in share-save, refusing a value a federated partner does not store and mapping the 409 `partner_upgrade_required` to a readable message. Verify: `tests/store/shareSave.valueFields.spec.js`.
- [x] 3.2 Build every recipient copy through `src/services/recipientCopy.js` (direct, bulk, group and team-folder shares), and carry both fields on ownership transfer and new federated shares. Verify: `tests/dialogs/SecretShareDialog.spec.js`, `tests/store/groupShare.spec.js`.
- [x] 3.3 Keep link shares to `key`, `login` and `additionalFields`. Verify: the link share store builds its payload from those three only.
- [x] 3.4 Carry both fields in version history and restore; the version details view shows only "Authenticator key set" and passkey metadata. Verify: `tests/store/secretVersion.spec.js`, `tests/dialogs/VersionDetailsDialog.loginFields.spec.js`.
- [x] 3.5 Carry both fields in the offline cache and the offline edit queue, and in encryption suite migration. Verify: `tests/vitest/offline-snapshot.spec.js`, `tests/store/offlineQueue.spec.js`, `tests/vitest/migration-pipeline.spec.js`.
- [x] 3.6 Keep password health on `key` only. Verify: `tests/store/health.spec.js`.

## 4. Types and gating

- [x] 4.1 Drop the Authenticator and Passkey types from the create dialog and the default item type setting, falling back to Login for a saved default of either, while existing items keep showing, editing, sharing and filtering. Verify: `tests/components/DefaultsSection.spec.js`, `tests/store/userPreferences.spec.js`.
- [x] 4.2 Gate the entry field and the passkey Delete action on `valueFieldsSupported()`. Verify: `tests/vitest/value-fields.spec.js`.

## 5. Docs

- [ ] 5.1 Document the Authenticator key field, the passkeys on a login, the use-only behaviour and that new Authenticator and Passkey items are no longer created, in `docs/`. Verify: docs build.

## 6. Import and export (keepiq#1327)

- [x] 6.1 Put Bitwarden `login.totp` and `login.fido2Credentials[]` (JSON) and `login_totp` (CSV), and KeePass `otp` and `TimeOtp-*`, on the login's `totp` and `passkeys` fields through `src/import/loginValueFields.js`, accepting only a seed the TOTP parser reads (others stay additional fields), folding KeePass period, length and algorithm into an otpauth URI when not the defaults, and giving a passkey-only Bitwarden login its RP id as URL. Verify: `tests/vitest/import-parsers.spec.js`, `tests/vitest/passkey.spec.js`.
- [x] 6.2 Attach a CXF item's TOTP and passkeys to its first accepted login, else its first accepted other row, else a new login with an empty password, whatever the credential order and never to a rejected row; read CXF 1.0 TOTP fields and reject an unusable algorithm or digit count; the first seed wins, a different one and a clashing passkey are rejected with a reason, and a repeated passkey is kept once. Verify: `tests/vitest/cxf.spec.js`.
- [x] 6.3 Export a secret's `totp` and `passkeys` as CXF 1.0 credentials of its own CXF item, report a stored code or passkey that cannot be exported, and keep standalone Authenticator and Passkey items exporting as items of their own. Verify: `tests/vitest/cxf.spec.js`, `tests/vitest/cxf-passkey-roundtrip.spec.js`.
- [x] 6.4 Remove `expandTotpRows` and the `(TOTP)` and `(passkey)` names; the import commit encrypts `totp` and `passkeys` and sends them only when set; the preview and the duplicates list mark rows with `LoginExtrasMarkers`. Verify: `tests/store/import.spec.js`, `tests/dialogs/ImportWizardDialog.spec.js`.
- [x] 6.5 Carry both fields in the backup and GDPR payload (`serializeVault`) and read them back on backup restore; write the seed in a `totp` CSV column that the generic CSV import maps back. Verify: `tests/vitest/export-serializer.spec.js`, `tests/vitest/export-gdprPackage.spec.js`, `tests/vitest/import-backupParser.spec.js`, `tests/vitest/import-parsers.spec.js`, `tests/store/import.spec.js`.
- [x] 6.6 The batch import endpoint (`ImportService::commitChunk`) passes `totp` and `passkeys` on to the secret it creates, and checks both as ciphertext. Done in fdca23d5. Verify: `ImportServiceTest::testTotpAndPasskeysReachTheCreatedSecret`, `ImportServiceTest::testPlaintextTotpOrPasskeysRejected`.

## 7. Deferred, not in this change

- [ ] 7.1 Clone and print carry both fields. Deferred: neither feature exists yet; belongs to `vault-item-clone-preview-and-print`.
- [ ] 7.2 The Passkey type filter also finds logins with passkeys. Deferred: the server cannot see inside the ciphertext; needs a backend query design.
- [ ] 7.3 Guided move of standalone Authenticator and Passkey items, and of additional fields named `totp` or `otp`, onto their login. Deferred to keepiq#1328.
- [ ] 7.4 Android reads and writes both fields. Deferred to keepiq#1329.

## Acceptance criteria

- A login can hold a seed and passkeys, shows the live code and the passkeys, and keeps them through edit, share, version restore, ownership transfer, offline editing and suite migration.
- The seed is never shown after saving, and no login passkey private key is ever shown.
- A use-only recipient can copy the code but never sees the seed.
- Link shares carry neither field; a federated partner that does not store them is never sent a value.
- New Authenticator or Passkey items cannot be created from the web app; existing ones work unchanged.
- Importing a Bitwarden, KeePass or CXF export with codes and passkeys gives one login per entry with its code and passkeys on it, and no `(TOTP)` or `(passkey)` items; a CXF export and re-import of such a login gives the same login back.
