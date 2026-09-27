---
kind: code
---

# Edit secrets offline and sync the changes when back online

## Why

Keepiq's offline cache lets a field worker read the vault with no network, but every edit needs the server. Someone who rotates a password on site, with no signal, has to remember the new value until they are back online. The offline cache recorded the write queue as a deliberate future change, not as a non-goal.

| Row | Capability | What Keepiq does today |
|---|---|---|
| clients-19 | Edit items while offline and have the changes sync when you are back online. | Offline mode reads only; edits need the server. |

Matrix: keepiq `openspec/parity/capabilities.json`

Not built. The offline cache is read-only by design (`openspec/specs/offline-readonly-cache/spec.md`, requirement "Offline mode is strictly read-only"), with the stale-data banner at `src/App.vue:76` and the write guard at `src/views/SecretList.vue:705`. The same spec gives the reason at `:128`: per-recipient share fan-out and sync-on-update re-encrypt against each recipient's current certificate and cannot be safely replayed from a stale snapshot. This change answers that reason: the queue never stores recipient ciphertext; the fan-out is computed at sync time, against certificates fetched at sync time.

### Demand

- featureRequest: https://community.bitwarden.com/t/offline-editing-management-of-writeable-vault-items/107

### Competitors rated yes

- 1Password: "https://support.1password.com/sync/ : in the apps data is cached locally so you can view and edit it without an internet connection, and changes reach other devices when you next go online."

## What Changes

- When an administrator enables offline edits, a user reading from the offline cache can create, edit, move and delete secrets. Each change goes into a local queue in IndexedDB, sealed to the user's own certificate.
- The offline vault view shows queued changes on top of the cached data, marked "Not synced yet".
- When the browser is online and the vault is unlocked, the web app replays the queue through the normal online paths. For a shared or team-folder secret it fetches the current recipients and certificates at that moment and runs the usual sync-on-update fan-out.
- The server gets an optional `baseUpdatedAt` precondition on `PUT` and `DELETE /api/v1/secrets/{id}`. A changed secret answers `409 Conflict`, and the user chooses to keep their offline version or the server's.
- Sharing, unsharing, link shares, sends, team-folder membership, folders and attachments stay online-only.
- The requirement "Offline mode is strictly read-only" is removed from `offline-readonly-cache` and replaced by the queue's own requirements, including the rule that sharing stays online-only.

## Capabilities

### New Capabilities

- `offline-edit-queue`: the offline change queue, its encryption at rest, replay with a fresh fan-out, conflict handling and administrator control.

### Modified Capabilities

- `offline-readonly-cache`: removes the strictly read-only requirement, which the queue supersedes.

## Impact

- **Backend**: `SecretController::update()` and `destroy()` accept `baseUpdatedAt` and answer 409 with the current row when it differs; a new admin config key `offline_edits_enabled`, exposed with `offline_cache_enabled` in the admin settings and the offline manifest.
- **Frontend**: `src/offline/` gains a queue store; `src/store/modules/offline.js` gains replay; the secret create and edit dialogs, `SecretList.vue` and the stale-data banner learn the queued state; a conflict dialog; `OfflineCacheSection.vue` gets the new switch.
- **Database**: none. No table, no column, no `<version>` bump; the new setting is app config.
- **Security**: queued values are the same ciphertext an online save sends; index fields are sealed to the owner's certificate; recipient ciphertext is only ever produced at sync time.
- **Cross-app**: none.
