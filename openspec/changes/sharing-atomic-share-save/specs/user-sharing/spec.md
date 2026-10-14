## MODIFIED Requirements

### Requirement: Sync on Update
When the owner, or a recipient allowed to write the source, updates the value, login or additional fields of a shared secret, the change MUST reach every copy in the same request, through `PUT /api/v1/secrets/{id}/share-save`. The browser MUST encrypt the change for each holder's certificate; the server MUST NOT decrypt or re-encrypt anything.

#### Scenario: Owner updates shared secret
- GIVEN a secret is shared with one or more users
- WHEN the owner updates the secret's value
- THEN the browser MUST encrypt the updated value for each recipient and send it with the owner's own change in one share-save
- AND each recipient's copy MUST hold the new value once the request succeeds

#### Scenario: Recipient updates shared secret
- GIVEN a secret has been shared with user B, who holds a write grade on it
- WHEN user B updates their copy of the secret
- THEN the browser MUST encrypt the updated value for the original owner and all other recipients and send it in one share-save

#### Scenario: Only the changed fields reach the copies
- GIVEN a shared secret with a login and additional fields
- WHEN the owner changes only the password
- THEN each copy MUST receive only the new password, and its login and additional fields MUST stay as they were

## ADDED Requirements

### Requirement: A shared secret and every copy save in one transaction
`PUT /api/v1/secrets/{id}/share-save` MUST take the caller's sparse patch with a required `baseUpdatedAt`, the ciphertext for every local copy (`copies`), the whole value for every active partner share (`federated`), and the copies and partner shares left out on purpose (`skipped`, `suspend`). `{id}` MUST be the caller's own row: the source on the owner path, or the caller's copy on the copy-editor path, where `sourceBaseUpdatedAt` is also required. The server MUST write the caller's row, every copy and every partner share in one database transaction, or write nothing. It MUST answer 409 `stale` with the current row as `current` when `{id}` changed since `baseUpdatedAt`, and 409 `stale_source` when the source changed on the copy-editor path. On the copy-editor path both answers MUST carry the source's version as `sourceUpdatedAt`. A refusal of the caller's own row, such as a vault policy, MUST roll back the copies too. A save on the copy-editor path MUST also be recorded in the audit trail on the source, with the caller as actor and the user ids of the holders whose copies were skipped (the owner's when the source itself was skipped).

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
