## MODIFIED Requirements

### Requirement: Sync on Update
When the owner, or a recipient allowed to write the source, updates the value, login or additional fields of a shared secret, the change MUST reach every copy in the same request, through `PUT /api/v1/secrets/{id}/share-save`. The browser MUST encrypt the change for each holder's certificate; the server MUST NOT decrypt or re-encrypt anything. Any other recipient's copy is view-only (see "View-Only Copies").

#### Scenario: Owner updates shared secret
- GIVEN a secret is shared with one or more users
- WHEN the owner updates the secret's value
- THEN the browser MUST encrypt the updated value for each recipient and send it with the owner's own change in one share-save
- AND each recipient's copy MUST hold the new value once the request succeeds

#### Scenario: Recipient updates shared secret
@e2e tests/e2e/workflows/share-save.spec.ts
- GIVEN a secret has been shared with user B, who holds a write grade on it: a `write` or `manage` team-folder grade, or membership of a group the secret is shared with in full (not use-only)
- WHEN user B updates their copy of the secret
- THEN the browser MUST encrypt the updated value for the original owner and all other recipients and send it in one share-save
- AND a recipient without a write grade MUST NOT be able to change the value of their copy (see "View-Only Copies")

#### Scenario: Only the changed fields reach the copies
- GIVEN a shared secret with a login and additional fields
- WHEN the owner changes only the password
- THEN each copy MUST receive only the new password, and its login and additional fields MUST stay as they were

## ADDED Requirements

### Requirement: A shared secret and every copy save in one transaction
`PUT /api/v1/secrets/{id}/share-save` MUST take the caller's sparse patch with a required `baseUpdatedAt`, the ciphertext for every local copy (`copies`), the whole value for every active partner share (`federated`), and the copies and partner shares left out on purpose (`skipped`, `suspend`). `{id}` MUST be the caller's own row: the source on the owner path, or the caller's copy on the copy-editor path, where `sourceBaseUpdatedAt` is also required. The server MUST write the caller's row, every copy and every partner share in one database transaction, or write nothing. It MUST answer 409 `stale` with the current row as `current` when `{id}` changed since `baseUpdatedAt`, and 409 `stale_source` when the source changed on the copy-editor path. On the copy-editor path both answers MUST carry a source version as `sourceUpdatedAt`: `stale_source` the source's current version; `stale` the source's current version only when the copy was written with it (the source's `updatedAt` equals the copy's), and otherwise the edit's own `sourceBaseUpdatedAt`, so a `stale` answer never names a source change the conflict does not show. A refusal of the caller's own row, such as a vault policy, MUST roll back the copies too. A save on the copy-editor path MUST also be recorded in the audit trail on the source, with the caller as actor and the user ids of the holders whose copies were skipped (the owner's when the source itself was skipped).

#### Scenario: A copy editor's save is audited on the source
- GIVEN a secret owned by Alice and shared with Bob, who holds a write grade, and with Carol, who has no vault key
- WHEN Bob saves a new password through his copy and skips Carol's copy
- THEN the audit trail of Alice's secret MUST record the update with Bob as actor and Carol's user id as skipped

#### Scenario: Everything is written or nothing is
- GIVEN a secret shared with two users and one partner
- WHEN the owner sends a share-save and the write of the owner's row is refused by a vault policy
- THEN no copy and no partner share MUST have changed

#### Scenario: A save from an older version is refused
- GIVEN a shared secret that another browser saved after this editor opened it
- WHEN this editor sends a share-save with the older `baseUpdatedAt`
- THEN the server MUST answer 409 `stale` with the current row and change nothing

#### Scenario: A stale copy does not name a source that moved alone
- GIVEN a copy editor's edit of a copy, and a copy and a source that both moved since, the source later than the copy
- WHEN the copy editor sends a share-save with the older `baseUpdatedAt` and `sourceBaseUpdatedAt`
- THEN the server MUST answer 409 `stale` with the copy as `current` and the edit's own `sourceBaseUpdatedAt` as `sourceUpdatedAt`, and change nothing

#### Scenario: A copy holder without a write grade is refused
- GIVEN a recipient whose copy is shared with a read grade
- WHEN that recipient sends a share-save for their copy
- THEN the server MUST refuse it and change nothing

### Requirement: A share-save names every copy or is refused
The server MUST answer 409 `incomplete` with the required lists, and change nothing, unless `copies` plus `skipped` name exactly the copies the caller must write and `federated` plus `suspend` name exactly the active partner shares of the source. On the owner path the required copies MUST be every copy of the source that has not expired; on the copy-editor path they MUST be the source and every other copy. Partner shares MUST be required only on the owner path; a copy editor MUST NOT send any, and the response MUST say whether partner shares exist that the save couldn't reach. A `no_vault_key` skip MUST be refused when the holder has an active vault key. The check MUST be repeated inside the transaction.

#### Scenario: A recipient added during the save is caught
- GIVEN an owner who encrypted a change for one recipient
- WHEN a second recipient was added before the owner's share-save arrived
- THEN the server MUST answer 409 `incomplete` naming the second recipient's copy
- AND the browser MUST encrypt for that copy and send the save again

#### Scenario: A skipped holder who has a vault key is refused
- GIVEN a recipient with an active vault key
- WHEN the share-save lists that recipient's copy as skipped with `no_vault_key`
- THEN the server MUST answer 409 `incomplete` with that copy under `skipRefused`

### Requirement: A large share-save is staged in chunks
A call MUST carry at most 100 entries in `copies` plus `skipped` and at most 100 in `federated` plus `suspend` (400 `too_many_entries` otherwise). A larger save MUST be sent as chunks marked `stage: {final: false}`, each answered with 202 and the stage id, followed by one final call that carries the patch and commits the stage with its own entries in one transaction. The server MUST refuse an empty chunk (400 `empty_chunk`) and more chunks than the save can need (400 `too_many_chunks`). A stage MUST belong to one user, row and base version (409 `stage_mismatch` otherwise), MUST be kept after `incomplete` and dropped after a stale answer, MUST expire after 30 minutes (404 `stage_unknown` afterwards), and MUST be removed when its user is deleted.

#### Scenario: A group of 250 receives one change
- GIVEN a secret shared with a group of 250 members
- WHEN the owner saves a new password
- THEN the browser MUST send the copies in chunks of at most 100 and finish with one final call
- AND either all 250 copies and the owner's row MUST hold the new password, or none

#### Scenario: A stage of empty chunks is refused
- GIVEN a secret shared with 3 users
- WHEN the owner sends a chunk without entries, or a second chunk for a save that needs only one
- THEN the server MUST answer 400 and store nothing

#### Scenario: Another user's stage is refused
- GIVEN a stage created by user A
- WHEN user B sends a chunk or a final call with that stage id
- THEN the server MUST refuse it and change nothing

### Requirement: A restore reaches every copy in the same transaction
A share-save MAY carry `restoredFrom`, the id of a version of `{id}`, on a one-call save or the final call only. The patch and every copy MUST then carry the whole value (`key`, `login` and `additionalFields`), decrypted from that version and encrypted again in the browser. The server MUST refuse a version of another row, or one the caller may not restore, with 400 `version_refused`, and MUST record the restore audit event in the same transaction as the save, after snapshotting the head as on every update. The server can't compare the re-encrypted value with the version, so the version that event records on this path is the one the client says it restored from (`restoredFrom`), not a server-checked value, and the event MUST be marked as coming from the share-save (`via: share-save`).

#### Scenario: A restore that fails leaves everything as it was
- GIVEN a secret shared with a recipient and a prior version `V`
- WHEN the owner restores `V` and the share-save is answered 409 `incomplete`
- THEN neither the owner's row nor the recipient's copy MUST have changed, and no restore MUST be recorded

### Requirement: A share is made from the current version

A share row sent to `POST /api/v1/shares/register-batch` or `POST /api/v1/team-folders/{id}/shares` MAY name, as `sourceUpdatedAt`, the version of the row its ciphertext was encrypted from: the source, or the caller's own copy when a delegate shares from it. The server MUST compare it with that row's version after taking the source's row lock, the lock a share-save takes, inside the transaction that mints the copy, comparing in whole seconds. When it differs, or the row is gone, the server MUST mint nothing for that row and MUST answer it `stale_source` with the current version (`sourceUpdatedAt`, null when gone), while the other rows of the request go on. A `sourceUpdatedAt` that is not a moment MUST be refused as `invalid`. A row without one MUST be accepted as before, until every client sends it. The web app MUST name the version on the rows it encrypts from the source itself or from a delegate's own copy. On a team folder row a manager or member encrypts from their own copy, it MUST name the source's version from that copy's write context, read before the copy is decrypted, so a save in between makes the row stale rather than letting an older value through. It MUST decrypt the row again and send a `stale_source` row once more, at most three times.

#### Scenario: A share made during a save is sent again

@e2e exclude A race between two requests; driven by DirectShareRegistrarTest::testARowFromAnOlderVersionIsStale, TeamFolderFanOutTest::testARowFromAnOlderVersionIsSkippedAsStale and tests/store/share.staleSource.spec.js.

- **GIVEN** Alice opens the share dialog of a login and Carol, who holds a write grade on it, saves a new password through a share-save before Alice confirms
- **WHEN** Alice shares the login with Bob, encrypted from the password she decrypted
- **THEN** the server MUST mint no copy from the old password and MUST answer the row `stale_source` with the new version
- **AND** Alice's browser MUST decrypt the login again and share the new password with Bob

#### Scenario: An older client's share is accepted

@e2e exclude Server-side compatibility; driven by DirectShareRegistrarTest::testARowWithoutAVersionIsNotCheckedAndAMalformedOneIsInvalid.

- **GIVEN** a client that does not send `sourceUpdatedAt`
- **WHEN** it registers a share
- **THEN** the share MUST be created as before

#### Scenario: A manager's fan-out from their own copy is sent again

@e2e exclude A race between two requests; driven by tests/store/teamFolder.staleSource.spec.js.

- **GIVEN** Mo manages a team folder and holds a copy of its login, and the owner saves a new password through a share-save while Mo's browser fans the login out to a new member
- **WHEN** Mo's browser reads the source's version from the write context of Mo's copy, then decrypts the copy
- **THEN** a save that commits between the two reads MUST make the row `stale_source`, and Mo's browser MUST decrypt the copy again and send the new password

