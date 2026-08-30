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
      service-worker.js      holds the CryptoKey; unlock, lock timer, blob fetch, decrypt,
                             WebAuthn ceremony (passkey), TOTP compute
    popup/
      popup.html / popup.js  unlock, matched-credential list, save/update, TOTP code, lock
    content/
      content-script.js      field detection + fill (all_frames), submit-capture, OTP fill,
                             WebAuthn relay
      inpage-shim.js         page-context navigator.credentials shim (Firefox path)
    passkey/webauthn.js      create()/get() ceremony (client-side signing)
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
  and manual lock — clearing the key and all derived state.

## Build

The extension shares the web app's `src/crypto` and `src/totp` modules verbatim
(ADR-003 dual-implementation invariant), bundled with esbuild:

```sh
npm run build:extension   # from the repo root
```
