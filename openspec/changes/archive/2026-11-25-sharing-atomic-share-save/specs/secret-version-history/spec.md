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

#### Scenario: A restore of the TOTP seed and passkeys
@e2e exclude Asserted on the persisted head and the share-save body; covered by PHPUnit (SecretVersionServiceTest, ShareSaveRequestTest, ShareSaveServiceTest).
- GIVEN a version records an empty `totp` or `passkeys` as `''` and a version made before the fields existed holds null
- WHEN the version is restored
- THEN a null field MUST keep the head's value, a `''` field MUST clear it, and a value MUST set it, on the head and on every copy
- AND a field whose value is the head's own ciphertext counts as kept, so a client that does not send it is not refused; a different value the patch leaves out MUST be refused with 400 `invalid_body`
- AND the restore MUST clear `totp_updated_at` or `passkeys_updated_at` of each of the two it changes, and on a flagged secret `key_updated_at` of a key it changes, so written-back old ciphertext never counts as a rotation and reads as exposed
- AND on a secret that is not flagged a restore that changes the key MUST set `key_updated_at` to the time of the restore, as an edit does, so the password's age for expiry, health and the compliance report is never taken from `created_at` by a restore

## ADDED Requirements

### Requirement: A restore is one share-save of the version
The web app MUST restore a version by decrypting it in the browser and sending it as one share-save with `restoredFrom` and the version's whole value, based on the head's `updatedAt`. A refused or failed share-save MUST leave the head, every copy and the version history unchanged and MUST show an error. The version restore route, `POST /api/v1/versions/{id}/restore`, changes one row only and records the restore too; the web app doesn't use it. That route MUST accept an optional `baseUpdatedAt` and, when it differs from the row's `updatedAt`, change nothing and answer 409 as a stale update does. With or without a base, a save that changes the row between the route's read and its write MUST make the route change nothing and answer 409 too.

#### Scenario: A failed restore changes nothing
- GIVEN a shared secret whose head changed in another browser after this one opened it
- WHEN the owner restores a prior version here
- THEN the server MUST answer 409 `stale`, and the head, the copies and the version list MUST stay as they were
