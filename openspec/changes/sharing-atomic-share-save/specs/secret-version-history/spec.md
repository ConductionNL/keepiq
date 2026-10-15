## MODIFIED Requirements

### Requirement: List, view, and restore versions
The system MUST let a user list versions newest-first (metadata only), view a version via client-side decryption identical to a head read, and restore a version — creating a new head version and reaching every copy in the same share-save.

#### Scenario: Restore becomes a new head and syncs
- GIVEN a secret shared with a recipient and a prior version `V`
- WHEN the owner restores `V`
- THEN the current head MUST first be snapshotted, the head MUST become `V`'s values, and the recipient's copy MUST hold `V`'s values in the same transaction

## ADDED Requirements

### Requirement: A restore is one share-save of the version
The web app MUST restore a version by decrypting it in the browser and sending it as one share-save with `restoredFrom` and the version's whole value, based on the head's `updatedAt`. A refused or failed share-save MUST leave the head, every copy and the version history unchanged and MUST show an error. A copy holder without a write grade restores only their own copy, through the version restore route, which records the restore too. That route MUST accept an optional `baseUpdatedAt` and, when it differs from the row's `updatedAt`, change nothing and answer 409 as a stale update does. With or without a base, a save that changes the row between the route's read and its write MUST make the route change nothing and answer 409 too.

#### Scenario: A failed restore changes nothing
- GIVEN a shared secret whose head changed in another browser after this one opened it
- WHEN the owner restores a prior version here
- THEN the server MUST answer 409 `stale`, and the head, the copies and the version list MUST stay as they were
