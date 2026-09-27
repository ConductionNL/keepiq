# Design: offline edit queue

## Context

Code at development `4c214a9d`:

- `src/store/modules/offline.js` owns the offline state. `syncNow()` fetches `GET /api/v1/offline/manifest` (`lib/Controller/OfflineController.php`, built by `lib/Service/OfflineManifestService.php:88`), seals it with `encryptSnapshot()` (`src/offline/snapshot.js:30`, index fields encrypted with the raw unlock key through `encryptMetadata`) and writes it to IndexedDB (`src/offline/cache.js:62`). `unlockOffline()` unlocks from the cached suite envelope. The lock hook keeps the at-rest snapshot and clears only the in-memory view; `evict()` purges it (called on suite rotation from `src/store/modules/encryptionSuite.js:287` and `:1211`).
- `readOnly` is `servedFromCache` (the `readOnly` getter in `offline.js`); `src/views/SecretList.vue:705` disables writes with it, and `src/App.vue:76` shows the stale-data banner.
- Online edits: `PUT /api/v1/secrets/{id}` (`lib/Controller/SecretController.php:294`) takes ciphertext `key`, `login` and `additionalFields` plus plain `name`, `url`, `typeId`, `folderId`, with no precondition. It answers 423 during a suite migration write lock. `DELETE` is `:344`.
- Shared edits: `src/store/modules/share.js` fetches the write context (`GET /api/v1/secrets/{id}/write-context`, `ShareController::writeContext()`), encrypts the value for each recipient certificate it receives, and calls `PUT /api/v1/secrets/{sourceId}/sync` (`syncAsTeamWriter`). The server authorizes the fan-out on the effective grade (`lib/Service/ShareSyncService.php:167`).
- The web app's own-certificate encryption is `rsaEncrypt` from `src/crypto/rsa.js`, chunked at the RSA-4096 OAEP limit; the hybrid construction (AES-256-GCM content key wrapped with RSA-OAEP) exists in `src/crypto/emergencyEnvelope.js:52`.
- Admin setting `offline_cache_enabled` lives in `lib/Service/AdminSettingsService.php:199` and `:353`, with the UI in `src/components/settings/OfflineCacheSection.vue`.

## Goals / Non-Goals

**Goals:**

- Create, edit, move and delete secrets while offline, with the change visible offline at once.
- Replay every change through the same online code paths, so the server rules (grades, write locks, policies) apply at sync time.
- Never send recipient ciphertext computed from a stale snapshot.
- Never overwrite a newer server change without the user choosing to.

**Non-Goals:**

- Offline sharing, unsharing, link shares, sends, team-folder membership, folder create or delete, and attachments. They stay online-only.
- Offline edits in the browser extension or the CLI.
- Automatic merging of two changed versions field by field.
- Background sync while the vault is locked. Replay needs the private key.

## Decisions

### D1: Queue what an online save would send, sealed to the owner

Each queue entry holds: an id, the operation (`create`, `update`, `delete`), the secret id (a client-made UUID for a create), `baseUpdatedAt` (the snapshot's `updatedAt` for that secret), the time queued, the owner's suite id, and a sealed body. The sealed body holds the same `key`, `login` and `additionalFields` ciphertext an online save would send (RSA-OAEP to the owner's own certificate, chunked as today) plus the index fields `name`, `url`, `typeId` and `folderId`, the whole body wrapped in the hybrid envelope from `emergencyEnvelope.js` to the owner's own certificate. The hybrid envelope avoids the RSA chunk limit for long additional fields.

Sealing to the certificate, not to the unlock key, means a routine master password change on another device does not strand the queue: the private key is the same, only its password wrapping changes.

Alternative considered: sealing with the raw unlock key, like the snapshot's metadata. Rejected: the unlock key changes with the master password, and the queue must survive that.

### D2: The fan-out happens at sync time only

The queue never holds recipient ciphertext. At replay the web app decrypts the entry with the in-memory private key and calls the existing online action: create, update or delete of the owner's row. For a secret with recipients it then asks for the write context now, gets the current recipient list and certificates now, encrypts for each, and calls `PUT /api/v1/secrets/{sourceId}/sync`, exactly as an online edit does. A recipient added or removed while the user was offline is therefore handled correctly, which is the reason the offline cache spec gave for staying read-only.

### D3: A server precondition catches concurrent changes

`PUT` and `DELETE /api/v1/secrets/{id}` accept an optional `baseUpdatedAt`. When given and different from the stored `updatedAt`, the server changes nothing and answers `409 Conflict` with the current row (ciphertext and index fields, as a normal read returns). Online clients that do not send it behave as today.

On a 409 the replay stops for that secret and shows a conflict dialog with both versions decrypted in the browser: "Keep my offline change" (replayed again with the new `baseUpdatedAt`; the server's version stays in version history) or "Keep the server version" (the entry is dropped). An offline delete against a changed secret asks the same question.

Alternative considered: a new integer revision column. Rejected for now: `updatedAt` already exists, and an offline edit is based on a snapshot minutes or hours old, so a same-second collision is not a practical risk.

### D4: Coalescing and order

Entries for one secret coalesce locally: repeated updates keep the earliest `baseUpdatedAt` and the latest body; a create followed by updates stays one create; a create followed by a delete removes both. Replay runs oldest first. A create is replayed before any entry that refers to its folder.

### D5: Failure states are explicit

A 403 (for example a write grade removed while offline) or a 404 marks the entry failed, shows it in a "Changes that could not sync" list, and lets the user copy their values (decrypted in the browser) or discard the entry. A 423 (suite migration in progress) or a network error leaves it queued for the next attempt. If the owner row saved but the recipient sync failed, the entry stays as "sync recipients" and retries only that step, which is idempotent.

### D6: When the replay runs

Replay starts when the browser is online and the vault is unlocked online, and again on the browser's `online` event while unlocked. The banner shows "N changes waiting to sync" until the queue is empty. Before logout, and before starting a suite rotation, the app asks the user to sync or discard the pending changes; a rotation cannot start while entries are pending.

If the active suite on the server differs from the entries' suite (a rotation ran on another device), the app asks once for the previous master password to open the entries with the cached old envelope, then replays them under the new certificate, or lets the user discard them.

### D7: Administrator control, off by default

A new app config `offline_edits_enabled` (default `false`) sits next to `offline_cache_enabled` in `OfflineCacheSection.vue` and travels in the offline manifest, so an offline client knows the rule. When false, the offline view stays read-only as today; entries already queued still replay on the next online unlock. When offline caching itself is disabled, the next online unlock replays the queue first and then purges the cache and the queue together.

Alternative considered: on by default. Rejected: existing deployments chose offline caching under a read-only promise, and an upgrade should not change that without an administrator's choice.

## Security and zero-knowledge

The server receives only what an online save sends: the owner-row ciphertext, the plain index fields, and at sync time the recipient ciphertext made with recipient certificates fetched at that moment (ADR-003). The master password never leaves the browser; the queue is opened with the in-memory private key.

At rest in IndexedDB: entries whose body is sealed to the owner's certificate (values and index fields). Plain in each entry: the entry id, the operation, the secret id, `baseUpdatedAt`, the queue time and the suite id. None of these is secret; the snapshot already holds secret ids in plain. A stolen device yields no value or name without the private key, which needs the master password.

Plaintext exists only in the unlocked page: while the user edits, and briefly at replay while the recipient fan-out is computed.

## Risks / Trade-offs

- **A user edits offline, then the owner removes their write grade.** The replay gets 403 and the entry is kept for the user to copy or discard; nothing is lost silently.
- **A long offline period meets many server changes.** Each conflict is a user decision. The dialog shows both versions side by side.
- **Two devices both offline edit the same secret.** The second to sync gets the 409 and decides.
- **Logout with pending changes.** The app warns first; a forced logout keeps the sealed queue for the next login on that browser.

## Seed data

None. Keepiq owns its tables (ADR-001) and has no OpenRegister register. Tests use the existing development secrets and a Playwright context switched offline.

## Migration

None: no table, no column. The new setting is an app config key with a default, so no `<version>` bump is needed. The IndexedDB database gains a queue store in a new schema version of the offline cache database, created on first use.
