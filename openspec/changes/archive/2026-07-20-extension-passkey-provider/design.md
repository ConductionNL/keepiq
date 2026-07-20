# Design — extension-passkey-provider

## Context

`passkey-item-type` established that a passkey is stored as a canonical JSON object in a secret's encrypted `key` field — `credentialId`, `rpId`, `privateKey` (PKCS#8), `algorithm` (COSE, e.g. `-7` ES256), `counter`, `transports`, `userHandle`, plus metadata — with the RP id mirrored into the plaintext `url` for matching (`openspec/changes/passkey-item-type/design.md:33`–`:50`). It deliberately did **not** act as an authenticator: "it will write through the *same* secret-create path and canonical schema this change defines — the seam is 'a passkey is created by writing a `passkey`-typed secret whose `key` holds the canonical JSON,' and nothing about that seam assumes a UI, an import, or an extension" (`openspec/changes/passkey-item-type/design.md:56`). This change is the provider that writes and reads through that seam.

`browser-extension-autofill` already ships the runtime this needs: an MV3 background service worker holding the non-extractable WebCrypto `CryptoKey` in memory, in-extension unlock, encrypted blob fetch/decrypt-on-demand, a popup UI, and an `all_frames` content script (`openspec/changes/browser-extension-autofill/design.md:83`–`:88`). It explicitly left passkey/WebAuthn interception to a passkey change (`openspec/changes/browser-extension-autofill/proposal.md:31`). The provider reuses all of that and adds only the WebAuthn ceremony layer.

The trust model is unchanged (ADR-003): the passkey private key is decrypted and used to sign **only** in the extension's service-worker memory, using the browser-user client-side WebCrypto path (`openspec/architecture/adr-003-rsa-aes-encryption-architecture.md:65`). The server stores and returns the same ciphertext blob it already does.

## Goals / Non-Goals

**Goals:**
- Intercept `navigator.credentials.create()` and offer to save the new passkey into the vault, generating the key pair client-side and writing through the `passkey-item-type` seam.
- Intercept `navigator.credentials.get()` and authenticate with a stored vault passkey, signing the assertion client-side in the extension.
- Update the stored signature counter after a `get`, via the existing secret update path, only when the credential uses a non-zero counter.
- Per-site user opt-in; fall through to the platform authenticator on decline.
- Preserve zero-knowledge: the passkey private key is never persisted, never sent to the server, and only decrypted in the extension.

**Non-Goals:**
- Passkey login into Doriath's **own** lock screen (owned by the separate `passkey-vault-login` change).
- TOTP surfacing in the extension (separate `extension-totp-autofill` change).
- Attestation richer than `none`/self, enterprise attestation, or a hardware root of trust (Doriath is a software authenticator).
- Any server-side signing, key custody, or new backend route/schema.

## Decisions

### D1 — The WebAuthn ceremony is signed client-side in the extension, never server-side
On `get`, the extension decrypts the chosen passkey's `privateKey` into a non-extractable WebCrypto `CryptoKey` in the service worker, constructs `authenticatorData` (RP id hash, flags, counter) and signs `SHA-256(authenticatorData ‖ clientDataHash)` with the credential's COSE `algorithm`. On `create`, it generates the new key pair with WebCrypto and builds the attestation object. The server is never in the signing path — it only stores/returns the ciphertext blob (ADR-003 `:65`). *Rejected alternative:* a server endpoint that signs assertions with the stored key — violates ADR-003 (server never decrypts with entity context) outright.

### D2 — Create and read go through the `passkey-item-type` seam unchanged
A newly-minted passkey is saved by POSTing a `passkey`-typed secret whose encrypted `key` holds the canonical JSON and whose plaintext `url` mirrors the RP id — the exact contract `passkey-item-type` defined (`openspec/changes/passkey-item-type/design.md:56`). Reading a stored passkey for a `get` reuses the extension's decrypt-on-demand path. This change introduces **no new schema and no new endpoint**; it is a new *caller* of an existing seam. *Rejected alternative:* a bespoke passkey-provision API — duplicates the secret CRUD path and forks the canonical schema.

### D3 — Provider registration is browser-adaptive (native proxy, else page shim)
Where the browser exposes `chrome.webAuthenticationProxy` (Chrome/Edge), the extension registers as a remote authenticator through it — the browser routes ceremonies to the service worker without the page seeing an override. Where it does not (Firefox and others), a page-context script overrides `window.navigator.credentials.create/get`, forwards options to the service worker, and returns the result — the same dual mechanism Bitwarden and Proton Pass ship. Both paths converge on one service-worker ceremony handler. *Rejected alternative:* shim-only — loses the cleaner, CSP-robust native path on the majority (Chromium) browsers; proxy-only — leaves Firefox unsupported.

### D4 — Signature counter: keep `0` for synced credentials, write back only when non-zero
WebAuthn allows a signature counter of `0` to mean "unsupported," which is the multi-device / synced-passkey convention (a shared credential that increments on each copy triggers false clone-detection at the RP). Doriath passkeys default to counter `0` (`passkey-item-type` design.md:43, `:71`), so the assertion reports `0` and **no write-back occurs**. Only when a stored credential carries a **non-zero** counter (e.g. imported from a hardware authenticator) does the provider increment it and persist the new value by re-encrypting the credential JSON through the existing secret update path — satisfying "update the signature counter on the stored item" without breaking synced-credential RPs. *Rejected alternative:* always increment — breaks synced-passkey RPs with clone-detection.

### D5 — User verification is gated on the unlocked extension; consent is explicit and per-site
The UV flag is set in `authenticatorData` only when the extension is unlocked (master password entered, per `browser-extension-autofill` in-extension unlock). A locked extension prompts to unlock before signing and never signs with a key it cannot decrypt. Every ceremony shows a consent prompt naming the RP origin; the extension never registers or asserts silently. *Rejected alternative:* treat "paired" as sufficient for UV — pairing authorizes blob fetch, not decryption (`browser-extension-autofill/design.md:41`), so it cannot represent user verification.

### D6 — Decline falls through to the platform authenticator, never blocks
If the user declines the consent prompt, or the RP's `authenticatorSelection` requires an authenticator the vault cannot be (e.g. a platform/hardware-attested requirement), the extension **does not handle** the ceremony: the native proxy path returns "not handled" and the shim path calls the original `navigator.credentials` method. The page's passkey flow proceeds with the browser's own authenticator — Doriath never leaves the user stuck. *Rejected alternative:* throwing/aborting on decline — degrades the site's login for a user who simply didn't want to use the vault this time.

## Declarative-vs-imperative decision

Imperative, per ADR-001: Doriath owns its own tables and does **not** use OpenRegister. There is no declarative schema/register layer. The provider is standalone MV3 JS/TS in the `browser-extension/` workspace; the only persistence is writing/updating a `passkey`-typed secret through the existing imperative secret CRUD endpoints. No OR schema, no seed-data register.

## Data model deltas

**None.** No new table, no new column, no migration (ADR-001). A created passkey is a new `passkey`-typed row in the existing `doriath_secrets` table (canonical JSON ciphertext in `key`, RP id plaintext in `url`) written via the existing create endpoint; a counter write-back is an update to that same encrypted `key` via the existing update endpoint. `passkey` is already the seeded system type introduced by `passkey-item-type` (mirrors `totp` at `lib/Repair/SeedSecretTypes.php:69`). The server cannot distinguish a provider-created passkey from an imported one.

## Extension architecture (MV3)

- **Content script — WebAuthn interception layer** (`all_frames: true`, reusing the autofill content-script registration): on the **native-proxy** browser it plays no interception role (the browser routes to the worker); on the **shim** browser it injects a small page-context script that overrides `navigator.credentials.create/get`, serialises the `PublicKeyCredentialCreation/RequestOptions`, and `postMessage`s them to the content script, which relays to the service worker and returns the built credential/assertion.
- **Service worker — ceremony handler**: the single point that (a) on `create`, generates the WebCrypto key pair, builds `attestationObject` + `clientDataJSON`, and writes the `passkey` secret; (b) on `get`, matches candidates by RP id + `allowCredentials`, decrypts the chosen `privateKey`, builds `authenticatorData`, signs, and returns the assertion; (c) triggers the popup consent prompt and awaits the user's choice; (d) performs the counter write-back. Holds the passkey `CryptoKey` in memory only; clears on lock (reuses the autofill lock semantics).
- **Popup UI — consent + selection**: names the RP origin, offers Save-passkey (create) or a credential picker (get), and a decline action that triggers fall-through. Requires unlock before any sign.
- **Message passing**: page shim ⇄ content script via `window.postMessage` (origin-checked); content script ⇄ service worker via `chrome.runtime` messaging; service worker ⇄ popup via runtime messaging. The `chrome.webAuthenticationProxy` path delivers `onCreateRequest`/`onGetRequest` events straight to the service worker, bypassing the page shim.
- **Shared crypto**: the assertion/attestation builders and RSA/AES envelope reuse the web frontend's crypto module per ADR-003's dual-implementation invariant (`browser-extension-autofill/design.md:86`).

## WebAuthn ceremony flows

**create()** — RP calls `navigator.credentials.create({publicKey})` → extension shows consent for the RP origin → on accept and unlocked: generate key pair (algorithm from `pubKeyCredParams`, ES256 default), mint a random `credentialId`, build `attestationObject` (`none`/self attestation) + `clientDataJSON` → encrypt the canonical passkey JSON's `privateKey` (and the object) under the owner's public cert (WebCrypto) → POST a `passkey`-typed secret (canonical `key`, `url` = RP id) → return a `PublicKeyCredential` (attestation response) to the page → on decline, fall through.

**get()** — RP calls `navigator.credentials.get({publicKey})` → match stored passkeys where `url` = `rpId` and (if present) `credentialId ∈ allowCredentials` → consent + picker → on accept and unlocked: decrypt the chosen passkey's `privateKey` in the worker → build `authenticatorData` (RP id hash, UP+UV flags, counter) → sign `SHA-256(authenticatorData ‖ clientDataHash)` with the COSE `algorithm` → return the assertion → write back the counter if non-zero → on decline or no match, fall through.

## Risks / Trade-offs

- **Page-shim tampering / `navigator.credentials` override races** → prefer the native `chrome.webAuthenticationProxy` path where available; on the shim path, origin-check every `postMessage` and never sign without an explicit, freshly-confirmed consent for the exact RP origin.
- **Private-key leakage** → the passkey `CryptoKey` lives only in service-worker memory, `extractable: false`, cleared on lock; never written to any `storage.*`. A test gate asserts no passkey key material is persisted.
- **RP origin / RP-id spoofing** → match strictly on the RP id (registrable domain) against the request origin per WebAuthn rules; refuse to assert on an RP-id/origin mismatch.
- **Counter clone-detection** → default `0` (no write-back) for synced credentials; write back only genuine non-zero counters (D4).
- **Service-worker termination mid-ceremony** → the ceremony is atomic within one handler invocation; a dropped key is treated as a lock event and the user re-unlocks and retries.
- **Attestation limitations** → only `none`/self attestation; RPs demanding enterprise/hardware attestation trigger fall-through (D6) rather than a forged attestation.

## Decisions made under uncertainty

1. **WebAuthn signing happens client-side in the extension**, never server-side — chosen to keep ADR-003 zero-knowledge intact; the server never sees the private key, challenge, or assertion.
2. **Create/read reuse the `passkey-item-type` canonical seam** (secret CRUD, canonical JSON in `key`, RP id in `url`) rather than a bespoke provision API — chosen so the provider is a caller, not a schema fork.
3. **Browser-adaptive registration** — native `chrome.webAuthenticationProxy` on Chrome/Edge, page-context `navigator.credentials` shim on Firefox/others — chosen to match Bitwarden/Proton Pass and cover all target browsers with one ceremony handler.
4. **Signature counter kept at `0` for synced credentials, written back only when non-zero** — chosen to avoid RP clone-detection false positives while still honouring "update the counter on the stored item" for hardware-imported credentials.
5. **User verification gated on the unlocked extension**, consent explicit and per-site — chosen because pairing ≠ unlock (`browser-extension-autofill/design.md:41`); a locked extension cannot represent UV.
6. **Decline falls through to the platform authenticator** (native "not handled" / original `navigator.credentials` call) rather than aborting — chosen so declining the vault never breaks the site's own passkey flow.
7. **Attestation is `none`/self only** — chosen because Doriath is a software authenticator with no hardware root; enterprise-attestation RPs fall through rather than receive a forged attestation.
8. **ES256 (`-7`) default key generation**, honouring the page's `pubKeyCredParams` order — chosen as the near-universal RP-preferred algorithm; other COSE algorithms in `pubKeyCredParams` are honoured where WebCrypto supports them, else fall-through.
9. **Passkey vault-login and TOTP surfacing excluded** — owned by `passkey-vault-login` and `extension-totp-autofill` respectively.
