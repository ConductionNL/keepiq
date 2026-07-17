# Tasks: Offline Read-only Cache

## 1. Backend

- [ ] 1.1 `offline_cache_enabled` admin setting via `OCP\IAppConfig` (default `true`); surface it in `lib/Settings/AdminSettings.php`
- [ ] 1.2 `offline_cache_optin` per-user setting via `OCP\IConfig` (default `true`), read/written through the existing `SettingsService` pattern
- [ ] 1.3 `OfflineController::manifest` (`GET /api/v1/offline/manifest`) — consolidated snapshot (active suite blob + KDF params + all secret ciphertext + folder tree + `syncedAt`); `#[NoAdminRequired]`, owner-scoped; returns 403 when caching is disabled
- [ ] 1.4 Register the route in `appinfo/routes.php` under a commented "Offline cache" section; assert the manifest returns ciphertext only (never decrypts)

## 2. Frontend cache module

- [ ] 2.1 `src/offline/cache.js`: open the per-user IndexedDB store; `writeSnapshot`, `readSnapshot`, `purge`; feature-detect `indexedDB`
- [ ] 2.2 Encrypt plaintext metadata (secret name/url, folder names) under the vault unlock key using `src/crypto/envelope.js`; store ciphertext-only for secret fields
- [ ] 2.3 Write-through on online unlock: fetch the manifest and commit all object stores in one atomic transaction; record `syncedAt`
- [ ] 2.4 Register cache purge as a `session` lock hook (reuse `lockHooks` in `src/store/modules/session.js`) for lock/logout; purge on suite rotation

## 3. Service worker

- [ ] 3.1 Service worker that precaches the app shell + static assets (build-hash-keyed), activate-and-claim on new version; never caches secret material
- [ ] 3.2 Register the service worker in `src/main.js` behind `'serviceWorker' in navigator`; webpack emits it with a stable URL and `publicPath:'auto'`

## 4. Frontend UI

- [ ] 4.1 Offline-aware lock screen: when offline, unlock from the cached suite blob + KDF params with no server round-trip; fall back to online path when connected
- [ ] 4.2 Read-only vault views offline: render list/detail from the decrypted snapshot; disable all create/update/share/delete controls with an explanation
- [ ] 4.3 Stale-data banner showing "Offline — last synced <time>" whenever data is served from cache; clears after a successful online refresh
- [ ] 4.4 Respect the admin toggle + user opt-out: when disabled, write no cache and purge any existing snapshot on load

## 5. Tests

- [ ] 5.1 PHPUnit: `OfflineController::manifest` returns ciphertext-only, is owner-scoped, and 403s when `offline_cache_enabled` is false
- [ ] 5.2 JS unit: write-through then offline read round-trips; metadata is encrypted at rest and only decrypts after unlock; purge clears every object store
- [ ] 5.3 JS unit: offline unlock derives the AES key from cached KDF params and opens the vault with no network mock calls
- [ ] 5.4 e2e (Playwright, offline emulation): populate cache online, go offline, unlock + read a secret, confirm write controls disabled and the stale banner shows; lock and confirm the cache is purged

## Acceptance criteria

- On each online unlock (when enabled), an encrypted snapshot is written atomically; secret names/URLs and folder metadata are encrypted at rest under the vault unlock key.
- Offline unlock re-derives the master key from cached KDF params and opens the vault read-only with no server request; the master password never leaves the browser.
- The service worker serves the app shell offline and never caches decrypted secret material.
- All write actions are disabled offline with a clear explanation (no queued writes in v1).
- A stale-data banner with the last sync time is shown whenever data is served from cache and clears after a fresh refresh.
- An admin can disable offline caching org-wide; disabling purges existing caches on next load.
- The cache is evicted on lock, logout, and suite rotation/compromise recovery.
