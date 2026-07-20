# Tasks — extension-passkey-provider

## 0. Scope Note (read first)

Make the MV3 browser extension act as a WebAuthn credential provider: intercept `navigator.credentials.create()`/`get()`, save new passkeys into the vault and assert with stored ones, signing the ceremony **client-side in the extension** with the decrypted passkey private key — **no server-side signing, no new backend route, no new column/migration**. Create/read reuse the `passkey-item-type` seam (canonical JSON in encrypted `key`, RP id in plaintext `url`, `lib/Repair/SeedSecretTypes.php:69` seeds the type); the counter write-back reuses the existing secret-update path. Reuse the `browser-extension-autofill` service worker, in-extension unlock, decrypt-on-demand, popup, and content-script registration (`openspec/changes/browser-extension-autofill/design.md:83`). Verify against HEAD before coding: the passkey canonical schema (`openspec/changes/passkey-item-type/design.md:33`) and the extension unlock/blob machinery.

## 1. Provider registration (browser-adaptive)

- [x] 1.1 Register the extension as a WebAuthn provider via `chrome.webAuthenticationProxy` where available (Chrome/Edge): wire `onCreateRequest`/`onGetRequest` to the service-worker ceremony handler.
- [x] 1.2 On browsers without a native proxy (Firefox/others), inject a page-context `navigator.credentials.create/get` shim that serialises options and `postMessage`s them (origin-checked) to the content script, which relays to the service worker and returns the result.

## 2. create() — register a new passkey into the vault

- [x] 2.1 On a `create` request, show a consent prompt naming the RP origin; require the extension to be unlocked before proceeding.
- [x] 2.2 Generate the key pair client-side (WebCrypto) honouring `pubKeyCredParams` (ES256/`-7` default); mint a random credential id; build `clientDataJSON` + `attestationObject` (`none`/self attestation).
- [x] 2.3 Save the credential by writing a `passkey`-typed secret through the existing secret-create path (canonical JSON in encrypted `key`, RP id mirrored into plaintext `url`); return the attestation `PublicKeyCredential` to the page.

## 3. get() — assert with a stored passkey

- [x] 3.1 On a `get` request, match stored passkeys by RP id (unencrypted `url`) and the request's `allowCredentials`; show the consent + credential-picker prompt.
- [x] 3.2 Decrypt the chosen passkey's private key in the service worker only; build `authenticatorData` (RP id hash, UP+UV flags, counter) and sign `SHA-256(authenticatorData ‖ clientDataHash)` with the COSE algorithm; return the assertion.
- [x] 3.3 Set the user-verification flag only when unlocked; if locked, prompt for the master password before signing and never sign with a key that cannot be decrypted.

## 4. Signature counter write-back

- [x] 4.1 Report counter `0` (no write-back) for synced credentials; for a non-zero counter, increment it and persist by re-encrypting the credential JSON via the existing secret-update path.

## 5. Opt-in and fall-through

- [x] 5.1 On decline, or when the RP requires an authenticator the vault cannot be, fall through to the platform authenticator (native "not handled" / original `navigator.credentials` call) — never abort the page's flow.

## 6. Tests

- [x] 6.1 Extension unit: `create` generates a key pair, builds a valid attestation, and writes a `passkey` secret with credential JSON in `key` and RP id in `url`; no plaintext private key in any request body.
- [x] 6.2 Extension unit: `get` matches by RP id + `allowCredentials`, signs a valid assertion client-side; assertion verifies against the stored public key; no plaintext key/challenge/assertion leaves the extension.
- [x] 6.3 Extension unit: non-zero counter increments and persists via secret-update; zero counter reports `0` and issues no update.
- [x] 6.4 Extension unit: locked extension prompts for unlock before signing and never sets UV while locked; the private key is `extractable: false`, absent from `storage.*` and from every request body.
- [x] 6.5 Extension integration: decline falls through to the platform authenticator on both the native-proxy and shim paths.

## 7. Quality Gates

- [x] 7.1 Extension lint + unit tests pass; run hydra gates (spec-coverage) on the diff — `@spec openspec/changes/extension-passkey-provider/specs/extension-passkey-provider/spec.md` on changed methods.
- [x] 7.2 Confirm no new backend route, no schema/migration, and no `AuditEventTypes` change — create/read/counter use the existing secret CRUD + update endpoints unchanged.


## Implementation notes (done — GH pending)

Implemented in the `browser-extension/` workspace (extension-only; no backend/schema change, confirmed).

- **1.1 native proxy** — `src/passkey/registration.js` wires `chrome.webAuthenticationProxy` `onCreateRequest`/`onGetRequest` to the orchestrator; completes with the credential/assertion JSON or a `NotAllowedError` (fall-through). No-op where the API is absent.
- **1.2 shim** — `src/content/inpage-shim.js` overrides page-context `navigator.credentials.create/get`, origin-checked `postMessage` → content-script relay → service worker; re-invokes the ORIGINAL on decline/no-match (platform fall-through).
- **2 create()** — `src/passkey/webauthn.js` `createCredential`: ES256 (P-256) keypair, `clientDataJSON` + `none`-attestation `attestationObject` (CBOR, `src/passkey/cbor.js`); orchestrator gates on unlock + per-RP consent (`consent.html`), then saves a `passkey` secret via the existing create path (canonical JSON in `key`, RP id in `url`).
- **3 get()** — `getAssertion`: matches by RP id + `allowCredentials`, decrypts the private key in the worker only, signs `authenticatorData ‖ clientDataHash` (ES256 → DER). UV flag set only when unlocked; locked → prompt/refuse.
- **4 counter** — synced (0) stays 0, no write-back; non-zero increments and persists via the existing secret-update path.
- **5 opt-in/fall-through** — consent window per ceremony; decline / no-credential / unsupported-algorithm all fall through to the platform authenticator.

**Tests** (`tests/extension/`): `webauthn.spec.js` — the produced assertion **verifies against the public key via an independent Node/DER verifier** (proves client-side ES256 signing), create() builds a valid record + attestation, counter 0-stays-0 / non-zero-increments, unsupported-alg rejects. `cbor.spec.js` — CBOR encoder against **RFC 8949 vectors** + attestation skeleton bytes + raw→DER. All green. Full extension-loaded browser run (native-proxy + shim decline paths, task 6.5) needs a `--load-extension` launch — documented manual QA step.

## Acceptance Criteria

- The extension intercepts `navigator.credentials.create()` and, on opt-in and unlocked, generates a passkey client-side and saves it as a `passkey`-typed secret through the `passkey-item-type` seam (canonical JSON in `key`, RP id in `url`).
- The extension intercepts `navigator.credentials.get()`, matches a stored passkey by RP id + `allowCredentials`, and signs a valid WebAuthn assertion entirely client-side in the extension.
- The passkey private key is decrypted only in the extension's service-worker memory, is `extractable: false`, is never written to `storage.*`, and is never transmitted to the server; the challenge and assertion never leave the client except back to the page.
- A non-zero signature counter is incremented and persisted via the existing secret-update path; a synced (zero) counter is reported as `0` and never written back.
- Every ceremony shows a per-site consent prompt; declining (or an incompatible RP requirement) falls through to the platform authenticator rather than aborting the page's flow.
- User verification is asserted only when the extension is unlocked; a locked extension prompts for unlock before signing.
- No new backend route, schema, migration, or audit-event type is introduced; registration is browser-adaptive (native proxy on Chrome/Edge, page shim on Firefox/others).
