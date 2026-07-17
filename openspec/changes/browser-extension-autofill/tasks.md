# Tasks: Browser extension + autofill API

## 1. Backend — pairing & matching

- [ ] 1.1 `ExtensionController` (or reuse the JWT/app-password middleware chain): `pair` (accept an NC app-password / OAuth-code exchange, no new long-lived Doriath credential), `unpair` (or document NC app-password revocation)
- [ ] 1.2 `GET /api/v1/extension/match?host=<origin>` — return blob rows whose unencrypted `url`/`name` match the host; never return plaintext
- [ ] 1.3 Register extension routes in `appinfo/routes.php` with correct auth attributes; confirm the discovery document (`DiscoveryController::document`) advertises the extension endpoints
- [ ] 1.4 Verify the existing blob-returning secret list/get and create/update endpoints satisfy the extension's fetch and save/update needs; extend only if a gap is found (no server-side decrypt)

## 2. Extension workspace scaffold (in-repo `browser-extension/`)

- [ ] 2.1 Scaffold an MV3 WebExtension (`browser-extension/`) targeting Firefox/Chrome/Edge: manifest, background service worker, content script (`all_frames: true`), popup UI, build tooling
- [ ] 2.2 Import the web frontend's shared crypto module (chunking, RSA, AES, KDF) so PHP↔JS round-trips stay valid per ADR-003; add a cross-implementation round-trip test in CI

## 3. Extension — auth & unlock

- [ ] 3.1 Pairing flow in the popup: obtain the NC app-password / OAuth credential and store only non-sensitive config in `storage.local`
- [ ] 3.2 Unlock flow: master password entered in popup → derive AES key → decrypt private key → import non-extractable `CryptoKey` in the background worker memory (never persisted)
- [ ] 3.3 Auto-lock: idle-timeout timer (configurable), clear-on-worker-termination, browser/OS lock, and manual "Lock" — all clear the in-memory key

## 4. Extension — matching, fill, capture

- [ ] 4.1 URL-matched candidate list for the active tab origin (unencrypted `url`/`name`); decrypt only the chosen secret on demand
- [ ] 4.2 Content-script field detection + fill on the top document and scriptable iframes; degrade to manual-copy for non-scriptable cross-origin frames
- [ ] 4.3 Submit-capture: detect entered credentials, offer save (new) or update (changed), encrypt client-side, POST only the blob
- [ ] 4.4 Strict origin matching (registrable domain), warn on non-TLS origins, require explicit user selection before any fill (anti-phishing / anti-injection)

## 5. Doriath web app surfaces

- [ ] 5.1 "Connect browser extension" panel in user settings (`CnSettingsSection`) with pairing instructions and a link to NC Security settings for device management/revocation
- [ ] 5.2 Documentation page explaining that autofill decrypts in the extension (zero-knowledge preserved)

## 6. Security & tests

- [ ] 6.1 Assert (lint/test gate) that no key material or plaintext is ever written to `storage.local`/`storage.sync`; `CryptoKey` is `extractable: false`
- [ ] 6.2 Unit: URL/host matching over unencrypted fields; save-vs-update decision logic; auto-lock state transitions
- [ ] 6.3 Backend unit: extension endpoints return blobs only and reject unpaired/unauthorized requests (403); no server-side decrypt path exists
- [ ] 6.4 e2e (browser): pair → unlock → autofill a login form (including an iframe form) → submit new credential (save prompt) → change password (update prompt) → idle auto-lock → re-unlock

## Acceptance criteria

- The extension pairs using a Nextcloud app password or OAuth-style flow; no new long-lived Doriath credential is minted, and revoking the NC credential kills the extension
- Vault unlock happens in the extension; the master password, derived key, and plaintext never reach the server
- A paired-but-locked extension can list unencrypted names/URLs but cannot decrypt any value
- The `CryptoKey`, master password, and plaintext are never persisted to extension storage; the key is `extractable: false`
- URL-matched candidates are offered for the active origin and decrypted only on selection
- Login forms are autofilled including inside scriptable iframes; non-scriptable frames degrade to manual copy
- Save/update capture on submit encrypts client-side and sends only a blob
- The extension auto-locks on idle timeout, browser/OS lock, worker termination, and manual lock
- Passkey/WebAuthn interception is out of scope (owned by `passkey-item-type`)
- The server never returns plaintext to the extension at any step (ADR-003 preserved)
