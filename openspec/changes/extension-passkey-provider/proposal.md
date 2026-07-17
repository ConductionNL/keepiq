---
kind: code
depends_on: [browser-extension-autofill, passkey-item-type]
---

# Proposal: Extension WebAuthn passkey provider (create + get interception)

## Why

The `passkey-item-type` change makes Doriath **store** passkeys but leaves them **inert data**: it explicitly defers "acting as a WebAuthn *authenticator/provider* in the browser (intercepting `navigator.credentials.create/get` to register and assert passkeys)" to "a **later change**" that "will write through the *same* secret-create path and canonical schema this change defines" (`openspec/changes/passkey-item-type/proposal.md:27`; `openspec/changes/passkey-item-type/design.md:21`, `:56`). This is that later change. Without it, a user can import or store a passkey but can never actually log in with one — the vault holds a credential no site can use.

Passkey **provision** (not just storage) is 2026 table stakes:

- **The stored passkey is unusable without a provider.** `passkey-item-type` stores the credential id, RP id, PKCS#8 private key, COSE algorithm and signature counter as canonical JSON in the encrypted `key` field (`openspec/changes/passkey-item-type/design.md:33`–`:45`). Every field needed to *sign a WebAuthn assertion* is present, but nothing invokes it — a classic orphaned capability. This change makes the stored credential do work.
- **Every serious competitor provides, not just stores.** Bitwarden and Proton Pass both act as browser passkey providers via `navigator.credentials` interception; Doriath's own analysis flags the FIDO2/WebAuthn gap three times as **High** risk (`docs/FEATURES.md:24`, `:308`, `:356`) and lists passkeys among tier-1 platform wishes (`docs/FEATURES.md:499`).
- **Demand on the Nextcloud platform is explicit.** NC Passwords issue **#792 (opened May 2026)** asks for exactly store **and autofill** of passkeys, and the incumbent has three open passkey issues with no support (`openspec/changes/passkey-item-type/proposal.md:15`). FIDO Alliance 2026 data puts **~75% of consumers** with at least one passkey — users arrive expecting their vault to complete the sign-in ceremony, not just archive the key.
- **The autofill extension already reserved this seam.** `browser-extension-autofill` puts passkey/WebAuthn interception out of scope precisely because it belongs to a passkey change (`openspec/changes/browser-extension-autofill/proposal.md:31`); this change fills that reserved seam using the MV3 extension's existing background service worker, popup consent UI, and content-script injection (`openspec/changes/browser-extension-autofill/design.md:83`–`:88`).

Critically, this preserves zero-knowledge with **zero server changes**: the WebAuthn assertion is signed **client-side in the extension** with the passkey private key, decrypted only in the extension's service-worker memory exactly like every other secret (ADR-003 browser-user client-side WebCrypto path, `openspec/architecture/adr-003-rsa-aes-encryption-architecture.md:65`). The server never sees the private key, the challenge, or the assertion — it only ever stores and returns the same ciphertext blob `passkey-item-type` already defined.

## What Changes

- **Intercept `navigator.credentials.create()` on relying-party pages** (passkey registration). On opt-in, the extension generates a new WebAuthn key pair **client-side** (WebCrypto, honoring the page's `pubKeyCredParams`, ES256/`-7` default), builds the attestation response, and saves the credential into the vault by writing a `passkey`-typed secret through the **exact seam `passkey-item-type` defined** — canonical JSON in the encrypted `key`, RP id mirrored into plaintext `url` (`openspec/changes/passkey-item-type/design.md:56`). No new create path.
- **Intercept `navigator.credentials.get()` on relying-party pages** (passkey authentication). The extension matches stored vault passkeys by RP id (unencrypted `url`, ADR-003 `:57`) and the request's `allowCredentials`, prompts the user to pick + confirm, decrypts the chosen passkey's private key **in the extension**, signs the WebAuthn assertion (`clientDataHash` ‖ `authenticatorData`) with WebCrypto, and returns the assertion to the page.
- **Update the signature counter on the stored item** after a `get` assertion, when the stored credential uses a non-zero counter — re-encrypting the credential JSON and persisting it via the **existing secret update path** (no new endpoint). Synced/software passkeys that carry counter `0` keep `0` (WebAuthn multi-device convention), so no write-back occurs for them.
- **User opt-in per site, fall through on decline.** Before any ceremony the extension shows a consent prompt naming the RP origin; on decline (or when the RP demands a platform authenticator the vault can't be), the extension falls through to the browser's platform authenticator rather than blocking the ceremony.
- **Provider registration path is browser-adaptive.** Where the browser exposes a native WebAuthn proxy API (`chrome.webAuthenticationProxy`, Chrome/Edge) the extension registers through it; otherwise it injects a page-context `navigator.credentials.create/get` shim (Firefox and others) — the same dual approach Bitwarden and Proton Pass ship.
- **User verification is satisfied by the unlocked vault.** The assertion's UV flag is set only when the extension is unlocked (master password entered, per `browser-extension-autofill`'s in-extension unlock); a locked extension prompts for unlock before signing, and never signs with a key it cannot decrypt.
- **Explicitly out of scope:** turning Doriath's own lock screen into a passkey login (that is the separate `passkey-vault-login` change), TOTP surfacing (separate `extension-totp-autofill`), attestation beyond `none`/self, and any server-side signing or key custody.

## Capabilities

### New Capabilities

- `extension-passkey-provider`: The browser extension acts as a WebAuthn credential provider — intercepting `navigator.credentials.create()`/`get()` on pages, saving newly-created passkeys into the vault and asserting with stored ones, signing the ceremony client-side in the extension with the decrypted passkey private key, updating the stored signature counter, gated on per-site user opt-in with fall-through to the platform authenticator on decline. The canonical home for the WebAuthn provider role that `passkey-item-type` deferred.

### Modified Capabilities

<!-- None. This change consumes the passkey canonical schema and secret CRUD/update paths that `passkey-item-type` and the existing secret endpoints already define, and the pairing/unlock/blob surfaces `browser-extension-autofill` defines — it adds a new provider capability rather than altering any existing requirement's behavior. The signature-counter write-back uses the unchanged secret update endpoint. -->

## Impact

- **Extension workspace (`browser-extension/`)**: new content-script WebAuthn interception layer (native proxy registration where available, page-context `navigator.credentials` shim otherwise), service-worker WebAuthn ceremony logic (key-pair generation, attestation/assertion building, client-side signing with WebCrypto), and popup consent UI for per-site opt-in and credential selection. Reuses the existing background `CryptoKey`, unlock, and blob-fetch machinery (`openspec/changes/browser-extension-autofill/design.md:83`–`:88`).
- **Backend**: none new — passkeys are created and the counter written back through the **existing secret create/update endpoints** (`passkey`-typed secret, canonical JSON in `key`, RP id in `url`), exactly as `passkey-item-type` defined. No new route, no schema/migration, no `AuditEventTypes` change.
- **Data model**: no new tables/columns (ADR-001, Doriath owns its tables, imperative). The only mutation is an update to an existing `passkey` secret's encrypted `key` (counter) via the existing path.
- **Security**: zero-knowledge preserved — the passkey private key is decrypted and used to sign **only inside the extension's service worker** (ADR-003 `:65`); it is never persisted (never `storage.local`), never sent to the server, and the WebAuthn challenge/assertion never leave the client except back to the page. New attack surface (a script overriding `navigator.credentials`, cross-page consent spoofing) must be threat-modeled: strict RP-origin matching, explicit user confirmation before every sign, and fall-through — never silent — on decline.
- **OpenConnector**: unaffected — passkey provision is a browser-user surface, separate from the machine `doriath://` secret-store path.
- **Docs**: extension passkey guide; explicit statement that WebAuthn signing is client-side in the extension (server sees only the stored ciphertext blob).
