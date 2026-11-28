---
kind: code
---

# One-time codes and passkeys on a login, in the mobile apps

## Why

keepiq#1324 moved a one-time code and passkeys onto the login they belong to, as two encrypted fields on the secret, `totp` and `passkeys`. The backend (keepiq#1325) and the web app (keepiq#1326, keepiq#1327, change `vault-login-totp-codes`) have them. The mobile apps still know only `key`, `login` and `additionalFields`: they read codes and passkeys from standalone Authenticator and Passkey items, save every new passkey as a standalone item, and drop a login's `totp` and `passkeys` from their offline store. This change is keepiq#1329.

## What Changes

- **Pairing needs a server with the fields.** Pairing refuses a server whose pair response does not list the `totp-field` and `passkeys-field` capabilities, and an account paired earlier is checked again before it writes a login's code or passkeys. There is no fallback to standalone items for new codes or passkeys.
- **The shared core carries both fields.** Rows, the offline store (its first migration, followed by a full sync), the opened item, the autofill index and the create and update bodies carry `totp` and `passkeys` as ciphertext.
- **The detail screen** shows a login's live code, and one row per passkey with the date it was added. Passkeys show nowhere else. A passkey can be deleted where the user may edit the login.
- **The edit screen** gets a write-only Authenticator key field on logins, and no longer offers the Authenticator or Passkey type for new items.
- **Autofill** fills a login's code from its `totp` field.
- **The passkey provider** signs with passkeys on a login, writes a counter back to that login, and saves a new passkey onto the one matching login, else onto a new login.
- **Standalone items** keep being read, filled and signed with. Nothing moves them onto a login (keepiq#1328 was dropped).

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `mobile-pairing-and-unlock`: a server without the `totp-field` and `passkeys-field` capabilities cannot be paired, and an account paired with one earlier takes no write of a login's code or passkeys.
- `mobile-shared-core`: the offline store keeps a login's `totp` and `passkeys`.
- `mobile-vault`: a login's code and passkeys on the detail screen, the Authenticator key field, and no new Authenticator or Passkey items.
- `mobile-system-autofill`: a one-time code also comes from a login's own `totp` field.
- `mobile-passkey-provider`: passkeys on a login, and new passkeys saved onto a login.

These capabilities come from `clients-mobile-apps`, which is not archived yet; this change archives after it.

## Impact

- **Backend**: none. The fields, their capabilities in `POST /api/v1/extension/pair` and the batch import come from keepiq#1325 and keepiq#1327.
- **Shared core** (`mobile/shared`): `VaultRow`, `StoredSecret`, `Vault.sq` and a first migration `1.sqm`, `ItemCodec`, `ItemParts`, `DecryptedItem`, `AutofillEntry`, `AutofillIndex`, `AutofillSites`, `Passkeys`, `PasskeysWrite` (the read-modify-write of a login's `passkeys`, with `baseUpdatedAt`), `PairResponse`, pairing in `KeepiqClient`, and `KeepiqApi.requireLoginFields` (the capability check before a write).
- **Android** (`mobile/android`): `ItemDetailScreen`, `ItemEditScreen`, `CredentialEntries`, `CredentialProviderActivity`, the autofill service, and new strings.
- **iOS**: compiles against the same core and gets the store migration, the pairing check, the code fill from a login through `AutofillSites` and the passkey provider through `ApplePasskeys`. One line of its screens changes: the edit view opens an item with `openForEdit`, so it never decrypts a login's saved key. The screens are otherwise not changed here; they follow in keepiq#1353.
- **Security**: both fields stay ciphertext in the store and the index, are decrypted on the device only when used, and no seed or passkey private key is ever shown. A use-only copy shows its code but no passkeys.
- **Not in this change**: share-save in the mobile apps, so a passkey counter written back on a shared login reaches only the user's own copy, as a standalone passkey's does today; and scanning a QR code for the Authenticator key. Both get their own issue.
