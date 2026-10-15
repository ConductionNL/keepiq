## MODIFIED Requirements

### Requirement: Offline changes go into a sealed local queue

When the administrator setting `offline_edits_enabled` is true and the vault is served from the offline cache, the web app MUST let the user create, edit, move and delete secrets, and MUST store each change as a queue entry in IndexedDB. Each entry's values and index fields (`name`, `url`, `typeId`, `folderId`) MUST be sealed to the owner's own certificate; only the entry id, the operation, the secret id, `baseUpdatedAt`, `sourceBaseUpdatedAt`, `conflictUpdatedAt` and `conflictSourceUpdatedAt` (the versions a replay's 409 left the conflict on), the queue time and the suite id MAY be stored in plain. The offline manifest MUST carry `sourceUpdatedAt`, the source's version, on every copy row (null for an own secret and when the source is gone), the offline snapshot MUST keep it on each cached row in plain, as it keeps `updatedAt`, and an edit of a copy made offline MUST record it as the entry's `sourceBaseUpdatedAt` (null when the cached row carries none); changes to one secret that are combined into one entry MUST keep the first one recorded. The vault view MUST show queued changes marked as not synced.

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

- **GIVEN** a team-folder member reading offline, from the snapshot of their last online unlock, a copy whose manifest row carried the source's `sourceUpdatedAt`
- **WHEN** they change its password and later its login, both before reconnecting
- **THEN** the queue MUST hold one entry whose `sourceBaseUpdatedAt` is that `sourceUpdatedAt`, the version the first change was made from

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

### Requirement: Concurrent server changes are never overwritten silently

`PUT /api/v1/secrets/{id}` and `DELETE /api/v1/secrets/{id}` MUST accept an optional `baseUpdatedAt`; when it is present and differs from the stored `updatedAt`, the server MUST change nothing and answer `409 Conflict` with the current row. A `PUT` without `baseUpdatedAt` is not compared with a client base, but a change that lands between the server's read of the secret and its write MUST NOT be overwritten: the server MUST apply the update once more to the secret as it is now, which keeps that change as a version, and when the secret changes again during that second attempt, change nothing and answer `409 Conflict` with the current row. On a 409 the web app MUST stop that entry and let the user keep their offline version or the server version.

#### Scenario: The secret changed online meanwhile

- **GIVEN** a vault owner who edited a secret offline, and the same secret changed from another browser after the snapshot was taken
- **WHEN** the owner reconnects and the replay sends the update with the snapshot's `baseUpdatedAt`
- **THEN** the server MUST answer 409 and leave the secret unchanged
- **AND** the web app MUST show both versions and apply the owner's choice

#### Scenario: Clients without the precondition are unaffected

- **GIVEN** an online client that sends `PUT /api/v1/secrets/{id}` without `baseUpdatedAt`
- **WHEN** the request is processed and no other save changes the secret while it is processed
- **THEN** the server MUST update the secret as it did before this change

#### Scenario: A save that lands during an update without a base is kept

- **GIVEN** an owner's share-save of a shared secret that is still writing its copies
- **WHEN** the browser extension sends `PUT /api/v1/secrets/{id}` for that secret without `baseUpdatedAt`, read before the share-save committed
- **THEN** the server MUST keep the share-save's value as a version and apply the update to the secret as the share-save left it
- **AND** when the secret changes again during that second attempt, the server MUST answer 409 with the current row and leave the secret unchanged

## ADDED Requirements

### Requirement: Replay saves value changes through the share-save

A queued update that changes a value, login or additional fields MUST be replayed as one share-save with the `baseUpdatedAt` stored in the entry (and, on a copy, the source version stored with it, or the source version of replay time when none was stored), encrypted for the copies and partner shares the server names at replay time. A 409 `stale` or `stale_source` MUST become a conflict the user resolves. Keeping their change MUST replay it on the versions of that conflict, the same before and after a reload: the row version a `stale` 409 returned and, on a copy, the source version the 409 named as `sourceUpdatedAt`, which a share-save of a copy carries with both `stale` and `stale_source`, so no read follows the 409. The entry MUST store them with the conflict as `conflictUpdatedAt` and `conflictSourceUpdatedAt`, apart from the `baseUpdatedAt` and `sourceBaseUpdatedAt` the edit was made from, and a version the conflict gave none of MUST stay the one the edit was made from. An entry left from before this change whose owner row saved but whose recipient sync did not MUST be saved again through share-save when the row still holds the entry's ciphertext, without its `mergedPending` count, and MUST be dropped when the row changed since.

#### Scenario: A shared secret edited offline reaches every copy

- **GIVEN** an owner who changed the password of a shared secret offline
- **WHEN** the owner reconnects and unlocks
- **THEN** the replay MUST save the owner's row and every copy in one share-save, or save nothing

#### Scenario: Keeping mine after the source moved

- **GIVEN** a queued edit of a copy whose replay was refused with 409 `stale_source`
- **WHEN** the user keeps their change
- **THEN** the replay MUST send the source version that 409 named, and save when the source has not moved again
- **AND** after a reload, the replay MUST send the same version, stored with the conflict, not the source version the edit was made from

#### Scenario: Keeping mine after the copy moved

- **GIVEN** a queued edit of a copy whose replay was refused with 409 `stale`, because the owner saved the secret meanwhile
- **WHEN** the user keeps their change, before or after a reload
- **THEN** the replay MUST send the copy version and the source version that 409 named, and save when neither has moved again
- **AND** when the copy was written after its source, such as by a plain update of the copy, the 409 names the source version the edit was made from, so keeping the change MAY first be refused with 409 `stale_source` and cost the user one more choice before it saves

#### Scenario: A pending sync from before the change is finished

- **GIVEN** a queued entry whose owner row saved but whose recipient sync was still pending, and an owner row that hasn't changed since
- **WHEN** the replay runs
- **THEN** it MUST send one share-save for that row's ciphertext without a `mergedPending` count
