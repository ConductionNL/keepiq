## MODIFIED Requirements

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

A queued update that changes a value, login or additional fields MUST be replayed as one share-save with the `baseUpdatedAt` stored in the entry, encrypted for the copies and partner shares the server names at replay time. A 409 `stale` or `stale_source` MUST become a conflict the user resolves. An entry left from before this change whose owner row saved but whose recipient sync did not MUST be saved again through share-save when the row still holds the entry's ciphertext, without its `mergedPending` count, and MUST be dropped when the row changed since.

#### Scenario: A shared secret edited offline reaches every copy

- **GIVEN** an owner who changed the password of a shared secret offline
- **WHEN** the owner reconnects and unlocks
- **THEN** the replay MUST save the owner's row and every copy in one share-save, or save nothing

#### Scenario: A pending sync from before the change is finished

- **GIVEN** a queued entry whose owner row saved but whose recipient sync was still pending, and an owner row that hasn't changed since
- **WHEN** the replay runs
- **THEN** it MUST send one share-save for that row's ciphertext without a `mergedPending` count
