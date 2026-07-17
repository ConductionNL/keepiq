# Extension WebAuthn Passkey Provider Specification

**Status**: in-progress

**OpenSpec changes:**
- [extension-passkey-provider](../../changes/extension-passkey-provider/)

## Purpose

`passkey-item-type` lets Doriath **store** passkeys but leaves them inert — it explicitly deferred acting as a WebAuthn authenticator/provider to a later change. This feature is that provider: the browser extension intercepts `navigator.credentials.create()`/`get()` on relying-party pages, saves newly-created passkeys into the vault and authenticates with stored ones, and performs the WebAuthn assertion signing **client-side in the extension** with the decrypted passkey private key. It updates the stored signature counter, is gated on per-site user opt-in, and falls through to the browser's platform authenticator on decline. Zero-knowledge is preserved — passkey private keys are only ever decrypted in the extension's service-worker memory (ADR-003) — with zero server changes: create/read reuse the `passkey-item-type` secret seam and the counter write-back reuses the existing secret-update path. Without a provider, stored passkeys are a credential no site can use; with one, they become a first-class login on the Nextcloud platform (FIDO 2026: ~75% of consumers hold at least one passkey; NC Passwords #792, May 2026, asks for exactly store + autofill of passkeys).

## Requirements

### Requirement: Passkey registration interception (create)

The extension MUST intercept `navigator.credentials.create()` and, on per-site opt-in and while unlocked, generate a WebAuthn key pair client-side, build the attestation, and save the credential as a `passkey`-typed secret through the existing secret-create path (canonical JSON in encrypted `key`, RP id in plaintext `url`).

#### Scenario: Save a newly-created passkey into the vault
- GIVEN an unlocked extension and a page calling `navigator.credentials.create()` for `example.com`
- WHEN the user opts in
- THEN the extension MUST generate the key pair client-side, return a valid attestation to the page, and store a `passkey`-typed secret whose `url` is `example.com` and whose credential JSON is encrypted in `key`

### Requirement: Passkey authentication interception (get)

The extension MUST intercept `navigator.credentials.get()`, match stored passkeys by RP id and `allowCredentials`, and sign the assertion client-side with the decrypted passkey private key, which MUST never leave the extension.

#### Scenario: Authenticate with a stored passkey
- GIVEN an unlocked extension holding a passkey for `example.com` and a page calling `navigator.credentials.get()` for `example.com`
- WHEN the user selects it and confirms
- THEN the extension MUST decrypt the private key in the extension only, sign the assertion client-side, and return it to the page without any plaintext key, challenge, or assertion reaching the server

### Requirement: Signature counter update

The extension MUST report a signature counter in the assertion; a synced (zero) counter stays `0` with no write-back, while a non-zero counter MUST be incremented and persisted through the existing secret-update path.

#### Scenario: Non-zero counter is persisted
- GIVEN a stored passkey whose counter is `41`
- WHEN a `get` assertion completes with it
- THEN the assertion counter MUST be `42` and the extension MUST persist `42` via a secret update

### Requirement: Per-site opt-in and platform fall-through

The extension MUST show a per-site consent prompt before any ceremony and MUST fall through to the browser's platform authenticator on decline or on an incompatible RP requirement, never aborting the page's flow.

#### Scenario: Decline falls through
- GIVEN a page invoking `navigator.credentials.get()` and a consent prompt for the RP origin
- WHEN the user declines
- THEN the extension MUST NOT sign, and the ceremony MUST proceed with the platform authenticator

### Requirement: Zero-knowledge and unlock gating

The extension MUST set the user-verification flag only while unlocked, MUST prompt for unlock before signing when locked, and MUST hold the passkey private key only in service-worker memory (`extractable: false`), never persisting it to storage or transmitting it to the server.

#### Scenario: Locked extension requires unlock before signing
- GIVEN a locked extension and a page calling `navigator.credentials.get()`
- WHEN the ceremony reaches signing
- THEN the extension MUST require the master password before signing and MUST NOT set user verification until unlocked

## User Stories

- As a user, I want to register a passkey on a website and have it saved into my Doriath vault so I don't need a separate authenticator
- As a user, I want to log into a website with a passkey stored in my vault so my stored passkeys are actually usable
- As a security-conscious user, I want the WebAuthn signing to happen in the extension so the server never sees my passkey private key
- As a user, I want to decide per site whether Doriath handles my passkey, and to fall back to my platform authenticator when I decline
- As a user, I want a locked extension to ask for my master password before it will sign, so a stolen unlocked-looking browser can't authenticate as me

## Acceptance Criteria

- [ ] `navigator.credentials.create()` is intercepted; on opt-in and unlocked, a passkey is generated client-side and saved as a `passkey`-typed secret via the `passkey-item-type` seam
- [ ] `navigator.credentials.get()` is intercepted; a stored passkey is matched by RP id + `allowCredentials` and a valid assertion is signed entirely in the extension
- [ ] The passkey private key is `extractable: false`, held only in service-worker memory, never persisted to `storage.*`, and never sent to the server
- [ ] A non-zero signature counter is incremented and persisted via the existing secret-update path; a zero counter is reported as `0` with no write-back
- [ ] Every ceremony shows a per-site consent prompt; declining or an incompatible RP requirement falls through to the platform authenticator
- [ ] User verification is asserted only while unlocked; a locked extension prompts for unlock before signing
- [ ] No new backend route, schema, migration, or audit-event type is introduced; registration is browser-adaptive (native `chrome.webAuthenticationProxy`, else a page-context `navigator.credentials` shim)

## Notes

- Depends on `browser-extension-autofill` (MV3 runtime: service worker, in-extension unlock, decrypt-on-demand, popup, content-script registration) and `passkey-item-type` (canonical passkey schema + secret seam).
- Out of scope: passkey login into Doriath's own lock screen (`passkey-vault-login`), TOTP surfacing (`extension-totp-autofill`), enterprise/hardware attestation.
- Related ADRs: ADR-001 (own tables, imperative — no OpenRegister), ADR-003 (always E2E, browser-user client-side WebCrypto, unencrypted `url` for matching).
