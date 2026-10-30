# Tasks: one-time codes and passkeys on a login, in the mobile apps

## 1. Shared core

- [ ] 1.1 Read `capabilities` in `PairResponse` and refuse pairing without `totp-field` and `passkeys-field` (D1). Verify: `KeepiqClientTest`.
- [ ] 1.2 Carry `totp` and `passkeys` as ciphertext in `VaultRow`, `StoredSecret` and `Vault.sq`, with the migration `1.sqm` that adds the columns and clears the sync state (D2, D3). Verify: `VaultSyncTest`, the store tests on both platforms.
- [ ] 1.3 Open `totp` and `passkeys` in `ItemCodec.open`, with the use-only rule (D4). Verify: `VaultRepositoryTest`.
- [ ] 1.4 Send `totp` and `passkeys` in the create and update bodies only when set or changed, `''` to clear, never an encrypted empty string (D5). Verify: `VaultRepositoryTest`.

## 2. Autofill and passkey provider

- [ ] 2.1 Keep both fields and `readOnly` in `AutofillEntry`, offer same-site logins with a code in `candidates(totp = true)`, and generate their code in `AutofillSites.code` (D10). Verify: `AutofillIndexTest`.
- [ ] 2.2 Offer a login's passkeys in `Passkeys.candidates` and in the passkey check of `CredentialEntries`, and write a counter back to the login fresh (D11). Verify: `PasskeysTest`.
- [ ] 2.3 Save a new passkey onto the one matching login, else a new login, with the excluded-credentials check over both sources (D12). Verify: `PasskeysTest`.
- [ ] 2.4 Extend the instrumented tests for the login path. Verify: `PasskeyProviderTest`, `SystemAutofillTest`.

## 3. Android screens

- [ ] 3.1 Show a login's code with `TotpRow`, and one row per passkey with its added date (D6). Verify: `ScreensTest`.
- [ ] 3.2 Delete a passkey behind a confirmation where the user may edit the login (D7). Verify: `ScreensTest`, `VaultRepositoryTest`.
- [ ] 3.3 Add the write-only Authenticator key field, and leave the Authenticator and Passkey types out of a new item's type list (D8, D9). Verify: `ScreensTest`, `VaultFlowsTest`.
- [ ] 3.4 Accessibility: content descriptions on the new rows and buttons, and the focus move after Delete. Verify: `AccessibilityAuditTest`.

## 4. Follow-ups

- [x] 4.1 File an issue for share-save in the mobile apps, covering every edit of a shared item and the passkey counter. Filed as keepiq#1347.
- [x] 4.2 File an issue for scanning a QR code for the Authenticator key in the Android app. Filed as keepiq#1348.

## Acceptance criteria

- A login holding a code and passkeys shows the live code and each passkey's added date on the phone, and keeps both through sync, offline reading and edits.
- The phone fills a login's code, signs in with a login's passkey, and saves a new passkey onto the matching login or a new one.
- Standalone Authenticator and Passkey items still show, fill and sign.
- A server without the fields cannot be paired.
