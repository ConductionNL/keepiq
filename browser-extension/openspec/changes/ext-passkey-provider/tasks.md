## 1. Entrypoints and messages

- [ ] 1.1 Add the page script and the relay (design.md § Injecting the page script): `entrypoints/passkey-page.content.ts` (Chrome, `world: 'MAIN'`, `document_start`, all frames), `entrypoints/passkey-page.ts` (Firefox, unlisted, in `web_accessible_resources`), `entrypoints/passkey.content.ts` (both, isolated, `document_start`, all frames), both page entrypoints importing `src/passkey/page.ts`
  - `.output/chrome-mv3/manifest.json` has a `world: "MAIN"` content script and no `passkey-page.js` in `web_accessible_resources`; `.output/firefox-mv2/manifest.json` has the reverse
  - No new permission in either manifest
  - Ends with `npm run build` and `npm run build:firefox`
- [ ] 1.2 Add the `passkey.*` kinds to `src/messages.ts` (design.md § Messages) and route them in `src/background/sender.ts`: content kinds only from a content script with a tab and frame, popup kinds only from `/popup.html`
  - Every listener keeps the `unknown` cast at the boundary
  - Tests: a `passkey.start` from the popup and a `passkey.pick` from a content script are ignored

## 2. Page side

- [ ] 2.1 Write `src/passkey/page.ts`: keep the original `create`, `get`, `isUserVerifyingPlatformAuthenticatorAvailable` and `isConditionalMediationAvailable`; replace them; serialise `publicKey` options to base64url; one `MessageChannel` per call; the 500 ms ack fallback; `AbortSignal` and timeout handling; the conditional race with the browser's own request; rebuild results with the `PublicKeyCredential` and response prototypes and their methods
  - Any throw ends in the original method with the page's arguments
  - Tests: a non-`publicKey` call, a forged result posted to the window, an abort, no ack, the conditional race both ways
- [ ] 2.2 Write the relay in `entrypoints/passkey.content.ts`: shape and size checks, `crossOriginAncestor` from `sameOriginAncestors()`, `passkey.start`, `passkey.abort` on abort and `pagehide`, results delivered only for its own ceremony id and while `location.origin` equals the ceremony's origin; on Firefox inject `passkey-page.js` with `injectScript()`
  - Tests: oversized input answers `fallback`; a lost extension context answers `fallback`
  - Ends with `npm run build` and `npm run build:firefox`

## 3. WebAuthn core

- [ ] 3.1 Write `src/passkey/credential.ts`, a port of the web app's `buildPasskeyCredential`, `parsePasskey` and `serializePasskey`, field order included
  - Tests: the web app's `passkey.js` cases, and JSON the extension writes parses in the web app's code byte for byte
- [ ] 3.2 Write `src/passkey/cbor.ts` and `src/passkey/webauthn.ts`: `clientDataJSON`, authenticator data with flags and counter, the EC2 COSE key, the `none` attestation object, P1363-to-DER signatures, the zero AAGUID
  - Tests: a registration and an assertion verify with an independent WebCrypto verifier in the test; flags per user verification case
- [ ] 3.3 Write `src/passkey/request.ts`: origin from `sender`, the RP ID rule (host or registrable suffix, not a public suffix or IP, via `tldts`), secure origin, ES256 present, attachment, deadline clamping
  - Tests: every gate in the spec's "go to the browser untouched" requirement, and `login.bank.example` with `bank.example`

## 4. Background ceremony

- [ ] 4.1 Write `src/passkey/ceremony.ts`: `passkeyCeremony.<tabId>` in `storage.session` (memory below Firefox 115), one per tab, ended by every case in the spec; `passkey.start` answering `{ ceremonyId }` or `{ fallback: true }` from settings, account state and the request only; the window opened per handled modal ceremony and reused in the tab; `enforce()` first on every message
  - Tests: a second ceremony in a tab ends the first with `NotAllowedError`; logout, account switch, tab close, navigation and window close each clear the record
  - Tests: a locked and an unlocked vault produce the same reply to `passkey.start`
- [ ] 4.2 Write `src/passkey/match.ts`: decrypt every `passkey` row of the active account while unlocked, keep only rows whose parsed `rpId` equals the request's, `algorithm` `-7`, in `allowCredentials` when given, not blocked or expired; hand out id, name and user name only
  - Tests: the edited-URL case, an unreadable row, a use-only copy listed, a blocked row left out
- [ ] 4.3 Write `src/passkey/sign.ts` for `get`: import the key non-extractable, sign, record `lastUsed`, push `passkey.result`; user verification per the spec, the PIN under its five-try rule
  - Tests: no decrypted value or key remains in `storage.local` or `storage.session` after a sign-in
- [ ] 4.4 Write `createPasskey()` in `src/vault/write.ts` and the `create` flow: `excludeCredentials` check after unlock, key generation, canonical JSON, `POST /api/v1/secrets` without `login` or `additionalFields`, the attestation pushed only after the server accepted; `passkey_type_missing` without a `passkey` type
  - Tests: a 423 and an offline save keep the ceremony open and send the page nothing
- [ ] 4.5 Write `savePasskeyCounter()` in `src/vault/write.ts` (design.md § Writes): counter 0 writes nothing; above 0, the fresh fetch, `restricted`, `view_only`, `unmatched`, share-save with `plain: { key }`, one retry after `stale`, then sign with the saved value
  - Tests on an account with `keepiqVersion` `0.3.12`, an `-unstable` one and `undefined`: own item, a shared item with a local copy, a partner-shared one with and without empty `login`, a use-only copy

## 5. Window and inline list

- [ ] 5.1 Add the passkey view to the popup for `?passkey=<ceremonyId>` (React, ADR-004): unlock, sign-in list, no passkey, save with Name and Folder, already saved, "Confirm it's you", error with Retry, and "Use your device or hardware key" and Cancel throughout; texts in `en.yml` and `nl.yml`; closes when the ceremony ends
  - No component imports `src/api` or `src/crypto`; the page's `rp.name` and `user.name` render as text
  - Popup tests in both projects, including keyboard use and the screen reader names
  - Ends with `npm run build` and `npm run build:firefox`
- [ ] 5.2 Extend the inline list for conditional requests: `webauthn` fields qualify while one is pending, `inline.state` carries passkey rows, `inline.pick` takes a passkey id through the existing checks, verification or a failed counter save opens the window on that step, and "No logins or passkeys for <host>"
  - Tests: no passkey row without a pending conditional request, none while locked
  - Ends with `npm run build` and `npm run build:firefox`
- [ ] 5.3 Add `askPasskeys` (default `true`) to `src/accounts/settings.ts` and "Ask to save and use passkeys" to the Notifications view; mark the provider delivered; drop the not-yet-supported note from the passkey detail
  - Turning it off sends the next request on an open page to the browser

## 6. Test site, docs and verification

- [ ] 6.1 Add `test-site/passkey.html` (register and sign in against an in-page relying party that verifies with WebCrypto and shows the counter), `passkey-conditional.html` and `passkey-iframe.html` (a cross-origin frame), linked from `test-site/index.html`; add e2e tests in `e2e/` for save, sign-in, cancel and the fallback reaching a CDP virtual authenticator
  - `npm run test:e2e` passes
- [ ] 6.2 Update ARCHITECTURE.md (entrypoints, modules, messages, storage, a Passkeys flow), SECURITY.md (where data lives, pages and messages), ADR-002 (the page script, the passkey key row, the counter save to copies) and ADR-003 (the `passkey` type's first release); `../docs/browser-extension/using.md` and `privacy.md` where they describe what the extension does on pages
  - Every comment and doc line follows CLAUDE.md § Comments & docs
- [ ] 6.3 Walk every scenario in `specs/` on Chrome (`npm run dev`) and Firefox (`npm run dev:firefox`) on the test site, with the vault locked and unlocked, a stopped MV3 worker and a Keepiq at 0.3.12
  - Every scenario holds on both browsers, or the difference is in design.md
  - `npm test`, typecheck and lint are clean
  - Both targets build
