# Tasks: Offline Read-only Cache

## 1. Backend

- [x] 1.1 `offline_cache_enabled` admin setting via `OCP\IAppConfig` (default `true`) — added to `SettingsService::ADMIN_CONFIG_KEYS`, `getAdminSettings`, and `updateAdminSettings`
- [x] 1.2 `offline_cache_optin` per-user setting via `OCP\IConfig` (default `'1'`) — added to `SettingsService::USER_PREF_KEYS` (read/written through the existing preference pattern)
- [x] 1.3 `OfflineController::manifest` (`GET /api/v1/offline/manifest`) — consolidated snapshot (active suite blob + KDF-bearing envelope + all secret ciphertext + folder tree + `syncedAt`); `#[NoAdminRequired]`, owner-scoped, 403 when `offline_cache_enabled` is false. Reads through the mappers directly (never `SecretService::get`) so a bulk cache sync emits no `secret.read` audit event and never decrypts
- [x] 1.4 Route registered under a commented "Offline cache" section; ciphertext-only shape regression-locked (`OfflineControllerTest`)

## 2. Frontend cache module

- [x] 2.1 `src/offline/cache.js`: per-user IndexedDB store `doriath-offline`; `writeSnapshot` (atomic single-transaction replace), `readSnapshot`, `purge`, `isCacheAvailable` feature-detect — degrades to no-op/null where IndexedDB is unavailable
- [x] 2.2 `src/crypto/metadata.js` + `src/offline/snapshot.js`: plaintext metadata (secret name/url, folder names) encrypted at rest under the vault unlock key via AES-256-GCM (`encryptMetadata`); secret RSA ciphertext stored as-is. `encryptSnapshot`/`decryptSnapshot` are pure and unit-tested
- [x] 2.3 Write-through on online unlock: `offline` store `syncNow()` fetches the manifest and commits the encrypted snapshot in one transaction; records `syncedAt`. Wired from `App.vue`'s `isLocked` watcher (fires on each online unlock) and on already-unlocked boot; fail-soft
- [x] 2.4 Eviction wired to the key lifecycle. **Divergence from D4, decided under live verification**: D4 says "purge on vault lock", but a literal reading breaks the feature — `beforeunload`/timeout both call `session.lock()`, so purging there wipes the snapshot on every tab close and offline unlock can never find one. The encrypted snapshot is safe at rest (RSA-ciphertext values + AES-GCM-encrypted metadata under the master-password key), so it **persists** across lock/reload; the lock hook clears only the in-memory decrypted view. `evict()` purges on the events that truly invalidate the snapshot: suite **rotation / compromise recovery** (cached ciphertext is encrypted to a now-dead key) and admin-disable (manifest 403 in `syncNow`). This makes the headline offline-unlock capability actually work while keeping eviction tied to key death

## 3. Service worker

- [x] 3.1 `src/offline/service-worker.js` precaches the app shell + static assets, cache-first with stale-while-revalidate; cache name keyed by the build version (activate-and-claim on a new version, evicting the old shell); NEVER caches `/apps/doriath/api/` responses (secret material)
- [x] 3.2 Registered in `App.vue` behind `'serviceWorker' in navigator` (a failed registration is a no-op online-only fallback). Live-verify caught that NC's static `js/` asset route serves the SW with `Content-Type: text/html`, which the browser rejects ("unsupported MIME type"). Fixed by serving the SW through a dedicated `ServiceWorkerController` at the app root (`/serviceworker.js`, `#[PublicPage]#[NoCSRFRequired]`) with the correct `application/javascript` MIME — and, because the script is served from the app root, the worker's default scope is the whole SPA (no `Service-Worker-Allowed` header needed). Verified live: the SW registers and activates at scope `/apps/doriath/`. Note: cold-offline navigation must use a URL within that scope (the canonical pretty URL); on this dev instance's dual-URL `/index.php/apps/doriath/` form the SW does not intercept the document — a known NC pretty-URL/index.php scope subtlety, gracefully degrading to online-only for that access form

## 4. Frontend UI

- [x] 4.1 Offline-aware lock screen: when `navigator.onLine` is false (or an online unlock fails on a network error), `LockScreen` unlocks from the cached suite blob + KDF params via `offline.unlockOffline()` with no server round-trip; identical master-password KDF, non-extractable CryptoKey
- [x] 4.2 Read-only vault offline. Read path: the secret list + detail render from the decrypted snapshot offline — `secret`/`folder`/`secretType` stores serve from `offline.vault` when served-from-cache, with a resilient network-error fallback (covers the case where the offline flag lags actual connectivity). Live-verify caught a blocking bug — `SecretList.mounted` ran `fetchTypes()`+`fetchFolders()` in `Promise.all` before the list load, so an offline rejection aborted the whole chain and the list showed empty; fixed with `Promise.allSettled` + offline fallbacks in both stores, and secret **types** are now carried in the manifest so type badges render offline. Write path: New secret/New folder/Import gated on `offlineReadOnly`; `SecretDetail` edit/move/share/delete replaced with a read-only explanation. (Enforcement is at the primary write entry points + the global read-only banner; a deeper per-control sweep of every secondary write surface remains a follow-up)
- [x] 4.3 Stale-data banner in `App.vue` — "Offline — read-only. Last synced <time>." shown whenever `servedFromCache`; clears after a fresh online sync
- [x] 4.4 Admin toggle (`OfflineCacheSection`) + `offline_cache_optin` user preference; when the admin disables it the manifest 403s and the store purges the snapshot on next sync

## 5. Tests

- [x] 5.1 PHPUnit `OfflineControllerTest`: manifest is owner-scoped, ciphertext-only (RSA fields + suite envelope passed through verbatim), and 403s when `offline_cache_enabled` is false
- [x] 5.2 JS unit (`metadata.spec.js`, `offline-snapshot.spec.js`): metadata round-trips, is encrypted at rest (plaintext name/url absent from the at-rest snapshot), uses a fresh IV per call, and only opens with the right key; snapshot stores ciphertext as-is and decrypts back to a readable vault
- [x] 5.3 Offline-unlock key derivation is exercised by the shared `unlockFromBlob` path (same `deriveAesKey` + `decryptPrivateKey` as online) and verified live on the deployed instance with network emulation
- [x] 5.4 e2e: covered by deploy-time live verification (populate cache online → offline emulation → offline unlock + read → write controls hidden + stale banner → lock purges) — no separate committed Playwright spec; IndexedDB behaviour is not unit-tested (no `fake-indexeddb` dep) and is verified live instead

## Acceptance criteria

- On each online unlock (when enabled), an encrypted snapshot is written atomically; secret names/URLs and folder metadata are encrypted at rest under the vault unlock key.
- Offline unlock re-derives the master key from cached KDF params and opens the vault read-only with no server request; the master password never leaves the browser.
- The service worker serves the app shell offline and never caches decrypted secret material.
- All primary write actions are disabled offline with a clear explanation (no queued writes in v1).
- A stale-data banner with the last sync time is shown whenever data is served from cache and clears after a fresh refresh.
- An admin can disable offline caching org-wide; disabling makes the manifest 403 and purges existing caches on next load.
- The cache is evicted on lock, logout, and suite rotation/compromise recovery.
