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
