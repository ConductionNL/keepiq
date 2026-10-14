## MODIFIED Requirements

### Requirement: Offline changes go into a sealed local queue

When the administrator setting `offline_edits_enabled` is true and the vault is served from the offline cache, the web app MUST let the user create, edit, move and delete secrets, and MUST store each change as a queue entry in IndexedDB. Each entry's values and index fields (`name`, `url`, `typeId`, `folderId`) MUST be sealed to the owner's own certificate; only the entry id, the operation, the secret id, `baseUpdatedAt`, `sourceBaseUpdatedAt`, the queue time and the suite id MAY be stored in plain. The offline manifest MUST carry `sourceUpdatedAt`, the source's version, on every copy row (null for an own secret and when the source is gone), and an edit of a copy made offline MUST record it as the entry's `sourceBaseUpdatedAt` (null when the cached row carries none); changes to one secret that are combined into one entry MUST keep the first one recorded. The vault view MUST show queued changes marked as not synced.

#### Scenario: A field worker rotates a password with no signal

- **GIVEN** a vault owner reading their vault offline with offline edits enabled
- **WHEN** they edit the password of the secret "Pump station router" in the secret list at /secrets and save
- **THEN** the secret MUST show the new value marked "Not synced yet"
- **AND** the IndexedDB queue MUST hold one entry whose stored form contains neither the new password nor the name "Pump station router" in plain

#### Scenario: Offline edits disabled keeps the cache read-only

- **GIVEN** offline edits are disabled by the administrator
- **WHEN** a vault owner reading offline tries to edit a secret
- **THEN** the action MUST be prevented with an explanation that Keepiq is read-only offline

#### Scenario: An offline edit of a copy records the source version it was made from

- **GIVEN** a team-folder member reading offline a copy whose cached row carries the source's `sourceUpdatedAt`
- **WHEN** they change its password and later its login, both before reconnecting
- **THEN** the queue MUST hold one entry whose `sourceBaseUpdatedAt` is that cached `sourceUpdatedAt`, the version the first change was made from

### Requirement: Failed entries are kept, never dropped silently

A replay answered with 403 or 404, a share-save refused with 400, or a share-save stopped by an unreachable partner or by the partner lookup limit MUST move the entry to a failed list where the user can copy their values (decrypted in the browser) or discard the entry, with the reason shown next to it, also after a reload. An entry stopped by an unreachable partner or the lookup limit MUST also offer to try again. A 423 or a network error MUST keep the entry queued. A failed entry MUST NOT stop the replay of the entries after it.

#### Scenario: Write grade removed while offline

- **GIVEN** a team-folder member who edited a folder secret offline, and whose grade was lowered to read meanwhile
- **WHEN** the replay runs
- **THEN** the replay MUST save the change to the member's own copy only, since the queue doesn't know the grade the edit was made under, and MUST NOT write the source or any other copy

#### Scenario: A refused share-save is kept

- **GIVEN** a queued edit of a shared secret
- **WHEN** the replay's share-save is refused with 403 or 400
- **THEN** the entry MUST appear under "Changes that could not sync" with copy and discard actions

#### Scenario: An unreachable partner doesn't hold up the queue

- **GIVEN** two queued edits, the first of a secret shared with a partner instance that doesn't answer
- **WHEN** the replay runs
- **THEN** the first entry MUST move to the failed list with the reason that the partner can't be reached
- **AND** the second entry MUST be replayed

## ADDED Requirements

### Requirement: Replay saves value changes through the share-save

A queued update that changes a value, login or additional fields MUST be replayed as one share-save with the `baseUpdatedAt` stored in the entry (and, on a copy, the source version stored with it, or the source version of replay time when none was stored), encrypted for the copies and partner shares the server names at replay time. A 409 `stale` or `stale_source` MUST become a conflict the user resolves; keeping their change after a `stale_source` MUST replay it on the source version that 409 named, or, after a reload that lost the 409, on the one stored in the entry. An entry left from before this change whose owner row saved but whose recipient sync did not MUST be saved again through share-save when the row still holds the entry's ciphertext, without its `mergedPending` count, and MUST be dropped when the row changed since.

#### Scenario: A shared secret edited offline reaches every copy

- **GIVEN** an owner who changed the password of a shared secret offline
- **WHEN** the owner reconnects and unlocks
- **THEN** the replay MUST save the owner's row and every copy in one share-save, or save nothing

#### Scenario: Keeping mine after the source moved

- **GIVEN** a queued edit of a copy whose replay was refused with 409 `stale_source`
- **WHEN** the user keeps their change
- **THEN** the replay MUST send the source version that 409 named
- **AND** after a reload, when that 409 is no longer known, the replay MUST send the source version stored in the entry

#### Scenario: A pending sync from before the change is finished

- **GIVEN** a queued entry whose owner row saved but whose recipient sync was still pending, and an owner row that hasn't changed since
- **WHEN** the replay runs
- **THEN** it MUST send one share-save for that row's ciphertext without a `mergedPending` count
