# Design: Browser extension + autofill API

## Context

Doriath is always end-to-end: the server returns encrypted blobs and never decrypts secrets with entity context (ADR-003, `openspec/architecture/adr-003-rsa-aes-encryption-architecture.md:46`). The web frontend already implements the full client-side unlock/decrypt path — master password → AES key → decrypt private key → non-extractable WebCrypto `CryptoKey` in JS memory (`:63`). A browser extension is a **second instance of the same client**: it must reproduce that unlock path in its own runtime, not add a server-side decrypt shortcut.

The pieces an extension needs beyond the existing web session are (a) a way to authenticate its background worker to the API without a live NC browser session cookie, and (b) URL-based credential matching. Doriath already has the auth primitive: JWT-Bearer for non-session clients (`JwtAuthService::exchangeAssertion`, `lib/Service/JwtAuthService.php:149`; `validateAccessToken`, `:284`; `JwtAuthMiddleware`, `lib/Middleware/JwtAuthMiddleware.php`) and a discovery document to locate the API (`DiscoveryController::document`, `lib/Controller/DiscoveryController.php:85`). URL matching is possible because `name` and `url` are stored unencrypted precisely to enable search (ADR-003 `:56`).

Passkey/WebAuthn is deliberately excluded — a separate `passkey-item-type` change (`openspec/changes/passkey-item-type/`) owns that item type and its ceremony.

## Goals / Non-Goals

**Goals:**
- Pair the extension to the logged-in Nextcloud user with a natively-revocable credential (app password or OAuth-style flow).
- Unlock the vault **in the extension**, client-side, preserving zero-knowledge — the server never sees the master password, the derived key, or plaintext.
- Offer URL-matched credentials on the active tab and autofill them, **including into iframes** (the incumbent's weak spot).
- Prompt to save/update on login-form submit, encrypting client-side.
- Auto-lock the in-extension key on idle / browser lock / manual lock.
- Ship an MV3 extension (Firefox/Chrome/Edge) sharing the web client's crypto code (ADR-003 dual-implementation invariant, `:157`).

**Non-Goals:**
- Passkey/WebAuthn interception (separate change).
- Bitwarden wire-protocol emulation — this is a Doriath-native contract.
- Mobile apps, TOTP autofill, and offline vault caching beyond the in-memory session.
- Any server-side decrypt path for the extension.

## Decisions

### Decision: The extension is a second E2E client, not a server-decrypt surface

The extension reproduces the web client's unlock path in its background service worker: fetch the AES-encrypted private-key blob, take the master password entered in the extension popup, derive the AES key, decrypt the private key, import it as a WebCrypto `CryptoKey` with `extractable: false`. All secret decryption happens against that key inside the extension. The server continues to return only blobs (ADR-003 `:46`).

*Rejected alternative:* a lightweight server endpoint that returns decrypted values to a paired extension. Rejected outright — it violates ADR-003 requirement 5 (server never decrypts with entity context).

### Decision: Pairing uses a Nextcloud-native credential, not a new Doriath secret

The extension pairs by obtaining a **Nextcloud app password** (device-scoped, listed and revocable under NC Settings → Security → Devices & sessions) or, alternatively, an **OAuth-style authorization-code flow** against the NC session. The API accepts that credential the same way the JWT-Bearer path already accepts application tokens (`JwtAuthMiddleware`). Doriath mints **no** new long-lived credential of its own; revocation is native to Nextcloud, so a lost/compromised extension is killed from NC security settings without a Doriath admin action.

*Rejected alternative:* a bespoke Doriath extension token table. Rejected — it duplicates NC app-password revocation and adds a credential-custody burden (contrary to the fleet's ADR-064 custody posture); app passwords already solve device pairing.

*Note on unlock vs pairing:* pairing authorizes the extension to fetch **blobs**; it does **not** unlock the vault. Unlock requires the master password entered in the extension. A paired-but-locked extension can list secret names/URLs (unencrypted) but cannot decrypt values.

### Decision: URL matching on the unencrypted index fields, decrypt-on-demand

The extension lists candidates by matching the active tab's origin against the unencrypted `url` (and `name`) fields (ADR-003 `:56`) — either client-side over the fetched blob list or via a thin server query that returns blob rows for a given host filter. Only when the user picks a candidate to fill does the extension decrypt that single secret's blob with the in-memory `CryptoKey`. This keeps decryption minimal and never bulk-decrypts the vault.

### Decision: Content-script fill covers iframes explicitly

The content script enumerates fillable fields on the top document **and** on child iframes it can access (same-origin, plus cross-origin frames the extension is granted access to via `all_frames: true` in the content-script registration). Field detection uses autocomplete tokens + heuristic username/password detection. This directly targets the incumbent's iframe gap (help.nextcloud.com/t/108643). Where a cross-origin iframe cannot be scripted, the extension surfaces the credential in the popup for manual copy rather than silently failing.

### Decision: Save/update capture on submit, encrypted client-side

A submit-time listener detects credentials entered into a login form. If no stored secret matches the origin+username, the extension offers "Save"; if one matches but the password differs, it offers "Update". On confirm, the extension encrypts the value with the user's public certificate (WebCrypto) and POSTs a blob via the existing secret create/update endpoints — identical to how the web client writes.

### Decision: Auto-lock semantics mirror the web client

The `CryptoKey` lives only in the background service-worker memory (never `storage.local`/`storage.sync`). It is cleared on: a configurable idle timeout (default e.g. 15 min), OS/browser lock or the service worker being terminated, and a manual "Lock" action. After lock, blob listing (unencrypted fields) may remain but no value can be decrypted until re-unlock.

### Declarative-vs-imperative decision

Doriath has no OpenRegister — everything is imperative by ADR-001. The pairing controller, URL-match query, and blob endpoints are imperative PHP; the extension is a standalone MV3 JS/TS workspace. There is no declarative schema/register layer.

### Repository decision: in-repo `browser-extension/` workspace

The MV3 extension lives in an **in-repo workspace** `browser-extension/` rather than a separate repository. Rationale: ADR-003 requires the crypto (chunking, RSA, AES, KDF) to be identical across implementations and cross-tested (`:157`); co-locating the extension lets it import the same crypto module the web frontend uses and keeps the round-trip tests in one CI. Recorded as a decision under uncertainty (a separate repo is a future option if release cadence diverges).

## API surface

Existing, reused (return blobs only — ADR-003 `:46`):
- `GET /api/v1/app/.well-known/doriath` — discovery (`DiscoveryController::document`).
- Existing encrypted-private-key fetch + secret list/get endpoints (blobs).
- Existing secret create/update endpoints (accept blobs) for save/update capture.

New / thin additions:
- `POST /api/v1/extension/pair` — exchange an NC app-password / OAuth code for the scoped access the extension uses (or documents the app-password header flow if no exchange is needed).
- `GET /api/v1/extension/match?host=<origin>` — return blob rows whose unencrypted `url`/`name` match the host (server-side filter; optional — the extension MAY filter client-side over the full blob list).
- `POST /api/v1/extension/unpair` (or rely on NC app-password revocation) — end the pairing.

All extension endpoints authenticate via the paired credential through the existing middleware chain; none returns plaintext.

## Extension architecture (MV3)

- **Background service worker**: holds the WebCrypto `CryptoKey` in memory; owns unlock, lock timer, blob fetch/cache (encrypted), decrypt-on-demand.
- **Popup UI**: unlock screen (master password), URL-matched credential list, save/update prompt, lock button, settings (idle timeout, host).
- **Content script** (`all_frames: true`): field detection + fill on top document and iframes; submit-capture listener.
- **Shared crypto module**: imported from the web frontend's crypto source so PHP↔JS round-trips stay valid (ADR-003 `:157`).
- **Storage**: only non-sensitive config in `storage.local`; the `CryptoKey` and plaintext never persisted.

## Frontend surfaces (Doriath web app)

- A "Connect browser extension" panel in user settings (`CnSettingsSection`) with pairing instructions + a link to NC Security settings to manage/revoke the device.
- Documentation page: how autofill preserves zero-knowledge (decrypt in the extension).

## Risks / Trade-offs

- **Content-script injection is a new attack surface** → fill only detected credential fields, never expose the full vault to the page; require explicit user selection before any fill; CSP-safe messaging between content script and worker.
- **`CryptoKey` leakage** → enforce in-memory-only storage, `extractable: false`, and clear on lock; never write to any `storage.*`. A lint/test gate asserts no key material is persisted.
- **Cross-origin iframe fill limits** → some frames can't be scripted; degrade to popup-based manual copy rather than a broken silent autofill (honest failure, unlike the incumbent).
- **MV3 service-worker termination** drops the in-memory key → treated as an auto-lock event; user re-unlocks. Acceptable and consistent with lock semantics.
- **Pairing credential scope** → an app password grants broad NC access; document that the extension only uses it against Doriath endpoints and that users can scope/revoke it in NC. OAuth-style flow is the tighter-scoped alternative offered.
- **Phishing / look-alike origins** → match strictly on registrable domain/origin; never fill on a mismatched host; warn on http (non-TLS) origins.

## Decisions made under uncertainty

1. **In-repo `browser-extension/` workspace** (not a separate repo) — chosen so the crypto module and cross-implementation tests stay unified per ADR-003; revisit if release cadence diverges.
2. **Pairing via NC app password / OAuth-style flow** rather than a bespoke Doriath extension-token table — chosen so revocation is native to Nextcloud and no new credential custody is introduced.
3. **Pairing ≠ unlock**: a paired extension can list unencrypted names/URLs but must have the master password entered to decrypt — chosen to keep zero-knowledge intact even for a paired device.
4. **URL matching on the unencrypted `url`/`name` fields** with decrypt-on-demand — chosen because those fields are already plaintext for search (ADR-003 `:56`) and it avoids bulk-decrypting the vault.
5. **`all_frames: true` content script** to cover iframes — chosen to fix the incumbent's #1 complaint; cross-origin frames that can't be scripted degrade to manual copy.
6. **Idle auto-lock default (~15 min)** and clear-on-worker-termination — chosen to mirror the web client's lock semantics; exact default is configurable.
7. **Doriath-native API contract, not Bitwarden-wire-compatible** — chosen to avoid emulating a foreign server model; the roadmap's "Bitwarden-compatible API subset" phrasing (`docs/FEATURES.md:272`) is interpreted as feature-parity, not wire-compatibility.
8. **MV3 (not MV2)** targeting Firefox/Chrome/Edge — chosen because Chrome/Edge require MV3 and Firefox supports it; a single manifest reduces divergence.
9. **TOTP autofill and passkeys excluded** — TOTP deferred; passkeys owned by `passkey-item-type`.
