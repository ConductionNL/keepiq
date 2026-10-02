# Keepiq browser extension

A Manifest V3 WebExtension (Firefox / Chrome / Edge) that brings **autofill**,
**passkey provision**, and **TOTP** to the [Keepiq](../) secrets manager —
without weakening its zero-knowledge model.

The extension is a **second end-to-end client**, exactly the shape ADR-003
already defines: it pairs against the user's Nextcloud session, unlocks the vault
**inside the extension** (master password → derive AES key → decrypt the private
key → non-extractable WebCrypto `CryptoKey` in the background service worker),
and the server only ever ships **encrypted blobs**. The master password, the
derived key, and plaintext never reach the server and are never written to
`storage.local`/`storage.sync`.

## Layout

```
browser-extension/
  manifest.json              MV3 manifest
  src/
    crypto/                  the SAME recipe as the web app (re-exported from ../../src/crypto)
    lib/
      api.js                 Keepiq API client (pair, match, list, get, create, update)
      match.js               registrable-domain / origin matching over unencrypted url/name
      vault.js               in-worker unlock/lock state + decrypt-on-demand
    background/
      service-worker.js      entry: wires runtime messages and the OS lock to router.js
      router.js              holds the per-account keys; unlock, lock timers, blob fetch,
                             decrypt, fill, save, WebAuthn ceremony (passkey), TOTP compute
    popup/
      popup.html / popup.js  unlock, matched-credential list, save/update, TOTP code, lock
    content/
      content-script.js      field detection + fill (all_frames), submit-capture, OTP fill,
                             WebAuthn relay
      inpage-shim.js         page-context navigator.credentials shim (Firefox path)
    passkey/webauthn.js      create()/get() ceremony (client-side signing)
    unlock/                  the unlock window: fingerprint or face unlock with a PRF passkey
    totp/                    RFC 6238 (re-exported from ../../src/totp)
  tests/                     vitest unit + integration
```

## Zero-knowledge invariants (enforced by tests)

- The `CryptoKey` is `extractable: false` and lives only in the service worker's
  memory — never in `storage.*`, never in a request body.
- A paired-but-locked extension can list unencrypted names/URLs but cannot
  decrypt any value.
- Autofill, WebAuthn signing, and TOTP computation all happen in the extension;
  the server sees only ciphertext.
- The extension auto-locks on idle timeout, browser/OS lock, worker termination,
  and manual lock, clearing the key and all derived state.
- Only the four messages a content script needs (`capture-credential`,
  `capture-decision`, `webauthn-create`, `webauthn-get`) are accepted from a
  web page. Everything that unlocks, lists, fills, saves or changes settings
  needs one of the extension's own pages as sender.

## Accounts, lock delay and fingerprint unlock

- Up to five accounts, on one or more servers. Each has its own key, lock
  state, idle timer and settings. Matching, filling and saving use the active
  account, picked in the popup header. A fill is refused unless the login came
  from the active account's own match for the page that is open.
- Each account picks its idle lock delay (1, 5, 15, 30, 60 or 240 minutes,
  15 by default). The administrator sets the longest one in the Keepiq admin
  settings; the extension reads it at every unlock and uses the shorter of the
  two. When it cannot be read, the extension caps the delay at 15 minutes.
- Fingerprint or face unlock enrols a platform passkey with the WebAuthn PRF
  extension, with the extension's own origin as relying party. The server
  stores only the PRF-wrapped unlock key, next to the web app's passkeys,
  where the owner sees it as "Browser extension" and can revoke it. The option
  shows only where the browser exposes a user-verifying platform authenticator.

Which browsers pass that check has not been tried by hand yet. Open item:
try Chrome, Edge and Firefox (current versions) on a machine with a
fingerprint reader or Windows Hello, and record the result here.

## Build

The extension shares the web app's `src/crypto` and `src/totp` modules verbatim
(ADR-003 dual-implementation invariant), bundled with esbuild:

```sh
npm run build:extension   # from the repo root
```

The build writes one package per browser, from `manifest.json` plus the
overlay in `manifests/browsers.mjs`:

- `dist/chromium`: Chrome and Edge (load unpacked from `chrome://extensions`).
- `dist/firefox`: Firefox 115 or later (load from `about:debugging` as a
  temporary add-on).
- Safari: made from `dist/chromium` on a Mac, see `safari/README.md`. Not
  yet part of the pipeline.

`node browser-extension/load-check/chromium.mjs` and `firefox.mjs` start each
package headless and check its background answers the popup; the
`Browser extension` workflow runs both.
