---
kind: code
depends_on: [ext-vault-browse, ext-vault-edit, ext-share-sync, ext-settings, ext-autofill]
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
  - ext-toolbar-icon
  - ext-passkey-provider
---

## Why

Keepiq stores passkeys (`passkey-item-type`), but no site can use them: the extension shows a passkey with "Signing in with this passkey is not yet supported". Bitwarden's extension is a passkey provider, and "fill in logins, passkeys and one-time codes on any site" is the product promise.

## What Changes

- Mirrors Bitwarden's passkey provider: a page script replaces `navigator.credentials.create()` and `.get()`, relays each ceremony through the content script to the background, and the background creates or signs with a Keepiq passkey after the user chose to.
- **Sign in (`get`)**: a window lists the vault's passkeys for the relying party; picking one signs the assertion in the background from the decrypted passkey key.
- **Save (`create`)**: a window offers "Save passkey"; the background makes an ES256 key pair, saves it as a new `passkey` item (canonical JSON in `key`, RP ID in `url`) and returns the attestation once the server accepted it.
- **Fallback**: "Use your device or hardware key" hands the request, unchanged, to the browser's own authenticator. So do a turned-off setting, an excluded domain, a logged-out account and every request Keepiq does not handle.
- **Locked**: the window opens on the unlock view, then continues. Nothing about which passkeys exist reaches the page or the window before unlock.
- **Conditional mediation**: while a page waits on `get({ mediation: 'conditional' })`, the inline list shows the matching passkeys next to the logins, and the browser's own conditional UI runs alongside, as in Bitwarden.
- **User verification**: a request with `userVerification: 'required'` asks the master password, or the PIN where one is set, unless the user unlocked within this ceremony.
- **Signature counter**: a passkey with a non-zero counter (imported) is saved with the counter plus one before the assertion is returned; new passkeys keep 0, as in Bitwarden.
- **Settings**: "Ask to save and use passkeys" in Notifications, on by default; the existing Excluded domains turn the provider off for those sites too.
- Item detail drops the "not yet supported" note.

## Capabilities

### New Capabilities
- `passkey-provider`: interception, what crosses each boundary, origin and RP ID, the ceremony and its window, signing, creating, the counter, lock, fallback, what a page can learn, the setting and supported Keepiq versions.

### Modified Capabilities
- `inline-list` (ext-autofill): passkey rows during a conditional request, and `webauthn` fields qualify while one is pending.
- `login-capture` (ext-autofill): excluded domains also turn off passkeys.
- `settings` (ext-settings): the "Ask to save and use passkeys" toggle.
- `item-detail` (ext-vault-browse): the passkey section loses the not-yet-supported note.

## Deviations from Bitwarden

- **A passkey is its own item.** Keepiq stores a passkey as a `passkey`-typed secret (`passkey-item-type`), not on a login, so Save always creates a new item; there is no "add to an existing login" and no overwrite prompt.
- **Every Keepiq passkey is discoverable.** The canonical schema has no discoverable flag, so `allowCredentials: []` offers every passkey for the RP ID and `credProps.rk` is always true.
- **AAGUID is all zeros.** Bitwarden has its own registered AAGUID; Keepiq has none, so sites show an unnamed provider.
- **User verification is the master password or the PIN.** Keepiq has no biometrics yet (ext-settings).
- **A request Keepiq does not handle goes to the browser untouched**: an RP ID that is not the origin's host or a registrable suffix of it, an `http:` origin other than `localhost`, a frame with a cross-origin ancestor, `authenticatorAttachment: 'cross-platform'`, or no ES256 in `pubKeyCredParams`. Bitwarden throws `SecurityError` for a bad RP ID; the browser applies its own rules, related origins included, and Keepiq reads nothing from the vault.
- **The page script is on every `http(s)` page while the setting is off.** Bitwarden registers it only while on, with `scripting.registerContentScripts`; that needs host permission for every site, which this extension does not hold (SECURITY.md). While off, it answers every request with the fallback.
- **Excluded domains match by base domain,** as ext-settings stores them; Bitwarden compares the host.

## Keepiq API used

- `POST /api/v1/secrets` (save a new passkey item)
- `PUT /api/v1/secrets/{id}/share-save` (save the counter, through the value save of ext-share-sync, shared or not)
- `GET /api/v1/secrets/{id}` (fresh fetch before a counter save, ADR-002)
- `GET /api/v1/secrets/{id}/write-context` (path and grade for that save)
- `GET /api/v1/suites` (the active suite, checked before encrypting)
- `POST /api/v1/shares/recipient-certificates` and `POST /api/v1/federation/recipient-certificate` (copies of a shared passkey)
- `GET /api/v1/secret-types` (the `passkey` type id, already cached)
- `GET /api/v1/folders` (folder choice on Save, already cached)

All are in ADR-003 and none is newer than `MIN_KEEPIQ_VERSION` (0.3.12); the `passkey` system type is seeded in every release from 0.3.0 on (`lib/Repair/SeedSecretTypes.php`). Nothing is added to `KEEPIQ_FEATURES`, and no Keepiq change is needed.

## Impact

- The page script: `entrypoints/passkey-page.content.ts`, a `world: 'MAIN'` content script on Chrome, and `entrypoints/passkey-page.ts`, an unlisted script the relay injects from a web-accessible resource on Firefox.
- New relay content script `entrypoints/passkey.content.ts` at `document_start`; `entrypoints/background.ts` routes ceremonies; the popup gains a passkey view for the ceremony window; the inline list gains passkey rows.
- New `src/passkey/*`: request checks, the ceremony store, CBOR, authenticator data, attestation, signing, the canonical credential, the counter save.
- `src/messages.ts` gains the `passkey.*` kinds; `src/background/sender.ts` routes them.
- `wxt.config.ts`: the Chrome MAIN-world content script and, on Firefox, `passkey-page.js` in `web_accessible_resources`. No new permission.
- `settings.<id>` gains `askPasskeys`.
- ADR-002 (the page script, where passkey data lives, the counter save to copies), ARCHITECTURE.md and SECURITY.md are updated.
