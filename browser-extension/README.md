# Keepiq browser extension

A Manifest V3 WebExtension (Firefox / Chrome / Edge) that brings **autofill**,
**passkey provision**, and **TOTP** to the [Keepiq](../) secrets manager,
without weakening its zero-knowledge model.

The extension is a **second end-to-end client**, exactly the shape ADR-003
already defines: it pairs against the user's Nextcloud session, unlocks the vault
**inside the extension** (master password → derive AES key → decrypt the private
key → non-extractable WebCrypto `CryptoKey` in the background service worker),
and the server only ever ships **encrypted blobs**. The master password, the
derived key, and plaintext never reach the server and are never written to
`storage.local`/`storage.sync`.

## What it does

For users, see `docs/browser-extension/using.md`. In short:

- **Fill:** logins, one-time codes and passkeys, only into frames on the matched site; from the popup, the right-click menu or Ctrl+Shift+L.
- **Save:** a bar offers to save a new login or update a changed password, per tab, also after the login page redirects.
- **Popup:** This site, Vault (browse, detail, edit, folders), Generator, Send and Settings tabs; a pop-out window.
- **Unlock:** master password, a PIN for the browser session, fingerprint or face, or offline from the vault copy.
- **Offline:** an encrypted copy of the vault per account, synced every 15 minutes and after each change.
- **Clipboard:** every copy is cleared after the delay the user picks, by the worker.

The specs: `browser-extension-autofill`, `extension-*`, `clients-*` and `item-name-limit` in `openspec/specs/`; the changes that built them are under `openspec/changes/archive/`. `openspec/references/keepiq-extension/mapping.md` maps the former keepiq-extension plans onto them.

## Layout

```
browser-extension/
  manifest.json              MV3 manifest; manifests/browsers.mjs adds the per-browser parts
  icons/                     toolbar and store icons (scripts/render-icons.mjs makes them)
  THIRD-PARTY-NOTICES.txt    credits for the bundled word list and Argon2
  src/
    crypto/                  the SAME recipe as the web app (re-exported from ../../src/crypto)
    background/
      service-worker.js      entry: wires runtime messages, alarms and the OS lock to router.js
      router.js              accounts, unlock and lock, the PIN, fill to frames, the save
                             prompt per tab, the context menu and shortcut, settings
      vault-handlers.js      the Vault, Send and generator messages
      vault-sync.js          the vault copy per account and its sync
      generator-handlers.js  generator options, policy and history
      clipboard-clear.js     clears the clipboard after a copy (offscreen page in Chromium)
    lib/
      api.js                 Keepiq API client: no cookies, https only, 401 signs out
      server-url.js          cleans a server address and refuses plain http
      vault.js               in-worker key per account, decrypt on demand
      match.js               host and registrable-domain matching, last use first
      field-detect.js        login fields, also in shadow roots and by label
      pin-unlock.js          the PIN-wrapped unlock key in session storage
      extension-settings.js  the browser-wide settings
      ...                    item form, folder rules, send form, generator state, policy
    popup/                   the popup page and its views (vault, item detail, folders,
                             generator, send, clipboard)
    content/                 field detection and fill (all frames), submit capture and the
                             save bar, one-time code fill, password suggestions, WebAuthn relay
    offscreen/               the hidden page that clears the clipboard in Chromium
    passkey/                 the WebAuthn create and get ceremony, signed in the extension
    unlock/                  the unlock window for fingerprint or face unlock
  load-check/                headless smoke test of each package
tests/extension/             vitest unit and integration tests (the real router and popup)
```

## Zero-knowledge invariants (enforced by tests)

- The `CryptoKey` is `extractable: false` and lives only in the service worker's
  memory, never in `storage.*` and never in a request body. A PIN keeps only a
  wrapped unlock key, in session storage, for the browser session.
- A passkey's private key never leaves the worker; the popup gets its site and
  account only.
- A paired-but-locked extension can list unencrypted names and URLs but cannot
  decrypt any value. The vault copy holds what the server stores: ciphertext and
  plaintext metadata.
- Autofill, WebAuthn signing and TOTP computation all happen in the extension;
  the server sees only ciphertext.
- The extension locks on idle timeout, browser or OS lock, worker termination
  and manual lock, clearing the key and what the popup shows.
- A web page may send only the messages a content script needs:
  `capture-credential`, `capture-decision`, `capture-offer`, `frame-ready`,
  `webauthn-create`, `webauthn-get`, `otp-field-detected`,
  `generate-for-field` and `page-settings` (`PAGE_MESSAGES` in `router.js`).
  None of them returns vault data. Everything that unlocks, lists, fills, saves
  or changes settings needs one of the extension's own pages as sender, as the
  top frame of its tab.

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

## Install

Once the store listings are live, install Keepiq from the Chrome Web Store,
Firefox Add-ons or Edge Add-ons. Each `extension-v<version>` GitHub release
also carries the packages. Organisations can force-install it, see
`docs/browser-extension/rollout.md`. Releasing is described in
`docs/browser-extension/release.md`.

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

`--target chrome|firefox` builds one package, `--outdir <dir>` writes it
elsewhere, and `EXTENSION_VERSION=1.2.0` sets the manifest version, as the
release workflow does from the tag.

`node browser-extension/load-check/chromium.mjs` and `firefox.mjs` start each
package headless and check its background answers the popup; the
`Browser extension` workflow runs both.
