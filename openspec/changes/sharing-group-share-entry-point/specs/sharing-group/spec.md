## ADDED Requirements

### Requirement: Share with a group

The system MUST let the owner of a secret share it with a Nextcloud group from the secret detail sidebar. The action MUST call `POST /api/v1/secrets/{secretId}/group-shares` and MUST show how many members received the share and how many were skipped for lack of an active encryption suite. Members who join the group later MUST follow the existing approval path of `GroupShareService::handleNewGroupMember()`.

#### Scenario: An owner shares with a group

- **GIVEN** an owner viewing a secret in the sidebar at /secrets and a group Finance with four members of whom three have an encryption suite
- **WHEN** the owner picks Share with group, selects Finance and confirms
- **THEN** the sidebar lists a Finance group share and says three members received it and one was skipped

#### Scenario: A non-owner cannot share with a group

- **GIVEN** a recipient who holds a shared copy
- **WHEN** the recipient opens the sidebar
- **THEN** the Share with group action is not offered

#### Scenario: The owner revokes a group share

- **GIVEN** a secret shared with Finance
- **WHEN** the owner revokes the Finance share in the sidebar
- **THEN** the group share is gone and members of Finance lose their copies
