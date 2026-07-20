---
kind: code
---

# Proposal: Read-only offline vault cache

## Why

Doriath is web-first with **zero offline capability today**. There is no service
worker and no client-side persistence: `grep -rn "serviceWorker|caches\.|indexedDB" src/`
returns nothing — the app is a plain Vue SPA that fetches ciphertext live on every
unlock (`src/store/modules/session.js:59` fetches the suite from the API, then
decrypts client-side). Close the tab in a tunnel or a basement server room and the
vault is simply unavailable.

That is a real public-sector persona pain. Municipal field workers — building
inspectors, outdoor-service and enforcement staff — routinely lose connectivity
and, with it, all access to the credentials they need on site. Doriath's own
competitive analysis rates offline access as a **tier-2 "table-stakes but
unevenly executed"** capability: Bitwarden's clients cache the vault for temporary
offline access and KeePassXC/Enpass are offline-first, while Doriath offers
nothing (`docs/FEATURES.md:84` only mentions an Enterprise "export to PDF" as an
offline stopgap). The canonical feature `offline-cache` sits in the Spectr
register at demand 50 — solid demand against a partial-coverage field.

Crucially this can be built without breaking ADR-003's zero-knowledge model. The
secret values Doriath serves are already RSA ciphertext the server itself cannot
read; caching that ciphertext locally is exactly as safe as the server storing
it. Offline unlock is the *same* client-side operation as online unlock — the
browser re-derives the AES key from the master password
(`src/crypto/aes.js:18`, PBKDF2 params already travel with the private-key blob)
and decrypts the cached ciphertext with the recovered private key. The only new
surface is *where the ciphertext lives* (an encrypted IndexedDB cache) and *how
the shell loads offline* (a service worker) — no new cryptography.

We scope v1 to **strictly read-only** because Doriath's write path cannot be
safely replayed offline: sharing fans out O(N) RSA re-encryptions against every
recipient's *current* public certificate (ADR-003, "Sharing in E2E"), and
sync-on-update re-encrypts for every recipient — neither can be reconstructed
from a stale local snapshot without risking encrypting to a revoked/rotated key.
Queued offline writes are therefore deferred; v1 delivers reliable offline *read*.

## What Changes

- **Encrypted local cache (IndexedDB).** On each online unlock, write through a
  local snapshot: the AES-wrapped private-key blob + its KDF parameters, every
  secret's RSA ciphertext, and the folder tree. Secret **names/URLs and folder
  metadata** (plaintext on the server, to enable search) are encrypted at rest in
  the cache under the vault unlock key, so a stolen offline device without the
  master password reveals nothing — not even secret names.
- **Service worker for the app shell.** Register a service worker that caches the
  Vue app shell (HTML/JS/CSS) so Doriath loads with no network. The service
  worker serves shell + static assets only; it never caches decrypted secret
  material.
- **Offline unlock re-derives the master key locally.** With KDF params and the
  wrapped private key cached, the lock screen works offline exactly as online:
  the entered master password derives the AES key, unwraps the private key,
  imports a non-extractable `CryptoKey`, and decrypts cached ciphertext — no
  server round-trip.
- **Strictly read-only offline (no queued writes in v1).** All create/update/
  share/delete actions are disabled while offline, with a clear explanation.
  Rationale documented above and in design: per-recipient share fan-out and
  sync-on-update cannot be safely replayed from a stale snapshot.
- **Stale-data banner.** When serving from cache (offline, or online before a
  refresh completes), show a banner with the last successful sync time so the
  user knows the data may be out of date.
- **Admin org-wide toggle.** An admin setting (`IAppConfig`) can disable offline
  caching for the whole instance; when disabled, no cache is written and any
  existing cache is purged on next load. Compliance environments that forbid
  secrets-at-rest on endpoints can turn the feature off.
- **Cache eviction.** The cache is cleared on logout, on vault lock, and on
  suite rotation/compromise recovery (the cached ciphertext is encrypted to a
  now-dead key and MUST NOT linger).
- **Explicitly out of scope for v1**: offline writes / write queue and conflict
  resolution, background sync, and offline access to link-share / secret-request
  public flows (those are anonymous, token-scoped, and inherently online).

## Capabilities

### New Capabilities
- `offline-readonly-cache`: Encrypted IndexedDB snapshot, service-worker app
  shell, offline unlock + read, stale-data banner, admin org-wide toggle, and
  cache eviction on lock/logout/rotation.

### Modified Capabilities
<!-- None. Online unlock/read behavior in encryption-suites and secrets is
     unchanged; this change adds an offline read path and a write-through cache. -->

## Impact

- **No new secret DB table.** The cache is client-side (IndexedDB). One admin
  setting stored via `OCP\IAppConfig` (`offline_cache_enabled`) and an optional
  per-user opt-in via `OCP\IConfig` (user-settings).
- **New backend endpoint (optional but recommended):** `GET /api/v1/offline/manifest`
  returning a single consolidated, atomic snapshot (active suite blob + KDF
  params + all secret ciphertext + folder tree + a server sync timestamp) so a
  refresh is one request instead of many. Ciphertext only — the server never
  decrypts (ADR-003 unchanged).
- **Frontend:** new service worker + registration in `src/main.js`; an
  IndexedDB cache module (`src/offline/cache.js`) with cache-key encryption;
  offline-aware lock screen and read views; stale-data banner; write-disable
  guard while offline.
- **Build:** webpack must emit the service worker with a stable URL and
  `publicPath:'auto'`; the shell precache list is generated at build time.
- **No impact on OpenConnector / internal-app `DecryptService`** — machine
  clients do not use the browser cache.
