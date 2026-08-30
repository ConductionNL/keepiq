## ADDED Requirements

### Requirement: Multi-Select in the Vault List
The system MUST let a user select multiple secrets in the vault list via per-row
checkboxes, a select-all control that selects every secret in the current filtered and
paginated view, and shift-click range selection. The system MUST show the active selection
count with a control to clear it. Selection state MUST be client-only — never persisted to
the server or to browser storage — and MUST be cleared when the vault locks.

#### Scenario: Select individual and range
- **WHEN** the user checks one secret, then shift-clicks another five rows down
- **THEN** all six secrets MUST be selected and the count MUST read 6

#### Scenario: Select-all covers the current view only
- **WHEN** the user activates select-all on a filtered/paginated list showing 20 of 200 secrets
- **THEN** exactly those 20 visible secrets MUST be selected

#### Scenario: Lock clears the selection
- **WHEN** the vault locks while a selection is active
- **THEN** the selection MUST be discarded from memory and no selection data MUST be persisted

### Requirement: Bulk Action Bar
The system MUST present a bulk action bar while a selection is active, offering move to
folder, delete, share to user/group, and add to team folder. The bar MUST disappear when
the selection is cleared.

#### Scenario: Bar reflects selection
- **WHEN** a selection becomes active
- **THEN** the bulk action bar MUST appear offering move, delete, share, and add-to-team-folder
- **AND** clearing the selection MUST hide it

### Requirement: Chunked, Progress-Reported Execution With a Per-Item Report
Every bulk action MUST process the selection in chunks, report progress as it runs, and
produce a final per-item report classifying each secret as succeeded, failed (with a
reason), or skipped (with a reason). The system MUST NOT drop any selected item silently:
every selected secret MUST appear in the final report exactly once. The run MUST be
cancellable, and the report MUST offer a retry that re-runs only the failed items.

#### Scenario: Every item is accounted for
- **WHEN** a bulk action runs over 50 selected secrets and 3 fail
- **THEN** the final report MUST list all 50 secrets, with the 3 marked failed and their reasons
- **AND** no selected secret MUST be missing from the report

#### Scenario: Retry re-runs only failures
- **WHEN** the user clicks "retry failed" after a run with failures
- **THEN** only the previously failed secrets MUST be re-processed
- **AND** because server writes are idempotent, an already-succeeded item MUST NOT be duplicated

#### Scenario: Cancel stops after the current chunk
- **WHEN** the user cancels mid-run
- **THEN** processing MUST stop after the in-flight chunk and the report MUST reflect what completed

### Requirement: Bulk Move to Folder
The system MUST move every selected secret to a chosen folder (or the vault root) via the
existing metadata-only update path, without re-encrypting any value. Received shares MUST
move only within the recipient's own folder tree.

#### Scenario: Bulk move
- **WHEN** the user bulk-moves 10 selected secrets into a folder
- **THEN** each secret's `folderId` MUST be updated with no change to its stored ciphertext
- **AND** the 10 secrets MUST appear under that folder

### Requirement: Bulk Delete Is an Explicit Irreversible Hard Delete
The system MUST delete every selected secret via the existing hard-delete path (there is no
trash), cascading to each secret's derived shares, requests, group shares, and delegations.
Because the deletion is irreversible, the system MUST require an explicit confirmation that
names the exact count before running, and MUST surface the per-item report of what was
deleted versus what failed.

#### Scenario: Confirmation names the count and delete cascades
- **WHEN** the user bulk-deletes 8 selected secrets
- **THEN** the system MUST require a confirmation naming 8 secrets before proceeding
- **AND** each deleted secret MUST cascade to its derived shares, requests, group shares, and delegations
- **AND** the report MUST list each of the 8 as deleted or failed

#### Scenario: Already-deleted item is skipped, not failed
- **WHEN** a selected secret was already removed before its turn in the run
- **THEN** it MUST be reported as skipped, not counted as a failure

### Requirement: Bulk Share to User or Group
The system MUST share every selected secret to each chosen user or group as a per-recipient
RSA-re-encrypted copy produced client-side, reusing the batch-share path. For each
(secret × recipient) the browser MUST decrypt with the owner's key and re-encrypt under the
recipient's certificate before POSTing ciphertext; the server MUST never receive plaintext.
Server writes MUST be idempotent so a resumed or retried run never creates a duplicate share.

#### Scenario: Bulk share fans out per recipient
- **WHEN** the owner bulk-shares 5 selected secrets with 3 users
- **THEN** the browser MUST produce and POST a re-encrypted copy for each of the 15 (secret × recipient) pairs
- **AND** no plaintext value MUST leave the browser

#### Scenario: Resumed share run does not double-share
- **WHEN** a bulk share is interrupted and resumed
- **THEN** the idempotent server upsert MUST ensure no recipient receives a duplicate copy of a secret

#### Scenario: Recipient without an active suite is skipped
- **WHEN** a chosen recipient has no active EncryptionSuite
- **THEN** that recipient MUST be reported as skipped for the affected secrets, and the run MUST continue

### Requirement: Bulk Add to Team Folder
The system MUST add every selected secret's membership to a chosen team folder by reusing
the team-folder-sharing membership fan-out, so selected secrets inherit that folder's
recipients as per-recipient re-encrypted copies. Server writes MUST be idempotent.

#### Scenario: Bulk add to team folder
- **WHEN** the owner bulk-adds 6 selected secrets to a team folder with 4 members
- **THEN** each selected secret MUST be shared to the team folder's members via the existing fan-out
- **AND** re-running the action MUST NOT create duplicate shares

### Requirement: Bulk Actions Respect Ownership and Authorization
The system MUST only perform an action on a secret the user is permitted to act on, reusing
each underlying endpoint's existing per-object authorization guard. A selection that mixes
permitted and non-permitted secrets MUST mark the non-permitted ones skipped with a reason
rather than failing the whole run, and MUST NOT introduce a new unguarded batch surface.

#### Scenario: Not-permitted item is skipped, run continues
- **WHEN** a selection includes a secret the user may not delete
- **THEN** that secret MUST be reported as skipped with an authorization reason
- **AND** the permitted secrets in the selection MUST still be processed
