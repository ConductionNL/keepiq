---
kind: code
---

# Proposal: Browser extension + autofill API

## Why

Doriath has no way to fill a credential into a login form. Every stored secret must be opened in the Doriath web UI and copy-pasted by hand. Doriath's own roadmap lists "Browser extension (Bitwarden-compatible API subset) — Auto-fill in browser" as a distinct feature (`docs/FEATURES.md:272`, `:470`) and names the browser-extension gap as a **High** risk versus Bitwarden (`docs/FEATURES.md:356`). The incumbent it must displace — Nextcloud Passwords, 500K+ installs — ships browser extensions as a core capability (`docs/FEATURES.md:15`), so a Nextcloud-native vault without autofill is not competitive.

Autofill is not a nice-to-have; it is the **#1 experiential complaint** across the Nextcloud secrets ecosystem:

- The Nextcloud Passwords browser extension's most persistent pain points are **pairing failures** (the extension losing its connection to the server) and **iframe autofill failures** (credentials not being offered on login forms embedded in iframes, which is most SSO/OAuth login pages). These recur in help.nextcloud.com support threads (e.g. `help.nextcloud.com/t/108643`).
- **Padloc**, a promising open-source vault, lost momentum partly because it lacked reliable browser autofill — a cautionary tale for any 2026 vault that treats autofill as optional.
- Autofill is **table-stakes tier-1** in 2026: Bitwarden, 1Password, Proton Pass, and Passbolt all ship a browser extension as their primary daily-driver surface.

Doriath is uniquely positioned to do this **without weakening its zero-knowledge model**. Its architecture already defines a non-browser-session client type — external applications authenticating via RFC 7523 JWT-Bearer and decrypting **locally** with their own private key (ADR-003 `openspec/architecture/adr-003-rsa-aes-encryption-architecture.md:98`; `JwtAuthService::exchangeAssertion`, `lib/Service/JwtAuthService.php:149`; `JwtAuthMiddleware`, `lib/Middleware/JwtAuthMiddleware.php`). A browser extension is exactly that shape: it authenticates against the user's Nextcloud session, unlocks the vault **in the extension** (master password → derive AES key → decrypt private key → WebCrypto `CryptoKey`, identical to the web client, ADR-003 `:63`), and the server only ever ships encrypted blobs. The decrypt location table in ADR-003 (`:63`) already lists "Browser user … Client-side (WebCrypto)"; the extension is a second such client. There is even a machine-readable discovery document (`DiscoveryController::document`, `lib/Controller/DiscoveryController.php:85`) an extension can use to locate the API.

This change makes autofill real while preserving the invariant that plaintext never leaves the client.

## What Changes

- Define a **browser-extension-facing API contract**: a stable, versioned surface the extension consumes — session pairing, encrypted-blob vault fetch, URL-indexed credential lookup, and save/update of a captured credential. Reuses the existing always-E2E secret endpoints (server returns blobs only, ADR-003 `:46`); adds only the thin pieces an extension needs (pairing + URL matching) that the web UI gets for free from its session.
- **Pairing / auth against the Nextcloud session**: the extension pairs by obtaining a scoped credential tied to the logged-in NC user — either a **Nextcloud app password** (device-scoped, revocable from NC security settings) or an OAuth-style authorization flow. No new long-lived Doriath secret is minted; revocation is native.
- **Vault unlock in the extension (zero-knowledge preserved)**: the master password is entered **in the extension**, derives the AES key, decrypts the private key, and imports a non-extractable WebCrypto `CryptoKey` held in the extension's background service worker memory — never sent to the server, never in `storage.local` (mirrors the web client, ADR-003 `:63`).
- **URL-matched credential list**: given the active tab's origin, the extension surfaces matching secrets (matched on the unencrypted `url`/`name` fields, ADR-003 `:56`), decrypting the chosen one client-side on demand.
- **Autofill into pages including iframes**: a content script fills username/password fields on the page and on same-origin/allowed cross-origin iframes, explicitly addressing the incumbent's iframe gap.
- **Save/update capture prompt on form submit**: on a login-form submission with credentials not already stored (or changed), the extension offers to save/update — encrypting client-side and POSTing a blob via the existing secret create/update path.
- **Extension auto-lock**: the in-memory `CryptoKey` is cleared on a configurable idle timeout, on browser lock/close, and on manual lock — matching the web client's lock semantics.
- **WebExtension (Manifest V3) MVP** for Firefox, Chrome, and Edge, living as an **in-repo workspace** (`browser-extension/`) so the shared PHP/JS crypto stays in lockstep with ADR-003's dual-implementation requirement (`:157`).
- **Explicitly out of scope**: Passkey/WebAuthn interception (covered by the separate `passkey-item-type` change — `openspec/changes/passkey-item-type/`), mobile apps, TOTP autofill, and a Bitwarden-wire-compatible API (this is a Doriath-native contract, not a Bitwarden server emulation).

## Capabilities

### New Capabilities
- `browser-extension-autofill`: A browser-extension-facing API contract plus a Manifest V3 WebExtension (Firefox/Chrome/Edge) that pairs against the Nextcloud session, unlocks the vault in-extension (client-side decrypt, zero-knowledge preserved), lists URL-matched credentials, autofills login forms including iframes, prompts to save/update captured credentials, and auto-locks on idle.

### Modified Capabilities
<!-- No existing capability's REQUIREMENTS change: the extension consumes the existing always-E2E secret endpoints (which already return blobs only) and the existing JWT/discovery surfaces; it adds a new pairing + URL-matching contract rather than altering secret CRUD behavior. -->

## Impact

- **New in-repo workspace**: `browser-extension/` (MV3 manifest, background service worker, content scripts, popup UI) — shares the crypto module with the web frontend to honor ADR-003's dual-implementation invariant.
- **New/extended backend**: a browser-extension pairing controller + routes; a URL-matching query on the unencrypted `url`/`name` columns; reuse of the existing blob-returning secret endpoints and `DiscoveryController` (`lib/Controller/DiscoveryController.php:85`).
- **Auth**: leans on Nextcloud app passwords / OAuth — no new Doriath long-lived credential; revocation is native to NC security settings.
- **Security**: zero-knowledge preserved — decrypt happens in the extension, server sees only blobs (ADR-003). New attack surface (extension storage, content-script injection, iframe fill) must be threat-modeled; the `CryptoKey` must never touch `storage.local`.
- **OpenConnector**: unaffected — the extension is a browser-user surface, separate from the machine `doriath://` path.
- **Docs**: extension install/pairing guide; explicit statement that autofill is client-side decrypt.
