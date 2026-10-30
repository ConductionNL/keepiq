## MODIFIED Requirements

### Requirement: A write-grade member may update a folder secret for all recipients
The system MUST allow a `write`-grade member to update a folder secret such that the change reaches the source and every local recipient in one share-save on the copy-editor path. The value MUST be re-encrypted under each holder's public certificate by the writer's client; the server MUST NOT decrypt, re-encrypt, or hold plaintext. Partner shares stay with the owner's next save. A member whose effective grade is `read` holds a view-only copy (user-sharing, "View-Only Copies"): they MUST NOT change its value, neither for every holder nor for their own copy alone.

#### Scenario: Write-grade member rotates a shared credential
- GIVEN user W holds a `write` grade on a folder containing secret S shared to recipients R
- WHEN W submits a share-save for their copy of S with one re-encrypted blob for the source and for each other copy
- THEN the system MUST update the source and every recipient's copy in one transaction without decrypting any blob

#### Scenario: Read-grade member cannot write
- GIVEN user V holds a `read` grade on a folder containing secret S
- WHEN V attempts a value update that would propagate to recipients
- THEN the system MUST reject the request and change no recipient's copy

#### Scenario: Read-grade member cannot change their own copy
- GIVEN user V holds a `read` grade on a folder containing secret S
- WHEN V sends `PUT /api/v1/secrets/{id}` for their copy of S with a new value
- THEN the system MUST answer 428 with `error` `view_only` and leave V's copy unchanged
