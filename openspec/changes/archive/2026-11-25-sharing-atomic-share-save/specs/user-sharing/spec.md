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

### Requirement: View-Only Copies
A received copy is view-only for its holder unless they may write its source: as its owner or a delegate, with an effective `write` or `manage` grade on a team folder that holds it, or as a member of a group the source is shared with through a full group share that has not expired (see `sharing-group`). A team-folder Viewer (`read`), a recipient of a direct share only, and a member of a group whose share is use-only or expired hold view-only copies. The system MUST refuse a change of the value, login or additional fields of a view-only copy and change nothing, whether it comes through `PUT /api/v1/secrets/{id}`, `POST /api/v1/versions/{id}/restore`, `PUT /api/v1/secrets/{id}/share-save` or a fill of a secret request for that copy. A field sent with the value already stored is not a change. The update and the restore MUST answer HTTP 428 with `code` and `error` set to `view_only` and the message "Shared with you to view only. Ask its owner for edit rights to change it." A change of any other field, such as the folder, MUST still save. A suite migration re-seals the copy under its holder's new key with values encrypted in their browser; the server can't tell a re-seal from a new value, so it doesn't check it.

The web app MUST read the copy's write-context to tell a view-only copy apart. On a view-only copy it MUST NOT offer Edit, a version restore or asking for new values, MUST say "Shared with you to view only. Ask its owner for edit rights to change it.", and MUST keep moving it to a folder, starring, archiving, tagging and deleting it. Its edit dialog MUST NOT save, so the web app offers no way to change its name or URL either, as on a read-only copy from another organisation. A share-save of a view-only copy MUST be refused in the browser before anything is sent.

#### Scenario: A direct-share recipient cannot change the value
- GIVEN a secret Alice shared directly with Bob
- WHEN Bob sends `PUT /api/v1/secrets/{id}` for his copy with a new value
- THEN the system MUST answer 428 with `error` `view_only` and leave Bob's copy, Alice's secret and every other copy unchanged

#### Scenario: A view-only holder still files their copy
- GIVEN a secret Alice shared directly with Bob, and a folder of Bob's
- WHEN Bob moves his copy to that folder and archives it
- THEN the system MUST store the new folder and the archive, and the value MUST stay as Alice saved it

#### Scenario: A team-folder Viewer sees no Edit
- GIVEN user V holds a `read` grade on a team folder containing secret S
- WHEN V opens their copy of S
- THEN the detail view MUST NOT show Edit, Restore or "Ask for new values"
- AND it MUST say that the secret is shared with V to view only

### Requirement: Runtime Paths Follow Only Judged Share Rows
A share row links a copy to its source. Every runtime path that follows a share row, from a copy to its source, from a source to its copies, or from a source and a target to the target's copy, MUST read the row through one judged lookup: the row's target must hold the copy it names, and while the quarantine scan is pending the row is judged on the spot first, as Planted Share Rows Are Quarantined describes. A share row MUST be followed only when its target holds the copy it names and the pending scan does not hold it back. A row that the scan quarantines is no copy: the secret it names is its holder's own. A row whose target does not hold its copy is never followed either: a save through it saves the holder's own row and a link share through it is refused, while a check that refuses copies still counts the secret as a copy. For a row the scan cannot judge, a path that can refuse its caller and would otherwise leave a copy on its old value MUST answer `409` with `error: share_scan_pending` and write nothing; every other path treats the row as held back, and a check that refuses copies keeps refusing until the row has been judged, answering the secret's own holder with `409` and `error: share_scan_pending`. In particular:
- a copy holder's save goes through a row to its source (the write context and share-save) only when the row may be followed; through a row the scan cannot judge, the write context names no source and no owner certificate, and share-save answers `409` with `error: share_scan_pending` and writes nothing;
- share-save judges the row of every copy it would write the same way: a copy whose row the pending scan quarantines is neither required nor written, and a copy whose row it cannot judge refuses the whole share-save with `409` and `error: share_scan_pending`, so no copy is left on the old value;
- the write context of a secret that is itself shared, asked by its holder while the row naming it as a copy cannot be judged, answers `409` with `error: share_scan_pending` instead of the `404` of an unknown id;
- a team-folder confirmer or manager has no copy of a folder secret through a held-back row, so that pair is left out and the secret the row names is never decrypted or re-encrypted for other members;
- a team-folder revoke keeps a row the scan quarantines, with its copy, for an administrator to judge, and deletes a trusted row with its copy. For a row the scan cannot judge, an owner or manager removing someone else's membership and an owner unsharing the folder answer `409` with `error: share_scan_pending` before anything changes; a removal checks only the rows of the users who lose access, and deletes the membership and revokes their rows in one transaction, so a revoke refused after that check changes nothing; a member leaving the folder themselves, a group leave, a membership whose end date passed and offboarding cannot be refused, so they remove the row and keep its copy, as a group leave does;
- an offboarding hand-over delegates a team secret to the successor only through the successor's own row that may be followed; any other secret is reported as skipped;
- sharing onward as a delegate (a direct, group or link share, or a restriction change) needs the caller's own copy through a row that may be followed; through a row the scan cannot judge it answers `409` with `error: share_scan_pending`, and register-batch reports that delegate's row as `share_scan_pending`, whether they name their own copy or its source;
- a delegation or an admin handover needs a row that may be followed; through a row the scan cannot judge, the owner delegating the secret, who may know its rows, and an administrator handing it over to themselves through their own row are answered `409` with `error: share_scan_pending`, and the delegate-to-be is never asked; a delegate's share is re-rooted onto a copy's source, and a copy gives a group-write grade on its source, only through such a row;
- a direct share, a group share, a sync, a link share or a value change of a secret that only a row the scan quarantines names treats that secret as an original. While the scan cannot judge the row, the secret's holder is answered `409` with `error: share_scan_pending` on each of these paths (register-batch reports the row as `share_scan_pending`), and anyone else keeps the refusal of a copy, until the row has been judged;
- a compromise of the suite that seals a secret whose row the scan cannot judge warns about that secret itself and still warns the people its holder shared it with, and counts the row as a failure;
- a honey-credential read alert, the target of a compromise warning, the copies a compromise warns about, the `secret.used` audit of a use-only copy (judged only after the caller is found to hold the live use-only copy), the owner's notice that a holder's access ended, and the org-ownership findings name a source only through a row that may be followed, so a held-back row never sends the holder's identity, address or use to the row's source owner.

#### Scenario: A read through a held-back row raises no alert on its source
- GIVEN the quarantine scan has not completed, and a row `{source: S_a, target: B, secret: V}` where V is B's own secret, which the scan quarantines on the spot
- WHEN B reads V
- THEN no read alert MUST name S_a or reach its owner because of V

#### Scenario: A use through a held-back row is not reported to its source
- GIVEN the quarantine scan has not completed, and a row `{source: S_a, target: B, secret: V}` where V is B's own secret, which the scan quarantines on the spot
- WHEN B uses V
- THEN no use audit MUST name S_a or reach its owner because of V

#### Scenario: A secret named by a held-back row is changed as an original
- GIVEN the quarantine scan has not completed, and a row `{source: S_a, target: B, secret: V}` where V is B's own secret, which the scan quarantines on the spot
- WHEN B edits V
- THEN B MUST be able to change V as an original, and the edit MUST NOT reach S_a

#### Scenario: A secret named by a held-back row is shared as an original
- GIVEN the quarantine scan has not completed, and a row `{source: S_a, target: B, secret: V}` where V is B's own secret, which the scan quarantines on the spot
- WHEN B shares V with a user or a group
- THEN the share MUST be made as the share of an original, and MUST NOT be re-rooted onto S_a

#### Scenario: A secret named by a held-back row is link-shared as an original
- GIVEN the quarantine scan has not completed, and a row `{source: S_a, target: B, secret: V}` where V is B's own secret, which the scan quarantines on the spot
- WHEN B link-shares V
- THEN the link share MUST be made as the link share of an original

#### Scenario: A secret named by a held-back row is synced as an original
- GIVEN the quarantine scan has not completed, and a row `{source: S_a, target: B, secret: V}` where V is B's own secret, which the scan quarantines on the spot
- WHEN B syncs V
- THEN the sync MUST go on as the sync of an original, and S_a MUST NOT be written

#### Scenario: Removing a team-folder member leaves a secret named by a held-back row alone
- GIVEN the quarantine scan has not completed, and a row `{source: S_a, target: B, secret: V}` in S_a's team folder where V is B's own secret, which the scan quarantines on the spot
- WHEN B is removed from S_a's team folder
- THEN V MUST NOT be deleted
- AND no ended-access notice MUST name S_a or reach its owner because of V

#### Scenario: Unsharing a team folder leaves a secret named by a held-back row alone
- GIVEN the quarantine scan has not completed, and a row `{source: S_a, target: B, secret: V}` in S_a's team folder where V is B's own secret, which the scan quarantines on the spot
- WHEN S_a's owner unshares the folder
- THEN V MUST NOT be deleted
- AND no ended-access notice MUST name S_a or reach its owner because of V

#### Scenario: A held-back row gives no standing on its source
- GIVEN the quarantine scan has not completed, and a row `{source: S_a, target: B, secret: V}` where V is B's own secret, which the scan quarantines on the spot
- WHEN S_a's owner delegates S_a to B
- THEN B MUST NOT become a delegate of S_a
- AND B MUST NOT gain a grade on S_a through V

#### Scenario: A compromise does not warn a source through a held-back row
- GIVEN the quarantine scan has not completed, and a row `{source: S_a, target: B, secret: V}` where V is B's own secret, which the scan quarantines on the spot
- WHEN the suite of B, or of S_a's owner, is revoked as compromised
- THEN no compromise warning MUST name S_a or reach its owner because of V
- AND V MUST NOT be deleted

#### Scenario: A copy holder's write context does not go through a row the pending scan cannot judge
- GIVEN the quarantine scan has not completed, judging a row `{source: S_a, target: B, secret: V}` fails, V is B's own secret, and S_a is shared with a group B is in
- WHEN B asks for the write context of V
- THEN the write context MUST name no source and no owner certificate

#### Scenario: A copy holder's share-save waits for a row the pending scan cannot judge
- GIVEN the quarantine scan has not completed, judging a row `{source: S_a, target: B, secret: V}` fails, V is B's own secret, and S_a is shared with a group B is in
- WHEN B share-saves V
- THEN the share-save MUST answer `409` with `error: share_scan_pending`, and neither V nor S_a MUST be written

#### Scenario: A row whose target does not hold its copy is never followed
- GIVEN B holds V, and a row `{source: S_a, secret: V}` names V as its copy while the row's target does not hold V
- WHEN B saves V, whether or not the quarantine scan has completed
- THEN the row MUST NOT lead B's save to S_a

#### Scenario: The holder of a secret a foreign row names waits while the scan cannot judge the row
- GIVEN the quarantine scan has not completed, B holds V, and a row `{source: S_a, target: M, secret: V}` names V while M does not hold V, and the scan cannot judge the row
- WHEN B syncs, directly shares, group-shares or link-shares V
- THEN B MUST be answered `409` with `error: share_scan_pending`
- AND M MUST keep the refusal of a copy
- AND B's value change of V MUST still save B's own V

#### Scenario: A share-save skips a copy whose row the pending scan quarantines
- GIVEN the quarantine scan has not completed, and S_a has a row `{source: S_a, target: B, secret: V}` where V is B's own secret
- WHEN the owner of S_a share-saves S_a and the scan quarantines the row on the spot
- THEN V MUST be neither required nor written, and S_a and its other copies MUST be saved

#### Scenario: A share-save waits for a copy whose row the pending scan cannot judge
- GIVEN the quarantine scan has not completed, and judging the row `{source: S_a, target: B, secret: V}` fails
- WHEN the owner of S_a share-saves S_a
- THEN the share-save MUST answer `409` with `error: share_scan_pending` and write nothing

#### Scenario: A sync waits for a copy the pending scan cannot judge
- GIVEN the quarantine scan has not completed, and judging the row of one copy of S fails
- WHEN a member of a group S is shared with syncs S, or S's owner syncs S naming that copy
- THEN the sync MUST answer `409` with `error: share_scan_pending` and write nothing

#### Scenario: A shared secret's write context waits for its own unjudged row
- GIVEN the quarantine scan has not completed, judging a row `{source: S_a, target: B, secret: V}` fails, and V is itself shared by B
- WHEN B asks for the write context of V
- THEN the answer MUST be `409` with `error: share_scan_pending`, naming no source and no owner certificate

#### Scenario: A team-folder confirmer's held-back row hands nothing on
- GIVEN the quarantine scan has not completed, and B holds a write grade in S_a's team folder through a row `{source: S_a, target: B, secret: V}` that the scan quarantines or cannot judge
- WHEN pending confirmations are served to B, or B's manager reconcile is computed
- THEN no copy of S_a MUST be named for B, and the pair MUST be left out

#### Scenario: A team-folder revoke that can be refused waits for a row the pending scan cannot judge
- GIVEN the quarantine scan has not completed, and judging B's share row in a team folder fails
- WHEN the folder's owner removes B's membership or unshares the folder
- THEN the system MUST answer `409` with `error: share_scan_pending` and change nothing

#### Scenario: A team-folder revoke that cannot be refused keeps the copy of a row the pending scan cannot judge
- GIVEN the quarantine scan has not completed, and judging B's share row in a team folder fails
- WHEN B leaves the folder themselves, leaves the group that made them a member, or B's membership ends
- THEN the row MUST be removed and its copy kept

#### Scenario: A team-folder revoke keeps a row the scan quarantines
- GIVEN B's share row in a team folder is quarantined
- WHEN the folder's owner removes B's membership or unshares the folder, B leaves the folder or the group that made them a member, or B's membership ends
- THEN the row MUST be kept with its copy

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

