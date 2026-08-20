# Offline Read-only Cache Specification

**Status**: done

**Standards**: Service Worker API, IndexedDB, WebCrypto (PBKDF2, AES-256-GCM, RSA-OAEP)
**Feature tier**: V1

**OpenSpec changes:** [offline-readonly-cache](../../changes/archive/2026-07-20-offline-readonly-cache/)

## Purpose

Give Doriath users read-only access to their vault with no network. An encrypted
IndexedDB snapshot of the user's secret ciphertext, folder metadata, and KDF
parameters is refreshed on each online session; a service worker serves the app
shell offline; and offline unlock re-derives the master key locally exactly as
online. The feature preserves ADR-003's zero-knowledge model — cached ciphertext
stays encrypted, plaintext metadata is encrypted at rest under the vault unlock
key, and the master password never leaves the browser. Offline mode is strictly
read-only in v1.

Competitive context: offline access is a tier-2 "table-stakes but unevenly
executed" capability (`docs/FEATURES.md:84`); Bitwarden caches vaults for
temporary offline use and KeePassXC/Enpass are offline-first, while Doriath has
zero offline capability today. Canonical feature `offline-cache`, Spectr demand 50.
Driving persona: municipal field workers who lose connectivity on site.

## Requirements

### Requirement: Online sessions write through an encrypted local snapshot
The system MUST, on each online unlock when offline caching is enabled, write an
IndexedDB snapshot (wrapped private-key blob + KDF params + all secret ciphertext
+ folder tree + sync timestamp), replacing any prior snapshot atomically, with
secret names/URLs and folder metadata encrypted at rest under the vault unlock key.

#### Scenario: Unlocking online populates the cache
- GIVEN offline caching is enabled and a user unlocks online
- WHEN the write-through completes
- THEN the snapshot MUST hold the wrapped private-key blob, KDF params, all secret
  ciphertext, the folder tree, and a sync timestamp, with metadata encrypted at rest

### Requirement: Offline unlock re-derives the master key locally
The system MUST let a user unlock and read their vault with no network by deriving
the AES key from the entered master password using the cached KDF parameters and
decrypting cached ciphertext client-side, with the master password never leaving
the browser.

#### Scenario: Offline unlock opens the vault for reading
- GIVEN a populated cache and no connectivity
- WHEN the user enters their correct master password
- THEN the browser MUST derive the key from cached KDF params, unwrap the private
  key into a non-extractable `CryptoKey`, and decrypt cached content with no server
  request

### Requirement: The app shell loads offline via a service worker
The system MUST serve the app shell from a service worker with no network, and the
service worker MUST NOT cache any decrypted secret material.

#### Scenario: App shell loads with no network
- GIVEN a registered service worker and no connectivity
- WHEN the user opens Doriath
- THEN the shell MUST load from the service worker cache and no decrypted secret
  material MUST be present in that cache

### Requirement: Offline mode is strictly read-only
The system MUST disable all create, update, share, and delete actions while
offline and explain why, rather than queuing writes.

#### Scenario: Write actions are disabled offline
- GIVEN a user viewing their vault offline
- WHEN they attempt to create, edit, share, or delete a secret
- THEN the action MUST be prevented with an explanation that Doriath is read-only
  offline

### Requirement: A stale-data banner shows the last sync time
The system MUST show a stale-data banner with the last successful sync time
whenever vault data is served from the local cache, and clear it after a fresh
online refresh.

#### Scenario: Banner appears when reading cached data offline
- GIVEN a user reading their vault from the offline cache
- WHEN the vault view renders
- THEN a banner MUST indicate offline/cached data and show the last sync time

### Requirement: An admin can disable offline caching org-wide
The system MUST provide an admin setting that disables offline caching for the
whole instance; when disabled, no cache MUST be written and any existing cache
MUST be purged on next load.

#### Scenario: Disabling offline caching purges existing caches
- GIVEN an admin sets `offline_cache_enabled` to false
- WHEN a user next loads Doriath
- THEN no snapshot MUST be written and any existing snapshot MUST be purged

### Requirement: The cache is evicted on lock, logout, and suite rotation
The system MUST clear the local snapshot on vault lock, logout, and suite
rotation/compromise recovery, so cached ciphertext never outlives its key.

#### Scenario: Suite rotation evicts the now-undecryptable cache
- GIVEN a user with a populated offline cache
- WHEN compromise recovery rotates their suite to a new RSA key pair
- THEN the local snapshot MUST be evicted

## User Stories

- As a municipal field worker, I want to read my credentials on site with no
  signal so that losing connectivity does not block my work
- As a user, I want offline data clearly marked as possibly stale so that I know
  when it was last synced
- As a security admin, I want to disable offline caching org-wide so that no
  secrets are cached on endpoints in a compliance-sensitive deployment
- As a user, I want the cache cleared when I lock or rotate my keys so that stale
  ciphertext never lingers

## Acceptance Criteria

- [ ] Each online unlock (when enabled) writes an encrypted snapshot atomically; metadata is encrypted at rest under the vault unlock key
- [ ] Offline unlock re-derives the master key from cached KDF params with no server request; the master password never leaves the browser
- [ ] The service worker serves the app shell offline and never caches decrypted secret material
- [ ] All write actions are disabled offline with a clear explanation (no queued writes in v1)
- [ ] A stale-data banner with the last sync time shows whenever data is served from cache and clears after a fresh refresh
- [ ] An admin can disable offline caching org-wide; disabling purges existing caches on next load
- [ ] The cache is evicted on lock, logout, and suite rotation/compromise recovery

## Notes

- No new secret-bearing DB table — the cache is client-side (IndexedDB). Config only: `offline_cache_enabled` (`IAppConfig`), `offline_cache_optin` (`IConfig`).
- Recommended endpoint `GET /api/v1/offline/manifest` returns a consolidated ciphertext-only snapshot for an atomic refresh (server never decrypts, per ADR-003).
- Read-only rationale: per-recipient share fan-out and sync-on-update re-encrypt against each recipient's current certificate and cannot be safely replayed from a stale snapshot; a write queue is a deliberate future change.
- Related specs: encryption-suites (unlock/session, suite rotation), user-settings (per-user opt-out), admin-settings (org-wide toggle). Related ADRs: ADR-001, ADR-003.
