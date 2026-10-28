# Design: one-time codes and passkeys on a login

## Context

- keepiq#1325 gave a secret two more encrypted value fields, `totp` and `passkeys`, carried by CRUD, share-save, sharing, federation, versions, suite migration and backups on the server. Each is null when it holds no value, `''` when explicitly not set, and RSA ciphertext otherwise. A federated partner advertises which of them it stores; the server refuses a value a partner cannot store with 409 `partner_upgrade_required`.
- `src/totp/totp.js` parses an `otpauth://totp/` URI or bare base32 secret and computes an RFC 6238 code with WebCrypto, in memory. `src/components/TotpDisplay.vue` renders a code from a `seed` prop.
- `src/components/PasskeyDisplay.vue` renders one credential in the canonical schema of the `passkey-item-type` spec, with a gated reveal of the private key for standalone items.
- Every share path builds a recipient copy from one plain snapshot, now in `src/services/recipientCopy.js`.

## Goals / Non-Goals

**Goals**
- A login holds a seed and passkeys, shows the live code and the passkeys, and keeps them through edit, share, version restore, ownership transfer, offline editing and suite migration.
- A use-only recipient can copy the code but never sees the seed or a private key.
- The web app no longer creates Authenticator or Passkey items; existing ones work unchanged.

**Non-Goals**
- HOTP (counter-based) codes. `totp.js` stays TOTP only.
- The browser extension and the Android app; they adopt the fields in their own changes.
- Anything listed under Deferred below.

## Decisions

**D1. The seed and passkeys live in their own encrypted fields.** `totp` holds the seed, `passkeys` a JSON array of credential objects in the canonical passkey schema. This reverses the earlier design of a reserved `totp` key inside the additional fields: a field the server knows exists lets share-save, federation gating, versions and the list marker work on it without decrypting, while its content stays ciphertext.

**D2. No encrypted empty string.** The web app sends `''` to clear a field and null on a copy that has no value, and never encrypts an empty string (`BLOB_FIELDS` in `src/utils/valueFields.js`). An untouched field is left out of the patch.

**D3. The seed is write-only after saving.** `AuthenticatorKeyField.vue` never loads a saved seed into the form; it shows that a key is saved and offers Replace (enter a new one) and Remove (send `''`). The sidebar, the Additional fields box and the version details view never render it. A key is validated by the same parser that later generates codes, so a key that saves always yields a code.

**D4. QR images are read in the browser.** `src/totp/qrImage.js` refuses an image over 10 MB before reading it, then draws the picked image to a canvas, scaled to at most 1600 px on its longest side, and decodes it with `jsqr` 1.4.0, imported lazily into its own chunk. No image or decoded text leaves the browser, and only a parseable `otpauth://totp/` URI is accepted, so a QR code for a website or a payment is never saved as a key.

**D5. Passkeys are listed, not edited.** Passkeys come from a provider or an import, never typed in. `PasskeyList.vue` shows each through `PasskeyDisplay` with the private key row left out, in every mode. Delete sits behind `PasskeyDeleteDialog.vue` and saves every other entry exactly as stored, or `''` when none remain (`src/passkey/loginPasskeys.js`).

**D6. Entry on logins, display wherever a value is.** Entering a key is offered only for the Login type. The read-only code and passkey rows show, in a box of their own in the sidebar, for any type whose row holds a value, so a value set by another client or a future import on another type is never hidden. On such a row the edit dialog shows that a key is saved and offers Remove only.

**D6a. Focus follows the change.** After Replace, Remove or keeping the saved key, focus moves to the control that took the chosen one's place. After a passkey Delete, focus moves to the passkey list, or when none remain to the Edit button, else the detail itself. Vault rows in the list and card views name their markers in the row's accessible name.

**D7. Use-only copies show the code.** The code is what a use-only holder needs to sign in, so it shows and copies; the seed and the private keys are never rendered in any mode, which is what makes this safe. View-only, read-only and federated read-only copies follow the login's existing rules: no Delete, no edit.

**D8. Link shares leave both fields out.** A link recipient decrypts the whole shared value in the browser, so a seed or a passkey in a link payload would hand out the second factor and the private keys. The link payload stays `key`, `login` and `additionalFields`.

**D9. Federation follows the partner's advertisement.** A partner that does not advertise a field gets null for it when the patch sets no value. Setting a value, or newly sharing a login that already holds one, to such a partner is refused before anything is sent, and the server's 409 `partner_upgrade_required` maps to the same readable message naming the release the partner needs.

**D10. No new Authenticator or Passkey items.** `NOT_CREATABLE_TYPE_NAMES` in `src/utils/secretTypes.js` removes `totp` and `passkey` from the create dialog and the default item type setting; a saved default of either falls back to Login. Existing items keep showing, editing, sharing and filtering.

**D11. A client-side version gate.** `valueFieldsSupported()` compares the `appVersion` initial state with `TOTP_PASSKEYS_FIELDS_SINCE` (placeholder `0.3.12`, kept in step with the backend's `Application::TOTP_PASSKEYS_FIELDS_SINCE` until the release is cut). An `-unstable` build and a missing version pass. The web app is served by its own server, so the gate only guards a mixed deploy; it hides the entry field and the passkey Delete action.

**D12. Additional fields named `totp` or `otp` are left alone.** The Bitwarden import has written a login's seed into the additional fields as `totp`. Those fields are no longer read as the seed and keep showing as ordinary fields; moving them into the `totp` field belongs to the guided move of keepiq#1328.

**D13. The list marker reads presence only.** `LoginExtrasMarkers.vue` checks whether a row's `totp` or `passkeys` holds ciphertext, decrypts nothing, and gives each icon a text alternative. It shows in the list, card and table views.

## Security and zero-knowledge

Both fields are RSA-encrypted in the browser with the holder's public key, using the existing chunked RSA encryption, and decrypted only in the browser. The seed, its derived key and the code are never sent to the server or kept in browser storage, and are dropped on lock, the same contract as the Authenticator item. Password health decrypts only `key`, so seeds and passkeys are never scored, reuse-matched or breach-checked. No login passkey private key is ever rendered, and link shares carry neither field.

## Risks / Trade-offs

- Keeping the password and the second factor in one item puts both behind the vault key. That is the user's choice, as in every competitor.
- A seed that is write-only in the UI cannot be read back to set up a second authenticator app. Replace covers the usual case of re-enrolling with a new seed from the site.
- The version gate uses a placeholder release number until the release is cut; a wrong number would hide the field on a server that has it, or show it on one that does not.

## Deferred

- **Clone and print.** Neither feature exists in the web app; carrying the fields belongs to `vault-item-clone-preview-and-print`.
- **Passkey type filter finding logins with passkeys.** The server cannot see inside the ciphertext, so the filter needs a backend query design.
- **Import and export.** Carrying the new fields and no longer creating `(TOTP)` and `(passkey)` items belongs to keepiq#1327.
- **Guided move of existing standalone items**, and of additional fields named `totp` or `otp`, onto their login belongs to keepiq#1328.
- **Android** belongs to keepiq#1329; the browser extension adopts the fields in its own changes.

## Seed data

Keepiq owns its tables (keepiq ADR-001) and has no OpenRegister register. No fixture change is needed: tests build their own logins with a published RFC 6238 test vector.

## Migration

None in the web app. The columns and their migration come from keepiq#1325; existing secrets read as having no seed and no passkeys.
