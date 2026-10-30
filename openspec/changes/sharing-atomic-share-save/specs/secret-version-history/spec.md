## MODIFIED Requirements

### Requirement: List, view, and restore versions
The system MUST let a user list versions newest-first (metadata only), view a version via client-side decryption identical to a head read, and restore a version — creating a new head version and reaching every copy in the same share-save. The holder of a view-only copy (user-sharing, "View-Only Copies") MAY list and view its versions but MUST NOT restore one: the web app MUST NOT offer Restore on it, and `POST /api/v1/versions/{id}/restore` MUST answer 428 with `error` `view_only` and change nothing.

#### Scenario: Restore becomes a new head and syncs
- GIVEN a secret shared with a recipient and a prior version `V`
- WHEN the owner restores `V`
- THEN the current head MUST first be snapshotted, the head MUST become `V`'s values, and the recipient's copy MUST hold `V`'s values in the same transaction

#### Scenario: A view-only holder cannot restore
- GIVEN a secret shared directly with Bob, whose copy has a prior version `V`
- WHEN Bob sends `POST /api/v1/versions/{id}/restore` for `V`
- THEN the system MUST answer 428 with `error` `view_only`, and Bob's copy and its version list MUST stay as they were

## ADDED Requirements

### Requirement: A restore is one share-save of the version
The web app MUST restore a version by decrypting it in the browser and sending it as one share-save with `restoredFrom` and the version's whole value, based on the head's `updatedAt`. A refused or failed share-save MUST leave the head, every copy and the version history unchanged and MUST show an error. The version restore route, `POST /api/v1/versions/{id}/restore`, changes one row only and records the restore too; the web app doesn't use it. That route MUST accept an optional `baseUpdatedAt` and, when it differs from the row's `updatedAt`, change nothing and answer 409 as a stale update does. With or without a base, a save that changes the row between the route's read and its write MUST make the route change nothing and answer 409 too.

#### Scenario: A failed restore changes nothing
- GIVEN a shared secret whose head changed in another browser after this one opened it
- WHEN the owner restores a prior version here
- THEN the server MUST answer 409 `stale`, and the head, the copies and the version list MUST stay as they were
