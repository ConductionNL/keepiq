---
kind: code
depends_on: [ext-vault-edit, ext-share-sync, ext-settings, ext-totp-autofill]
chain:
  - ext-accounts-and-unlock
  - ext-vault-browse
  - ext-vault-edit
  - ext-share-sync
  - ext-i18n
  - ext-generator
  - ext-send
  - ext-theme
  - ext-settings
  - ext-autofill
  - ext-totp-autofill
  - ext-totp-capture
---

## Why

ext-totp-autofill fills and copies a login's code, but the seed can only get onto a login in the Keepiq web app. A user setting up two-factor sign-in on a site has the site's QR code in front of them and the login in the extension; they should be able to save the key there and then type the first code with the extension's help.

## What Changes

- Mirrors Bitwarden's "Authenticator key (TOTP)" field with its "Scan QR code" button in the add and edit form of a login. The seed goes in the login's own `totp` field (keepiq#1324, #1325), with the web app's rules (`vault-login-totp-codes`, `login-one-time-codes`).
- **Authenticator key field.** On a Login in the add and edit form, the user pastes an `otpauth://totp/` link or a base32 secret. It is validated and normalised as the web app does.
- **Scan QR code on page.** The extension takes a picture of the visible part of the tab the popup opened over, reads the QR code in it in the popup, and puts the key in the field. Only an `otpauth://totp/` QR code is accepted. The picture is never stored or sent.
- **A saved key stays hidden.** The edit form shows the key as "Saved" with a trash button, never the seed. On a Login the trash brings back the input, so a new key replaces the saved one and an empty one removes it; another type that holds a key can only remove it.
- **Clone leaves the key out.** A clone is usually another account on the same site, which has its own key.
- **Share-save carries `totp`.** A changed key reaches every copy in the same request. Partner shares always get the whole value with `totp` and `passkeys` when the partner stores them; adding a key to a login shared with a partner that cannot store one is refused before anything is written.
- **No new standalone `totp` items.** The Type select of the add form and the Default item type setting no longer offer Authenticator, as in the web app. Existing ones stay editable.
- **Permission `activeTab`** on both browsers, for the picture. It shows no install prompt.
- Out of scope:
  - Noticing a two-factor setup page by itself and offering to save its key. No standard marks such a page, a QR code can only be read from its pixels, and reading every page's images or taking pictures without a click would watch pages the user never asked about. An `otpauth://` link is the only reliable signal and few sites show one; a later change may offer a prompt for it.
  - Picking a QR image file, as the web app offers: a file dialog closes the toolbar popup.
  - Editing `passkeys`; ext-passkey-provider writes them.

## Capabilities

### New Capabilities
- `totp-capture`: the Authenticator key field, Scan QR code on page, the hidden saved key with its trash button, saving the key encrypted on create and edit, and a clone without the key.

### Modified Capabilities
- `item-editing` (ext-vault-edit): the add form's Type select drops `totp`; Clone leaves the authenticator key out.
- `share-sync` (ext-share-sync): `totp` is a value field of share-save, and partner shares get `totp` and `passkeys` in the whole value, refused for a partner that cannot store a key the change adds.
- `settings` (ext-settings): Default item type drops `totp`, and a stored `totp` reads as Login.

## Deviations from Bitwarden

- The edit form never shows a saved seed; a trash button takes it off, and a new key entered then replaces it. Keepiq's `login-one-time-codes` requires the hidden seed; Bitwarden shows the key. The web app offers Replace and Remove as two buttons; one trash button does both here, as replacing a key means removing the old one.
- Clone leaves the key out; Bitwarden copies it. Not forced.
- Only an `otpauth://totp/` QR code is accepted, as in the web app; Bitwarden also takes Steam and HOTP links, which Keepiq's code generator cannot use.
- Scanning works only in the toolbar popup, not the pop-out window: `activeTab` is granted to the popup's tab, and the pop-out has no page under it. Bitwarden's pop-out has the same limit.

## Keepiq API used

- `POST /api/v1/secrets` with `totp`, and `PUT /api/v1/secrets/{id}` with `totp` (ADR-003).
- `PUT /api/v1/secrets/{id}/share-save` with `totp` in the patch and copies, and `totp` and `passkeys` in partner shares; 409 `partner_upgrade_required` with `requiredVersion`.
- `POST /api/v1/federation/recipient-certificate`, its `valueFields`.

All since Keepiq 0.3.12 (keepiq#1325), the minimum, so nothing is gated in `KEEPIQ_FEATURES`. No Keepiq backend change is needed.

## Impact

- `src/totp/authenticator-key.ts` (new): normalise, validate and read a key from QR text, a port of the web app's `authenticatorKey.js`.
- `src/totp/qr-scan.ts` (new): the picture of the visible tab to an `otpauth://totp/` link, with jsQR.
- `entrypoints/popup/components/AuthenticatorKeyField.tsx` (new) and `entrypoints/popup/views/ItemForm.tsx`: the field, its states and the Type select.
- `src/messages.ts`: `totp` on `ItemDraft` and `ItemPatch`, `totpSaved` on `EditableItem`, the `partner_upgrade_required` write error.
- `src/vault/write.ts`: `totp` on create and update, `totpSaved` on fetch, nothing of the key on a clone.
- `src/vault/share-save.ts` and `src/vault/verified-partners.ts`: `totp` as a value field, `totp` and `passkeys` in partner shares, `valueFields`.
- `entrypoints/popup/views/settings/Vault.tsx`: Default item type without `totp`.
- `wxt.config.ts`: `activeTab`. `package.json`: `jsqr` 1.4.0, the web app's version.
- `test-site/`: a page whose QR code holds a website link.
