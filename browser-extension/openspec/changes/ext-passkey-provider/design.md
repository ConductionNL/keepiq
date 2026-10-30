## Context

The earlier changes provide:
- the unlocked key, `enforce()` before every vault use, lock and logout broadcasts (ext-accounts-and-unlock);
- the snapshot, whose rows carry the `passkeys` ciphertext as the server sends it, and the item detail (ext-vault-browse, ext-totp-autofill);
- `run()`, `createItem` and `updateItem` in `src/vault/write.ts`; an edit sends no `passkeys`, so the server keeps them (ext-vault-edit);
- the value save through share-save with `write-context` and partner checks (ext-share-sync), today for `key`, `login` and `additionalFields`;
- the frame registry, the inline list with its key handshake and visibility guard, `sameOriginAncestors()`, `popout()` and `openPopupOrPopout()` (ext-autofill);
- `excludedDomains` and the Notifications view (ext-settings).

Server truth is Keepiq's `login-one-time-codes` (a login's `passkeys` field: null, `''` or ciphertext of a JSON array), `passkey-item-type` (the canonical credential schema of each entry) and `clients-passkey-origin` (origin from the sender, RP ID a registrable suffix). The web app's `src/passkey/passkey.js` and `src/passkey/loginPasskeys.js` are the reference for the JSON; its import writes a passkey-only login with `key` the encryption of `''`.

## Goals / Non-Goals

**Goals:**
- Sign in and save with Keepiq passkeys on Chrome MV3 and Firefox MV2, from one source.
- The page learns nothing about the vault before the user acts; the passkey key exists only in background memory for one signature.
- No new permission and no Keepiq change.

**Non-Goals:**
- Passkeys in the popup's "Autofill suggestions", the context menu or the toolbar badge.
- Deleting or exporting a passkey from the extension; the web app deletes one.
- Signing with standalone `passkey` items.
- Unlocking Keepiq with a passkey (biometrics) or hybrid (phone) sign-in.
- WebAuthn extensions other than `credProps`, attestation other than `none`, algorithms other than ES256, related origins.
- Chrome's `webAuthenticationProxy`: Chrome-only, one extension at a time, meant for remote desktop; Keepiq's web app dropped it in `clients-extension-save-prompt-and-passkey-origin`.

## Decisions

### Boundaries

What crosses each boundary, and nothing else:

| From → to | Carries | Trusted for |
| --- | --- | --- |
| Page script → relay (`postMessage`, then a `MessageChannel` port) | kind, request id, `publicKey` options as base64url, `mediation` | nothing: it is the page |
| Relay → page script | ack; credential as base64url, a `DOMException` name, or `fallback` | nothing: the page reads it anyway |
| Relay → background (`passkey.start`, `passkey.abort`, `passkey.available`) | the options, `crossOriginAncestor` | the ancestor flag only; tab, frame and origin come from `sender` |
| Background → relay (`passkey.result`, by `frameId`) | ceremony id, origin, result | — |
| Background ↔ passkey window (popup kinds) | ceremony view: kind, RP ID, host, page's `rp.name` and `user.name`, lock state, sign-in rows (login id, credential id, login name, user name), save targets (login id, name, username), codes; back: pick, save to a login or a new one, replace, verify, fallback, cancel | — |
| Background ↔ inline list | passkey rows (login id, credential id, login name, user name) in `inline.state`; `inline.pick` with a login id and credential id | — |
| Background ↔ popup detail | `item.passkeys`: per entry relying party, user name, created date | — |

- **The page script holds one ceremony per call** and the browser's original `create`, `get` and the two static methods, captured when it starts.
- **`fallback` is a result, not an error:** the page script then calls the original method with the page's own options and signal, so the browser's dialog and errors are the page's.
- **The relay acks at once and answers `fallback` when its context is gone**; the page script falls back on no ack within 500 ms, so a disabled extension never hangs a sign-in.
- **The relay checks sizes and shape** and the background checks again; the content script is extension code, but its input is the page's.

### Injecting the page script

| | Chrome MV3 | Firefox MV2 |
| --- | --- | --- |
| Page script | `entrypoints/passkey-page.content.ts` (`include: ['chrome']`), `world: 'MAIN'`, `runAt: 'document_start'`, `allFrames`, `*://*/*`; Chrome 111+ | Unlisted `entrypoints/passkey-page.ts` (`include: ['firefox']`), injected with WXT's `injectScript()` by the relay, listed in `web_accessible_resources` for Firefox only. Both import `src/passkey/page.ts` |
| Relay | `entrypoints/passkey.content.ts`, isolated, `document_start`, `allFrames`, `*://*/*` | Same |

- **Static, not `scripting.registerContentScripts`:** dynamic scripts need host permission for every site, which the extension does not hold (SECURITY.md). So the page script is present while the setting is off and answers with the fallback (proposal, deviations).
- **A separate relay at `document_start`:** `content.ts` runs at `document_idle` for its DOM work, too late to inject on Firefox.
- **Firefox's `<script src>` loads asynchronously,** so a page's earliest inline script can run before it; Bitwarden's MV2 build has the same gap. Such a call goes to the browser, unhandled.
- **No `scripting` permission** is needed on either browser.

### Background flow

- **`passkey.start` answers at once** with `{ ceremonyId }` or `{ fallback: true }`, from the gates in the spec only; the result follows later as `passkey.result` through `tabs.sendMessage` with the frame's `frameId`. A held reply or a port dies with an MV3 worker; a pushed result survives it, since the ceremony is in `storage.session`.
- **One ceremony per tab** in `passkeyCeremony.<tabId>`. The window and the inline list name it by ceremony id, so a stale window cannot answer a newer ceremony.
- **The window is the popup page** at `popup.html?passkey=<ceremonyId>`, opened with `browser.windows.create({ type: 'popup' })` as `popout()` does. It reuses the unlock view and the PIN, sends popup kinds the existing sender routing accepts, and holds the `popup` port, so "Immediately" locks when it closes, as for any popup.
- **Ending a ceremony:** `windows.onRemoved` (Cancel), `tabs.onRemoved`, `tabs.onUpdated` with `status: 'loading'`, the relay's `pagehide` (`passkey.abort` for a subframe), `vault.locked` with a logout, an account switch, and the deadline. The page script rejects at the deadline and sends `passkey.abort`; the background also checks the deadline on every ceremony message, so a page that never aborts only leaves the window open until the user closes it.
- **`enforce()` runs first** on every ceremony message, so an overdue vault locks before anything is matched.

### Messages

All in `src/messages.ts`, dotted kinds:

- Content: `passkey.start`, `passkey.abort`, `passkey.available`; added to `CONTENT_KINDS`.
- Background → relay: `passkey.result`.
- Popup: `passkey.state`, `passkey.pick`, `passkey.save`, `passkey.verify`, `passkey.retry`, `passkey.fallback`, `passkey.cancel`; the replies carry codes, worded in the popup.
- Inline list: `inline.state` replies gain `passkeys`; `inline.pick` takes `{ itemId, credentialId }` for a passkey as well as `{ itemId }` for a login.
- Popup: `item.passkeys` (`{ id }` → per entry `{ rpId, rpName, userName, createdAt }`, or a failure code); `passkeys` is never a `DecryptField`.

### Matching and signing

- **Candidates are every `login` row with a `passkeys` value, decrypted.** `url` is plaintext and editable (ext-vault-edit), so it can't decide; each entry's decrypted `rpId` does, as in Bitwarden. A passkey is named by its login id and `credentialId`. Few logins hold passkeys; each field is a few RSA chunks.
- **Standalone `passkey` items are left out** everywhere: no client creates them since keepiq#1324, and matching them too would show one credential twice once a user moves it onto the login.
- **`src/passkey/credential.ts` ports `buildPasskeyCredential`, `parsePasskey` and the `passkeys` array helpers** (`loginPasskeys.js`) from the web app, field order included, tested against the web app's cases.
- **WebCrypto only:** ECDSA P-256 `generateKey` (extractable, for the PKCS#8 export), `importKey('pkcs8', …, false, ['sign'])`, SHA-256. The P1363 signature WebCrypto returns is converted to DER.
- **`src/passkey/cbor.ts`** encodes the few shapes needed (unsigned and negative integers, byte and text strings, maps); no new dependency.
- **Flags:** UP always, UV per spec, BE and BS always, AT on create. AAGUID is 16 zero bytes.

### Writes

- **Save as new login** is a background-only `createPasskeyLogin()` in `src/vault/write.ts` on `run()`: body `{ name, url: origin, typeId: login, folderId, key: encrypt(''), passkeys }`, no `login` or `additionalFields`, as the web app's import creates one. The reply waits for the POST, not the sync that follows.
- **Every write to an existing login's `passkeys`** (save to it, replace, counter) is one background-only `writeLoginPasskeys(id, change)` on `run()`: cached row, use-only and read-only refused (`restricted`), `write-context` (`view_only`), a fresh `GET /api/v1/secrets/{id}` whose `passkeys` must equal the cache's (`unmatched`), the array decrypted and `change` applied, then `shareSave()` with `plain: { passkeys }` and `baseUpdatedAt` from the fetch. One retry after `stale`. The other entries are kept as the parsed objects and serialised with the web app's field order, so they round-trip byte for byte.
- **`shareSave()` gains `passkeys` as a value field,** next to `key`, `login` and `additionalFields`: the patch and each copy carry it re-encrypted, a partner share carries it only when the partner's `valueFields` lists it, and a partner that does not refuses with 409 `partner_upgrade_required`, shown with its `requiredVersion`. Its `Opened` vouches for `key`, `login` and `additionalFields` only when the fresh row has them empty, so a partner-shared login with any of them fails `unverified`, as an edit would. See Open Questions.
- **Keepiq API:** `secret#create`, `secret#show`, `share#writeContext`, `shareSave#save`, `share#recipientCertificates`, `federation#recipientCertificate`, `encryptionSuite#index`, `secretType#index` (`appinfo/routes.php`). All in 0.3.12 (ADR-003), the `passkeys` field too (keepiq#1325). No `KEEPIQ_FEATURES` entry.

### Browser APIs

| Use | API | Chrome MV3 | Firefox MV2 |
| --- | --- | --- | --- |
| Page script | manifest `world: 'MAIN'` / `injectScript` | 111+ | web-accessible script |
| Relay | `runtime.sendMessage`, `tabs.sendMessage({ frameId })` | yes | yes |
| Origin | `sender.origin`, else `sender.url` | `origin` | `url` only |
| Window | `windows.create`, `windows.remove`, `windows.onRemoved` | yes | yes |
| Lifecycle | `tabs.onRemoved`, `tabs.onUpdated`, `alarms` | yes | yes |
| Ceremony store | `storage.session` | 102+ | 115+, memory below |
| Crypto | WebCrypto ECDSA P-256, SHA-256 | yes | yes |

Permissions: none new; `tabs`, `alarms` and `storage` are declared. `web_accessible_resources` gains `passkey-page.js` on Firefox.

### Where data lives

| Data | Lives in | Cleared by |
| --- | --- | --- |
| Ceremony: ids, origin, RP ID, challenge, page's `rp` and `user`, credential id lists, requirements, deadline, window id, verified flag | `storage.session` `passkeyCeremony.<tabId>`; memory below Firefox 115 | Ceremony end, next ceremony in the tab, tab close or navigation, logout, account switch, restart, reload |
| Decrypted `passkeys` array, imported signing key, generated key pair | Background memory, one handler | Handler return |
| Rows (login id, credential id, login name, user name), save targets | Popup or list page memory | Window or list closing |
| A login's passkey metadata (relying party, user name, date) | Popup memory while the detail shows it | The view closing |
| Credential in flight | Relay and page script memory | The call settling |
| `askPasskeys` | `storage.local` `settings.<id>` | Account removal |
| Last use of a login signed in with a passkey | `storage.local` `lastUsed.<id>` (existing) | Logout, account removal |

## Risks / Trade-offs

- [The counter save sends the login's passkeys to every copy the server lists] → The same trust ext-share-sync accepts for an edit (ADR-002 § Sharing), now on each sign-in with an imported non-zero counter. New passkeys keep 0 and never write. See Open Questions.
- [Saving to a login rewrites its whole `passkeys` field] → Entries round-trip as parsed and serialised in the web app's field order; the fresh fetch must match the cache, so a concurrent change from another client fails `stale` and is retried on the new value, never overwritten.
- [Existing standalone passkey items stop being usable for sign-in in the extension] → Accepted: the app has no users beyond internal testers (keepiq#1328 dropped); the detail still shows them and says why.
- [A non-zero counter can't sign offline or from a copy the user can't write] → As Bitwarden, which saves before replying. The window offers the browser's authenticator.
- [Firefox's injected `<script src>` shows the per-install `moz-extension://` UUID to the page while it loads] → Accepted for parity; see Open Questions.
- [The page script detects as Keepiq on every page] → Patched methods are visible to any page; Bitwarden's are too. It answers nothing about the vault.
- [Sites see an unnamed authenticator (zero AAGUID)] → Cosmetic; a registered AAGUID can come later without changing stored passkeys.
- [Chrome below 111 runs the page script isolated, where it does nothing] → The browser handles every request; no harm.
- [The page counts passkeys from the inline list's height after a focus] → As accepted for logins in ext-autofill, and as in Bitwarden.

## Open Questions

- Should a counter save re-encrypt a shared login's passkeys for every listed copy on sign-in (as specced, like an edit), or should a shared passkey sign without saving the counter?
- Save to a partner-shared login fails `unverified` when it has a password, username or additional fields, since the extension never opened them in a form. Accept (as specced), or let the passkey save vouch for fields it never changes?
- Should use-only, read-only and view-only copies with a non-zero counter refuse to sign (as specced, Bitwarden's behaviour), or sign with the unsaved counter plus one?
- Firefox: accept the injected script and its UUID exposure (as specced), raise `strict_min_version` to 128 if MV2 honours a manifest `world: 'MAIN'` there, or install the page functions from the isolated world with `exportFunction`?
- Register a Keepiq AAGUID with the passkey provider list, or keep zeros?
