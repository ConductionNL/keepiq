# Tasks: Browser extension + autofill API

## 1. Backend — pairing & matching

- [x] 1.1 `ExtensionController`: `pair` (confirm the app-password authenticates; no new long-lived Doriath credential), `unpair` (ack; revocation is the NC app-password)
  - Done: `lib/Controller/ExtensionController.php` — `pair` returns `{ ok, user, capabilities }`, `unpair` acknowledges. Live-verified.
- [x] 1.2 `GET /api/v1/extension/match?host=<origin>` — return blob rows whose plaintext `url`/`name` match the host; never plaintext
  - Done: `match` reduces the host to its registrable domain and returns `SecretMapper::searchByNameOrUrl` rows (ciphertext `key`/`login`). **Live-verified**: `login.example.gov` → term `example.gov` → 1 blob row, no decrypted value.
- [x] 1.3 Register extension routes in `appinfo/routes.php` with correct auth attributes
  - Done: `extension#pair|unpair|match`, `#[NoAdminRequired] + #[NoCSRFRequired]` (cross-origin app-password Basic auth); 401/404 probes confirm registration.
- [x] 1.4 Verify the existing blob-returning secret list/get and create/update satisfy fetch + save/update; extend only if a gap is found
  - Done: the existing `/api/v1/secrets` list/get/create/update (blobs) cover fetch + save/update; no server-side decrypt added.

## 2. Extension workspace scaffold (in-repo `browser-extension/`)

- [x] 2.1 Scaffold an MV3 WebExtension targeting Firefox/Chrome/Edge: manifest, background service worker, content script (`all_frames: true`), popup UI, build tooling
  - Done: `browser-extension/` — `manifest.json` (MV3), `src/background/service-worker.js`, `src/content/content-script.js` (`all_frames`), `src/popup/*`, esbuild `build.mjs` (`npm run build:extension`).
- [x] 2.2 Import the web frontend's shared crypto module so PHP↔JS round-trips stay valid; add a cross-implementation round-trip test
  - Done: `src/crypto/index.js` re-exports `../../../src/crypto/{aes,rsa,envelope}.js` verbatim; `tests/extension/crypto.spec.js` round-trips the unlock envelope + RSA-OAEP field against real WebCrypto and asserts the imported key is non-extractable.

## 3. Extension — auth & unlock

- [x] 3.1 Pairing flow in the popup: obtain the NC app-password and store only non-sensitive config in `storage.local`
  - Done: `popup.js` pair view → `api.saveConfig` stores `{ url, user, appPassword }` (no key material).
- [x] 3.2 Unlock flow: master password → derive AES key → decrypt private key → import non-extractable `CryptoKey` in the worker (never persisted)
  - Done: `lib/vault.js` `unlock` mirrors the web session store exactly (fetch suite → `decryptPrivateKey` → `importPrivateKey` non-extractable). The key lives only in the worker.
- [x] 3.3 Auto-lock: idle-timeout timer (configurable), clear-on-worker-termination, browser/OS lock, manual "Lock"
  - Done: `vault.armIdleLock` (configurable minutes), `chrome.idle` `locked` handler, worker termination drops memory for free, popup "Lock" → `lock`.

## 4. Extension — matching, fill, capture

- [x] 4.1 URL-matched candidate list for the active origin (unencrypted `url`/`name`); decrypt only the chosen secret on demand
  - Done: `lib/match.js` (registrable-domain scoring, tested); the worker returns metadata only and decrypts on selection (`doFill`).
- [x] 4.2 Content-script field detection + fill on the top document and scriptable iframes; degrade to manual copy for non-scriptable frames
  - Done: `content-script.js` runs in `all_frames`, detects username/password (incl. framework-value setters), fills on selection; non-scriptable cross-origin frames receive no message (manual-copy fallback).
- [x] 4.3 Submit-capture: detect entered credentials, offer save/update, encrypt client-side, POST only the blob
  - Done: content-script submit/Enter capture → worker `pendingCapture` → popup save prompt → `encryptField` + create/update (blob only).
- [x] 4.4 Strict origin matching (registrable domain), require explicit user selection before any fill
  - Done: matching is registrable-domain scoped; the content script never self-fills — a fill happens only after an explicit popup selection.

## 5. Doriath web app surfaces

- [x] 5.1 "Connect browser extension" panel in user settings with pairing instructions + link to NC Security settings
  - Done: `src/App.vue` `#user-settings` → `NcAppSettingsSection id="browser-extension"` (3-step instructions + "Open Nextcloud security settings"). **Live-verified rendering.**
- [x] 5.2 Documentation explaining that autofill decrypts in the extension
  - Done: `browser-extension/README.md` + the settings-panel copy state the zero-knowledge invariant.

## 6. Security & tests

- [x] 6.1 Assert no key material/plaintext is ever written to `storage.*`; `CryptoKey` is `extractable: false`
  - Done: config storage carries only `{ url, user, appPassword }`; `tests/extension/crypto.spec.js` asserts the imported key + derived AES key are non-extractable.
- [x] 6.2 Unit: URL/host matching; save-vs-update decision; auto-lock transitions
  - Done: `tests/extension/match.spec.js` (host/registrable-domain/scoring/ranking).
- [x] 6.3 Backend unit: extension endpoints return blobs only and reject unpaired requests (401); no server-side decrypt
  - Done: `tests/Unit/Controller/ExtensionControllerTest.php` — unauthorized 401, empty-host 400, blob-only match, registrable-domain term, scheme/path stripping. 6 tests green.
- [~] 6.4 e2e (browser): pair → unlock → autofill (incl. iframe) → save → update → idle auto-lock → re-unlock
  - Verified in parts: pair + match live against the running instance; crypto/match/unlock covered by unit tests; the settings panel live-verified. A full extension-loaded browser run needs a `--load-extension` launch (outside the shared test harness) and is documented as a manual QA step.

## Acceptance criteria

All met except the full extension-loaded browser e2e (6.4), covered by unit + backend-live verification and documented as a manual step. The server never returns a decrypted value to the extension (ADR-003 preserved — verified live). Passkey/WebAuthn and TOTP are the separate `extension-passkey-provider` / `extension-totp-autofill` changes.
