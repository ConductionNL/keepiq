## ADDED Requirements

### Requirement: Sync runs on defined triggers
The extension SHALL run a full vault sync for the active account on unlock, on popup open when the last successful sync is older than `SYNC_INTERVAL_MINUTES`, on a repeating `browser.alarms` alarm with that period while the vault is unlocked, after every local write, and when the user activates "Sync now". `SYNC_INTERVAL_MINUTES` is a single named constant in `src/vault/sync.ts` with the value 15. The alarm is created on unlock and cleared on lock and logout, so no sync runs while locked or logged out.

#### Scenario: Popup opened with a stale snapshot
- **GIVEN** the vault is unlocked and the cached `syncedAt` is more than 15 minutes old
- **WHEN** the popup opens
- **THEN** the popup renders the cached snapshot immediately
- **AND** a sync starts in the background and the list refreshes when it completes

#### Scenario: Popup opened with a fresh snapshot
- **GIVEN** the cached `syncedAt` is less than 15 minutes old
- **WHEN** the popup opens
- **THEN** no sync starts

#### Scenario: Lock stops the schedule
- **GIVEN** the vault is unlocked and the sync alarm exists
- **WHEN** the vault locks
- **THEN** the alarm is cleared and no sync runs until the next unlock

### Requirement: Unchanged vault costs one cheap request
Before fetching a snapshot, a scheduled or popup-open sync SHALL request `GET /api/v1/secrets?sort=updated_at&direction=desc&limit=1` and compare the first row's `updatedAt` and the envelope's `total` with the values recorded at the last successful sync. When both are equal and the folder and type lists were refreshed within `SYNC_INTERVAL_MINUTES`, the sync SHALL end there and refresh `syncedAt`. Syncs triggered by unlock, by a local write or by "Sync now" skip the check and fetch the snapshot directly (ADR-002).

#### Scenario: Nothing changed on the server
- **GIVEN** the cached `newestUpdatedAt` and `total` equal the values the probe returns
- **WHEN** a scheduled sync runs
- **THEN** exactly one request is made and the snapshot is left as it is

#### Scenario: A secret was deleted elsewhere
- **GIVEN** the probe returns the same `updatedAt` but a smaller `total`
- **WHEN** a scheduled sync runs
- **THEN** the full snapshot is fetched and replaced

### Requirement: Manifest first, paginated fallback
The extension SHALL fetch the snapshot from `GET /api/v1/offline/manifest`. When that request returns 403 or 404 the extension SHALL instead fetch `GET /api/v1/secrets` with `limit=100`, incrementing `page` until `page * limit >= total`, together with `GET /api/v1/folders`, `GET /api/v1/secret-types` and `GET /api/v1/suites` (selecting the row with `status === 'active'`), and assemble the same snapshot shape. All routes and shapes are as in ADR-003.

#### Scenario: Manifest available
- **GIVEN** the server allows offline caching
- **WHEN** a sync runs
- **THEN** one request to `GET /api/v1/offline/manifest` supplies suite, secrets, folders, types and `syncedAt`

#### Scenario: Manifest disabled by the admin
- **GIVEN** `GET /api/v1/offline/manifest` returns 403
- **WHEN** a sync runs
- **THEN** the extension pages through `GET /api/v1/secrets` and fetches folders, types and suites
- **AND** the assembled snapshot is stored exactly as a manifest result would be, with `syncedAt` set to the client time of completion

#### Scenario: Fallback page fails mid-way
- **GIVEN** the fallback is on page 3 of 5
- **WHEN** a request fails
- **THEN** the previous snapshot stays in place untouched and the sync reports an error

### Requirement: Snapshot is stored atomically per account
The extension SHALL store the snapshot as one value in `storage.local` keyed by the account id, holding the active suite row, the secret rows (ciphertext plus plaintext metadata as returned by the server), the folders, the types and `syncedAt`. The whole value is replaced in a single `storage.local.set` so a reader never observes a half-written vault. The value is cleared by logout, by account removal and by suite change.

#### Scenario: Sync replaces the snapshot
- **GIVEN** a cached snapshot with 120 secrets
- **WHEN** a sync completes with 121 secrets
- **THEN** the stored value is replaced in one write
- **AND** a popup reading during the sync sees either the old or the new snapshot, never a mix

#### Scenario: Logout clears the snapshot
- **GIVEN** a cached snapshot for account `<uuid>`
- **WHEN** the user logs out of that account
- **THEN** the snapshot key for `<uuid>` is removed from `storage.local`

### Requirement: Suite change purges cache and key
The extension SHALL compare the synced active suite's `id` and `unlockKeyEpoch` with the cached suite. When either differs, the extension SHALL discard the cached snapshot and the private key held in `storage.session` or memory, lock the vault, and require a fresh unlock followed by a full sync before any item is shown.

#### Scenario: Master password rotated in the web app
- **GIVEN** the cached suite has `unlockKeyEpoch` 3
- **WHEN** a sync returns the active suite with `unlockKeyEpoch` 4
- **THEN** the snapshot and the private key are purged
- **AND** the popup shows the unlock view with the message that the vault key changed

#### Scenario: Suite unchanged
- **GIVEN** the cached suite `id` and `unlockKeyEpoch` match the synced ones
- **WHEN** a sync completes
- **THEN** the key stays and the snapshot is replaced normally

### Requirement: Blocked rows are kept, marked and never decrypted
The extension SHALL store rows with `blocked: true` in the snapshot together with their `blockedReason`, SHALL render them as blocked wherever items are listed, and MUST NOT attempt to decrypt, copy or fill from them.

#### Scenario: Revoked suite yields blocked rows
- **GIVEN** the fallback list returns rows with `blocked: true` and no `key`
- **WHEN** the snapshot is stored and rendered
- **THEN** each row appears with a blocked badge and no subtitle
- **AND** `item.decrypt` for that id is refused with a `blocked` error

### Requirement: Offline reads come from the cache
When the server is unreachable or returns a 5xx error, the extension SHALL keep serving the cached snapshot, SHALL show "Last synced <relative time>" derived from `syncedAt`, and SHALL show an offline indicator until a sync succeeds. Vault writes are not queued while offline; write controls are disabled with an explanation, matching Keepiq's read-only offline mode.

#### Scenario: Network down on popup open
- **GIVEN** a cached snapshot synced 2 hours ago and no network
- **WHEN** the popup opens and the stale-triggered sync fails
- **THEN** the list renders from the cache
- **AND** a banner reads "Offline. Last synced 2 hours ago" with a "Sync now" action

#### Scenario: Connectivity returns
- **GIVEN** the offline banner is visible
- **WHEN** the user activates "Sync now" and the sync succeeds
- **THEN** the banner disappears and the list reflects the new snapshot

#### Scenario: No snapshot and no network
- **GIVEN** a freshly unlocked account with no cached snapshot and no network
- **WHEN** the first sync fails
- **THEN** the Vault tab shows an error state "Could not load your vault" with a retry action instead of an empty vault

### Requirement: Sync error handling
The extension SHALL treat a 401 on any sync request as an authentication failure handled by the account layer from ext-accounts-and-unlock (purge and return to logged out), SHALL treat 423 as a temporary condition that keeps the snapshot and retries on the next trigger, and SHALL keep the snapshot on any other error while recording `lastError` in the sync status.

#### Scenario: App password revoked
- **GIVEN** a cached snapshot
- **WHEN** a sync request returns 401
- **THEN** the account layer purges the account's snapshot, key and app password
- **AND** the popup shows the login view

#### Scenario: Vault write-locked during key migration
- **GIVEN** a cached snapshot
- **WHEN** a sync request returns 423
- **THEN** the snapshot stays, the status shows "Server busy, retrying later" and the next trigger retries

### Requirement: One sync at a time
The extension SHALL run at most one sync per account at a time. A trigger that fires during a running sync SHALL reuse the in-flight sync rather than start another. Sync status (`syncing`, `syncedAt`, `offline`, `lastError`) SHALL be exposed to the popup through `vault.snapshot`.

#### Scenario: Sync now during a scheduled sync
- **GIVEN** a scheduled sync is running
- **WHEN** the user activates "Sync now"
- **THEN** no second request sequence starts
- **AND** the popup shows the syncing indicator until the running sync finishes

### Requirement: Snapshot exposure is metadata only
The extension SHALL answer `vault.snapshot` with the plaintext metadata of each row (`id`, `name`, `url`, `typeId`, `folderId`, `blocked`, `blockedReason`, `createdAt`, `updatedAt`, and whether `login` is present), the folders, the types, the sync status and the ids matching the given tab URL. Ciphertext MUST NOT leave the background except through the decrypt path, and content scripts MUST NOT receive the snapshot in any form.

#### Scenario: Popup requests the snapshot
- **GIVEN** an unlocked vault with a cached snapshot
- **WHEN** the popup sends `vault.snapshot`
- **THEN** the reply contains no `key`, `login` or `additionalFields` values

#### Scenario: Locked vault requests the snapshot
- **GIVEN** the vault is locked
- **WHEN** the popup sends `vault.snapshot`
- **THEN** the background replies with a `locked` state and no rows

### Requirement: Decryption is on demand and in the background
The extension SHALL decrypt `login`, `key` and `additionalFields` only when the popup requests them for specific ids through `item.decrypt`, using the private key from ADR-002 in the background. Decrypted values are returned to the popup and held in popup memory only; nothing decrypted is written to `storage.local` or `storage.session`.

#### Scenario: Decrypt for the list subtitle
- **GIVEN** an unlocked vault and 20 rows rendered in the list
- **WHEN** the popup sends `item.decrypt` with those 20 ids and `fields: ['login']`
- **THEN** the background returns a map of id to decrypted login for the rows that have one
- **AND** no storage write occurs

#### Scenario: Decrypt while locked
- **GIVEN** the vault locked between render and request
- **WHEN** the popup sends `item.decrypt`
- **THEN** the background replies with a `locked` error and the popup returns to the unlock view
