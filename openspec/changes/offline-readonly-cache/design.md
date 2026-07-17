## Context

Doriath is a live-fetch Vue SPA with no persistence layer: every unlock pulls the
EncryptionSuite from the API and decrypts client-side (`src/store/modules/session.js:59`),
and `grep -rn "serviceWorker|indexedDB|caches\." src/` is empty — there is no
offline story at all. The secret values the API returns are already RSA ciphertext
the server cannot read (ADR-003); only secret `name`/`url` and folder metadata are
plaintext (deliberately, to power search). The master-password KDF parameters
already travel with the private-key blob (`src/crypto/aes.js` PBKDF2 salt is stored
in the envelope), so offline unlock needs no server round-trip once the blob is
cached.

Doriath owns its own tables (ADR-001) and there is no OpenRegister. The offline
cache is a *client-side* store (IndexedDB); the only server-side additions are one
admin config flag and an optional consolidated snapshot endpoint.

## Goals / Non-Goals

**Goals:**
- Read a user's vault (list + open secrets) with no network, using an encrypted
  local snapshot refreshed on each online session.
- Offline unlock that is cryptographically identical to online unlock — same
  master-password KDF, same non-extractable `CryptoKey`, same ciphertext.
- Zero new cryptography and zero weakening of ADR-003: cached ciphertext is as
  safe as the server copy; plaintext metadata is additionally encrypted at rest.
- An admin org-wide off switch and deterministic eviction on lock/logout/rotation.

**Non-Goals:**
- Offline writes, a write queue, or conflict resolution (v1 is read-only — see D3).
- Background/periodic sync while the app is closed.
- Offline access to anonymous link-share / secret-request flows (token-scoped,
  inherently online).
- Passkey unlock offline (depends on `passkey-vault-login`; the two can compose
  later but are specced independently).

## Decisions

### D1: The cache stores ciphertext + KDF params; metadata is encrypted under the vault unlock key

The IndexedDB snapshot holds, per user: the active suite's AES-wrapped private-key
blob and its KDF parameters (as returned by the suites API), and, per secret, the
RSA ciphertext fields (`key`, `login`, `additional_fields`) plus the folder tree.
The RSA ciphertext is stored as-is (already server-safe). The *plaintext* metadata
— secret `name`/`url` and folder names — is instead encrypted at rest with an
AES-256-GCM envelope (`src/crypto/envelope.js`) keyed by the **vault unlock key**,
so a stolen locked device without the master password cannot even enumerate secret
names. Metadata is decrypted into memory only after a successful offline unlock.

*Alternative rejected*: caching plaintext metadata (simpler, enables an offline
"locked search"). Rejected because leaking secret names/URLs at rest on a field
worker's device is a real disclosure; the online search convenience does not
justify it offline.

### D2: Write-through refresh via one atomic manifest, served entirely from cache offline

On each **online** unlock the client fetches `GET /api/v1/offline/manifest` — a
single consolidated snapshot (suite blob + KDF params + all secret ciphertext +
folder tree + a server `syncedAt` timestamp) — and writes it to IndexedDB in one
transaction, replacing the prior snapshot atomically (no half-updated cache). When
**offline**, the service worker serves the app shell and the store reads the last
committed snapshot. A single manifest request avoids N per-secret round-trips and
gives a clean atomic refresh boundary.

*Alternative rejected*: incrementally caching each `GET /secrets/{id}` response as
the user browses. Rejected because it yields a partial, non-deterministic cache
(only visited secrets are available offline) — useless for a worker who needs a
credential they did not happen to open while online.

### D3: Strictly read-only offline — writes are disabled, not queued

Doriath's write path cannot be safely replayed from a stale snapshot. Sharing
re-encrypts a secret against **every recipient's current public certificate**
(ADR-003, "Sharing in E2E"; O(N) RSA ops), and sync-on-update does the same on
every edit. A queued offline edit could therefore re-encrypt to a certificate that
was revoked or rotated (compromise recovery) while offline, silently sending
ciphertext no one can read — or worse, to a stale key. Rather than ship an unsafe
queue, v1 **disables** all create/update/share/delete while offline and explains
why. A safe write queue is a deliberate future change, not a v1 gap papered over.

*Alternative rejected*: optimistic offline writes with later re-encryption.
Rejected on the certificate-staleness hazard above.

### D4: Eviction is deterministic and tied to the key lifecycle

The cache is cleared: on **logout**, on **vault lock** (the metadata-decryption key
is gone anyway), on **suite rotation / compromise recovery** (cached ciphertext is
encrypted to a now-dead key and MUST NOT linger), and when the **admin disables**
offline caching (purge on next load). This reuses the existing lock-hook mechanism
in the session store (`src/store/modules/session.js` `lockHooks` — the same seam
password-health uses to discard derived state on lock).

### Declarative-vs-imperative decision

Imperative PHP throughout, per ADR-001 — Doriath owns its tables and does not use
OpenRegister. The manifest endpoint is a thin read over the existing
suite/secret/folder mappers; the admin toggle is a plain `IAppConfig` value.

### Data model

No new secret-bearing DB table. Configuration only:

| Store | Key | Type | Default | Notes |
|-------|-----|------|---------|-------|
| `IAppConfig` (admin) | `offline_cache_enabled` | bool | `true` | Org-wide off switch |
| `IConfig` (per-user) | `offline_cache_optin` | bool | `true` | User can opt out on their devices |

Client-side IndexedDB store `doriath-offline` (per NC user), object stores:
`suite` (wrapped private-key blob + KDF params + `syncedAt`), `secrets`
(id → RSA ciphertext fields), `folders` (encrypted tree), `meta` (encrypted
name/url map). The `secrets`/`folders`/`meta` payloads that contain plaintext
metadata are AES-256-GCM enveloped under the vault unlock key (D1).

### Offline unlock + read flow (prose diagram)

```
App loads with no network:
  service worker serves shell + static assets (no secret material cached)
  │
Lock screen (offline):
  1. read IndexedDB `suite` → wrapped private-key blob + KDF params (salt, iters)
  2. user enters master password (never leaves browser)
  3. deriveAesKey(masterPassword, cachedSalt)  // identical to online path
  4. unwrap private key → import non-extractable CryptoKey
  5. use unlock key to AES-GCM-decrypt cached metadata (names/urls/folders)
  │
Vault view (offline, read-only):
  - list from decrypted metadata; open a secret → RSA-decrypt cached ciphertext
  - stale-data banner shows "Offline — last synced <syncedAt>"
  - all create/update/share/delete controls disabled with an explanation
```

### Online refresh flow (prose diagram)

```
Online unlock succeeds:
  1. GET /api/v1/offline/manifest → { suiteBlob, kdfParams, secrets[ciphertext],
        folders, syncedAt }
  2. encrypt plaintext metadata (names/urls/folders) under the vault unlock key
  3. write all object stores in ONE IndexedDB transaction (atomic replace)
  4. banner clears (fresh); syncedAt recorded
```

### Browser-support matrix note

Requires Service Worker API and IndexedDB — available in all evergreen browsers
(Chrome/Edge, Firefox, Safari 11.1+) and mandatory for PWA behavior. The service
worker requires a secure context (HTTPS or localhost), which Nextcloud already
mandates. Where a service worker cannot register (e.g. private-browsing modes that
disable it), the app falls back to online-only behavior — the offline cache is
simply absent, never a hard error. Detected at runtime via
`'serviceWorker' in navigator` and `'indexedDB' in window`.

### Decisions made under uncertainty

- **U1 — Whether cached plaintext metadata is worth protecting.** Uncertain how
  sensitive secret *names* are per deployment, so we chose the safe default:
  encrypt name/url/folder metadata at rest under the vault unlock key (D1),
  accepting that there is no offline "locked search" in exchange for no at-rest
  disclosure on a stolen locked device.
- **U2 — Default on or off.** Uncertain whether public-sector CISOs want secrets
  cached on endpoints by default. We default `offline_cache_enabled=true` (the
  field-worker demand is the driver) but give admins a first-class org-wide off
  switch and users a per-device opt-out, so a compliance mandate is one setting
  away rather than a code change.
- **U3 — Consolidated manifest vs. reusing existing endpoints.** Uncertain about
  payload size for very large vaults; we chose one atomic manifest for cache
  correctness (D2) and note that pagination/streaming of the manifest is a
  follow-up if vault sizes prove large enough to matter.
- **U4 — Read-only vs. a "safe subset" of offline writes.** We could not identify
  a write that is provably safe to replay offline given per-recipient
  re-encryption against possibly-rotated keys (D3), so v1 is strictly read-only;
  a write queue is deferred as an explicit, separately-designed change rather than
  a half-measure.

## Risks / Trade-offs

- **Encrypted secret ciphertext at rest on an endpoint** → the cached RSA
  ciphertext is only openable with the master-password-derived private key the
  device never stores, so at-rest exposure equals the server's existing posture;
  metadata is additionally encrypted (D1). Admins who still object can disable the
  feature org-wide.
- **Stale data misleading a user** → mitigated by an always-visible stale-data
  banner with the last sync time whenever data is served from cache, so the user
  is never silently shown outdated credentials.
- **Cache surviving a key rotation** → deterministic eviction on rotation/lock/
  logout (D4); the cache is never allowed to outlive the key it was encrypted for.
- **Service worker serving a stale app shell after deploy** → standard SW
  versioning (precache manifest keyed by build hash; activate-and-claim on new
  version) so a Doriath update invalidates the old shell.
- **Offline user expecting to edit** → writes are disabled with a plain-language
  explanation, not silently failing or dangerously queuing (D3).

## Migration Plan

1. Ship the `offline_cache_enabled` admin setting (default true) and the
   `GET /api/v1/offline/manifest` read endpoint (additive; no schema change).
2. Ship the service worker + IndexedDB cache behind runtime feature detection;
   with no SW/IndexedDB support the app is byte-for-byte the current online-only
   SPA.
3. First online unlock after deploy populates the cache; nothing to backfill.
4. **Rollback**: flip `offline_cache_enabled=false` (purges caches on next load)
   and/or remove the SW registration; the online path is unaffected.

## Open Questions

- Should the manifest be paginated/streamed for very large vaults, or is a single
  transaction acceptable up to some secret count? (U3 — measure before optimizing.)
- Should offline access itself be time-boxed (e.g. cache auto-expires N days after
  last successful sync) for compliance, independent of lock/logout eviction?
- How should the stale-data banner interact with the (future) write queue when
  that lands — does re-enabling writes require a fresh sync first?
