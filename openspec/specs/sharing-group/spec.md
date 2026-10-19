# Sharing with a group Specification

**Status**: done

**OpenSpec changes:**
- [sharing-group-share-entry-point](../../changes/archive/2026-09-29-sharing-group-share-entry-point/) _(archived 2026-09-29)_

## Purpose
An owner shares a secret with a Nextcloud group from the secret sidebar. The server keeps the group share and names the members with an encryption suite; the browser encrypts a copy for each of them. Parity row sharing-02.

## Requirements

### Requirement: Share with a group

The system MUST let the owner of a secret share it with a Nextcloud group from the secret detail sidebar, within Nextcloud's share settings (group sharing on; own groups only when that restriction is set). The action MUST call `POST /api/v1/secrets/{secretId}/group-shares` and MUST show how many members received the share and how many were skipped for lack of an active encryption suite. Members who join the group later MUST follow the existing approval path of `GroupShareService::handleNewGroupMember()`.

#### Scenario: An owner shares with a group

@e2e exclude Needs two users with active vault suites and a shared group on the test instance; covered by vitest tests/store/groupShare.spec.js and tests/components/GroupShareList.spec.js, and PHPUnit GroupShareServiceTest and ShareServiceTest::testRegisterDirectSharesLinksAGroupShareOfTheSameSecret.

- **GIVEN** an owner viewing a secret in the sidebar at /secrets and a group Finance with four members of whom three have an encryption suite
- **WHEN** the owner picks Share with group, selects Finance and confirms
- **THEN** the sidebar lists a Finance group share and says three members received it and one was skipped

#### Scenario: A non-owner cannot share with a group

@e2e exclude Needs a second user holding a shared copy; covered by vitest tests/components/SecretDetailSidebar.sharing.spec.js (a recipient gets no group share list).

- **GIVEN** a recipient who holds a shared copy
- **WHEN** the recipient opens the sidebar
- **THEN** the Share with group action is not offered

#### Scenario: The owner revokes a group share

@e2e exclude Needs group members with vault suites; covered by vitest tests/components/GroupShareList.spec.js and PHPUnit GroupShareServiceTest::testRevokeGroupShareCascades.

- **GIVEN** a secret shared with Finance
- **WHEN** the owner revokes the Finance share in the sidebar
- **THEN** the group share is gone and members of Finance lose their copies

### Requirement: A group member saves for everyone it is shared with

A recipient who holds a copy of a secret and is a member of a group the secret is shared with (a group share that has not been revoked or expired) MUST be able to save a change for everyone the secret is shared with, through their copy on share-save's copy-editor path. How their copy reached them (that group share, another group share, a direct share or a team folder) MUST NOT matter. The write context of their copy MUST answer `effectiveGrade: 'write'` with the owner's certificate as `ownerCertificate`. A copy holder who is in no such group MUST NOT get the `write` grade and MUST be refused, even when they left the group and their copy was not removed. A use-only copy MUST NOT give the grade, and neither MUST a use-only group share, even when another grant lifts the use-only flag of the member's copy. Partner shares MUST stay with the owner's next save, as for a team-folder editor, and the member's save MUST be recorded in the audit trail on the source with the member as actor.

The write context MUST answer only a caller who owns the source or is a delegate of it, holds a team-folder grade on it, or holds a copy of it whose access has not ended. Any other caller, including one who knows the id of someone else's copy, MUST get 404, the same answer as for an unknown id.

#### Scenario: A group member saves for everyone

@e2e exclude Needs two users with vault suites in a shared group; covered by PHPUnit ShareSaveServiceTest::testAGroupMemberWritesTheSourceAndTheOtherCopiesAndIsAuditedAsAGroupWrite and GroupMemberWritesForEveryoneTest.

- **GIVEN** Alice shared a login with the group admin, whose members Bob and Carol hold copies
- **WHEN** Bob changes its username and password in his copy
- **THEN** Alice's secret and Carol's copy MUST hold the new values once the save succeeds
- **AND** the audit trail of Alice's secret MUST record the update with Bob as actor

#### Scenario: A group member's write context carries the write grade

@e2e exclude Needs a group member holding a copy; covered by PHPUnit GroupMemberWritesForEveryoneTest::testAGroupMemberGetsTheWriteGradeAndTheOwnerCertificate.

- **GIVEN** Bob holds a copy of Alice's secret through her share with admin
- **WHEN** Bob's browser reads the write context of his copy
- **THEN** it MUST answer `effectiveGrade: 'write'` with Alice's certificate as `ownerCertificate`

#### Scenario: A member who left the group is refused

@e2e exclude Needs a leave cleanup that fails; covered by PHPUnit ShareSaveServiceTest::testAMemberWhoLeftTheGroupIsRefusedThoughTheirCopyRemains and GroupMemberWritesForEveryoneTest::testAMemberWhoLeftTheGroupKeepsTheirCopyButNoWriteGrade.

- **GIVEN** Bob left admin, is in no other group the secret is shared with, and the removal of his copy failed, so the copy is still there
- **WHEN** Bob saves a change through his copy
- **THEN** the write context MUST NOT answer `write` for him
- **AND** a share-save for every copy MUST be refused with nothing changed

#### Scenario: A member whose copy came another way saves for everyone

@e2e exclude Needs a group member whose copy came through a direct share; covered by PHPUnit GroupMemberWritesForEveryoneTest::testAMemberWhoseCopyCameThroughADirectShareGetsTheWriteGrade and testAMemberWhoLeftOneGroupButIsStillInAnotherGetsTheWriteGrade.

- **GIVEN** Alice shared the secret directly with Frank before sharing it with admin, so Frank's copy came through the direct share
- **AND** Frank is a member of admin
- **WHEN** Frank's browser reads the write context of his copy
- **THEN** it MUST answer `effectiveGrade: 'write'`, and a share-save for every copy MUST succeed

#### Scenario: A direct-share recipient changes only their own copy

@e2e exclude Needs a second user holding a direct copy; covered by PHPUnit ShareSaveServiceTest::testADirectRecipientIsRefused and GroupMemberWritesForEveryoneTest::testADirectRecipientKeepsEditingOnlyTheirOwnCopy.

- **GIVEN** Alice also shared the secret directly with Dan, who is in no group it is shared with
- **WHEN** Dan saves a change to his copy
- **THEN** the write context MUST NOT answer `write` for Dan, and a share-save for every copy MUST be refused

#### Scenario: Knowing a copy's id reveals nothing

@e2e exclude Refusal of a crafted request; covered by PHPUnit GroupMemberWritesForEveryoneTest::testKnowingAGroupCopysIdGivesNoAnswer and testACallerWithoutAccessIsAnsweredAsAnUnknownSecret.

- **GIVEN** Eve holds no copy of Alice's secret and no grade on it
- **WHEN** Eve asks for the write context of Bob's copy by its id
- **THEN** the server MUST answer 404, as for an unknown id
