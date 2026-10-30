---
kind: code
depends_on: [ext-vault-browse, ext-vault-edit, ext-share-sync, ext-settings, ext-autofill, ext-totp-capture]
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
  - ext-totp-autofill
  - ext-totp-capture
  - ext-passkey-provider
---

## Why

Since keepiq#1324 a login keeps its passkeys in its own encrypted `passkeys` field, but no site can use them. Bitwarden's extension is a passkey provider, and "fill in logins, passkeys and one-time codes on any site" is the product promise.

## What Changes

- Mirrors Bitwarden's passkey provider: a page script replaces `navigator.credentials.create()` and `.get()`, relays each ceremony through the content script to the background, and the background creates or signs with a Keepiq passkey after the user chose to.
- **Passkeys live on a login** (keepiq#1325): the `passkeys` field holds a JSON array of credentials in the canonical schema of `passkey-item-type`. Standalone `passkey` items are never offered or created; no Keepiq client creates them any more.
- **Sign in (`get`)**: a window lists the passkeys on the vault's logins for the relying party; picking one signs the assertion in the background from the decrypted passkey key.
- **Save (`create`)**: a window offers the matching logins and "Save as new login", as Bitwarden does; the background makes an ES256 key pair, adds it to the chosen login's `passkeys` or creates a login holding it, and returns the attestation once the server accepted it.
- **Fallback**: "Use your device or hardware key" hands the request, unchanged, to the browser's own authenticator. So do a turned-off setting, an excluded domain, a logged-out account and every request Keepiq does not handle.
- **Locked**: the window opens on the unlock view, then continues. Nothing about which passkeys exist reaches the page or the window before unlock.
- **Conditional mediation**: while a page waits on `get({ mediation: 'conditional' })`, the inline list shows the matching passkeys next to the logins, and the browser's own conditional UI runs alongside, as in Bitwarden.
- **User verification**: a request with `userVerification: 'required'` asks the master password, or the PIN where one is set, unless the user unlocked within this ceremony.
- **Signature counter**: a passkey with a non-zero counter (imported) is saved with the counter plus one before the assertion is returned; new passkeys keep 0, as in Bitwarden. Every write saves the login's `passkeys` whole, the other entries unchanged.
- **Settings**: "Ask to save and use passkeys" in Notifications, on by default; the existing Excluded domains turn the provider off for those sites too.
- **Item detail** lists a login's passkeys by relying party, user name and date, never a private key; a standalone passkey item says Keepiq signs in only with passkeys on a login.

## Capabilities

### New Capabilities
- `passkey-provider`: interception, what crosses each boundary, origin and RP ID, the ceremony and its window, signing, creating, the counter, lock, fallback, what a page can learn, the setting and supported Keepiq versions.

### Modified Capabilities
- `inline-list` (ext-autofill): passkey rows during a conditional request, and `webauthn` fields qualify while one is pending.
- `login-capture` (ext-autofill): excluded domains also turn off passkeys.
- `settings` (ext-settings): the "Ask to save and use passkeys" toggle.
- `item-detail` (ext-vault-browse): a login's passkeys; the standalone passkey section's note says it is not used to sign in.

## Deviations from Bitwarden

- **A login holds several passkeys.** Keepiq's `passkeys` field is an array; Bitwarden keeps one per login. So Save adds to a login, and asks before replacing only a passkey for the same relying party and user.
- **Standalone passkey items are not used.** Keepiq ended them (keepiq#1324); Bitwarden has none.
- **Every Keepiq passkey is discoverable.** The canonical schema has no discoverable flag, so `allowCredentials: []` offers every passkey for the RP ID and `credProps.rk` is always true.
- **AAGUID is all zeros.** Bitwarden has its own registered AAGUID; Keepiq has none, so sites show an unnamed provider.
- **User verification is the master password or the PIN.** Keepiq has no biometrics yet (ext-settings).
- **A request Keepiq does not handle goes to the browser untouched**: an RP ID that is not the origin's host or a registrable suffix of it, an `http:` origin other than `localhost`, a frame with a cross-origin ancestor, `authenticatorAttachment: 'cross-platform'`, or no ES256 in `pubKeyCredParams`. Bitwarden throws `SecurityError` for a bad RP ID; the browser applies its own rules, related origins included, and Keepiq reads nothing from the vault.
- **The page script is on every `http(s)` page while the setting is off.** Bitwarden registers it only while on, with `scripting.registerContentScripts`; that needs host permission for every site, which this extension does not hold (SECURITY.md). While off, it answers every request with the fallback.
- **Excluded domains match by base domain,** as ext-settings stores them; Bitwarden compares the host.

## Keepiq API used

- `POST /api/v1/secrets` (a new login holding the passkey, `passkeys` in the body)
- `PUT /api/v1/secrets/{id}/share-save` (a login's `passkeys`: a saved passkey, a replacement, the counter; through the value save of ext-share-sync, shared or not)
- `GET /api/v1/secrets/{id}` (fresh fetch before each of those saves, ADR-002)
- `GET /api/v1/secrets/{id}/write-context` (path and grade for that save)
- `GET /api/v1/suites` (the active suite, checked before encrypting)
- `POST /api/v1/shares/recipient-certificates` and `POST /api/v1/federation/recipient-certificate` (copies of a shared login; a partner's `valueFields` must list `passkeys`)
- `GET /api/v1/secret-types` (the `login` type id, already cached)
- `GET /api/v1/folders` (folder choice on Save, already cached)

All are in ADR-003 and none is newer than `MIN_KEEPIQ_VERSION` (0.3.12); the `passkeys` field is in 0.3.12 too (keepiq#1325). Nothing is added to `KEEPIQ_FEATURES`, and no Keepiq change is needed.

## Impact

- The page script: `entrypoints/passkey-page.content.ts`, a `world: 'MAIN'` content script on Chrome, and `entrypoints/passkey-page.ts`, an unlisted script the relay injects from a web-accessible resource on Firefox.
- New relay content script `entrypoints/passkey.content.ts` at `document_start`; `entrypoints/background.ts` routes ceremonies; the popup gains a passkey view for the ceremony window; the inline list gains passkey rows.
- New `src/passkey/*`: request checks, the ceremony store, CBOR, authenticator data, attestation, signing, the canonical credential and the `passkeys` array, the saves of that field.
- `src/vault/share-save.ts`: `passkeys` as a value field of the value save. `src/background/requests.ts`: `item.passkeys` for the detail view, metadata only.
- `src/messages.ts` gains the `passkey.*` kinds; `src/background/sender.ts` routes them.
- `wxt.config.ts`: the Chrome MAIN-world content script and, on Firefox, `passkey-page.js` in `web_accessible_resources`. No new permission.
- `settings.<id>` gains `askPasskeys`.
- ADR-002 (the page script, where passkey data lives, the counter save to copies), ARCHITECTURE.md and SECURITY.md are updated.
