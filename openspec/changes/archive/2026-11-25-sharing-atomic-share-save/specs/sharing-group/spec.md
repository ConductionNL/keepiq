## MODIFIED Requirements

### Requirement: A group member saves for everyone it is shared with

A recipient who holds a copy of a secret and is a member of a group the secret is shared with (a group share that has not been revoked or expired) MUST be able to save a change for everyone the secret is shared with, through their copy on share-save's copy-editor path. How their copy reached them (that group share, another group share, a direct share or a team folder) MUST NOT matter, and neither MUST whether they joined the group before or after their copy was made: a copy holder who joins later gets the right at once, without the owner's approval, and the owner is told so unless they can save for everyone as a delegate or through a team-folder write grade (see `user-sharing`). Before the save overwrites the owner's secret and the member's own copy, their previous values MUST be kept as versions, as `secret-version-history` requires. The write context of their copy MUST answer `effectiveGrade: 'write'` with the owner's certificate as `ownerCertificate`. A copy holder who is in no such group MUST NOT get the `write` grade and MUST be refused, even when they left the group and their copy was not removed. A use-only copy MUST NOT give the grade, and neither MUST a use-only group share, even when another grant lifts the use-only flag of the member's copy. Partner shares MUST stay with the owner's next save, as for a team-folder editor, and the member's save MUST be recorded in the audit trail on the source with the member as actor.

The write context MUST answer only a caller who owns the source or is a delegate of it, holds a team-folder grade on it, or holds a copy of it whose access has not ended. Any other caller, including one who knows the id of someone else's copy, MUST get 404, the same answer as for an unknown id. The one exception is a caller who asks about a live row of their own whose share rows lead to no source, such as a copy with two share rows: they MUST get `effectiveGrade: 'none'` with no source id, version or certificate, so they can still save their own row. When that row is itself the source of share rows, the caller MUST get 404 instead, so a save of it never skips its copies; when the row naming it as a copy is one the pending share quarantine scan cannot judge (user-sharing), the caller MUST get `409` with `error: share_scan_pending` instead.

#### Scenario: A group member saves for everyone

@e2e tests/e2e/workflows/share-save.spec.ts

- **GIVEN** Alice shared a login with the group admin, whose members Bob and Carol hold copies
- **WHEN** Bob changes its username and password in his copy
- **THEN** Alice's secret and Carol's copy MUST hold the new values once the save succeeds
- **AND** the audit trail of Alice's secret MUST record the update with Bob as actor

#### Scenario: A group member's write context carries the write grade

@e2e tests/e2e/workflows/share-save.spec.ts

- **GIVEN** Bob holds a copy of Alice's secret through her share with admin
- **WHEN** Bob's browser reads the write context of his copy
- **THEN** it MUST answer `effectiveGrade: 'write'` with Alice's certificate as `ownerCertificate`

#### Scenario: A member who left the group is refused

@e2e tests/e2e/workflows/share-save.spec.ts

The e2e test keeps the copy through a direct share, which leaving the group does not remove; a copy kept by a failed removal is covered by PHPUnit ShareSaveServiceTest::testAMemberWhoLeftTheGroupIsRefusedThoughTheirCopyRemains and GroupMemberWritesForEveryoneTest::testAMemberWhoLeftTheGroupKeepsTheirCopyButNoWriteGrade.

- **GIVEN** Bob left admin, is in no other group the secret is shared with, and the removal of his copy failed, so the copy is still there
- **WHEN** Bob saves a change through his copy
- **THEN** the write context MUST NOT answer `write` for him
- **AND** a share-save for every copy MUST be refused with nothing changed

#### Scenario: A member whose copy came another way saves for everyone

@e2e tests/e2e/workflows/share-save.spec.ts

- **GIVEN** Alice shared the secret directly with Frank before sharing it with admin, so Frank's copy came through the direct share
- **AND** Frank is a member of admin
- **WHEN** Frank's browser reads the write context of his copy
- **THEN** it MUST answer `effectiveGrade: 'write'`, and a share-save for every copy MUST succeed

#### Scenario: A copy holder who joins a shared group later saves for everyone

@e2e exclude Needs a group membership change on the test instance after the share; covered by PHPUnit GroupMemberWritesForEveryoneTest::testACopyHolderWhoJoinsASharedGroupLaterSavesForEveryone.

- **GIVEN** Alice shared the secret directly with Dan and with the group admin, and Dan is not a member of admin
- **WHEN** Dan is added to admin
- **THEN** Alice MUST be notified that Dan can now change the secret for everyone, with nothing to approve, and no second share MUST be created for Dan
- **AND** the write context of Dan's copy MUST answer `effectiveGrade: 'write'` at once, before Alice answers

#### Scenario: A direct-share recipient cannot change their copy

@e2e tests/e2e/workflows/share-save.spec.ts

- **GIVEN** Alice also shared the secret directly with Dan, who is in no group it is shared with
- **WHEN** Dan tries to save a change to his copy
- **THEN** the write context MUST NOT answer `write` for Dan, and a share-save for every copy MUST be refused
- **AND** his copy MUST be view-only, so a change of its value through `PUT /api/v1/secrets/{id}` MUST be refused with 428 `view_only` (see `user-sharing`)

#### Scenario: Knowing a copy's id reveals nothing

@e2e tests/e2e/workflows/share-save.spec.ts

- **GIVEN** Eve holds no copy of Alice's secret and no grade on it
- **WHEN** Eve asks for the write context of Bob's copy by its id
- **THEN** the server MUST answer 404, as for an unknown id
