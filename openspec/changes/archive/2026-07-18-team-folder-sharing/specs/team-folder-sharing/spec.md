---
status: proposed
---

# Team Folder Sharing

## Purpose

Let a folder be shared as a team vault: an owner shares a whole folder with Nextcloud users and groups, and every secret in the folder (and its subfolders) inherits access via membership-driven fan-out of the existing per-recipient RSA share operation — never a shared symmetric key. Membership changes propagate (join → owner-approved add; leave → auto-revoke), and an admin offboarding action revokes a leaver's inherited access and transfers their owned team secrets via existing delegation mechanics. Zero server-side plaintext exposure (ADR-003).

## ADDED Requirements

### Requirement: Share a folder as a team folder

Doriath SHALL allow a folder's owner — and only the owner — to share an owned folder with Nextcloud users and groups by attaching a team-folder membership set to it. Sharing a folder SHALL NOT introduce any shared symmetric key; access is granted by fanning out per-recipient RSA-encrypted copies of the contained secrets.

#### Scenario: Owner shares a folder

- **GIVEN** a user owns a folder containing N secrets
- **WHEN** the owner shares the folder with users and groups whose eligible members total R recipients
- **THEN** the system MUST create a team-folder record for that folder and, for each of the N secrets, create one per-recipient RSA-encrypted copy per eligible recipient (up to N×R shares), each linked to the team folder via `team_folder_id`
- **AND** no shared symmetric folder key MUST be created

#### Scenario: Non-owner cannot share or manage a folder

- **GIVEN** a user who is a recipient of a shared folder but not its owner
- **WHEN** they attempt to share the folder further or change its membership
- **THEN** the request MUST be rejected with a 403/forbidden response

#### Scenario: Recipients without an EncryptionSuite are skipped

- **GIVEN** a group member who has never opened Doriath and has no active EncryptionSuite
- **WHEN** the owner shares a folder with that member's group
- **THEN** that member MUST be skipped silently with no error, and no share MUST be created for them

### Requirement: Secrets inherit team-folder access on add and lose it on removal

Doriath SHALL automatically share a secret to a team folder's current recipient set when the secret is placed in the folder, and SHALL automatically revoke the folder-derived copies when the secret is removed from the folder or deleted. Fan-out server writes SHALL be idempotent so a retried operation never creates duplicate shares.

#### Scenario: Adding a secret to a shared folder fans out shares

- **GIVEN** a shared folder with R eligible recipients
- **WHEN** the owner creates or moves a secret into that folder
- **THEN** the system MUST create one per-recipient RSA-encrypted copy per recipient, each linked via `team_folder_id`

#### Scenario: Removing a secret revokes the derived copies only

- **GIVEN** a secret in a shared folder that also has an independent direct share to user D
- **WHEN** the owner moves the secret out of the folder
- **THEN** all `team_folder_id`-derived copies MUST be revoked and their encrypted copies deleted
- **AND** the independent direct share to D (with `team_folder_id` null) MUST remain intact

#### Scenario: Retried fan-out does not double-share

- **GIVEN** a fan-out that partially completed and is retried
- **WHEN** the client re-POSTs copies for the same (secret, recipient, team folder) tuples
- **THEN** the server MUST upsert on that tuple so no duplicate share is created

### Requirement: Nested subfolders inherit the nearest ancestor team folder

Doriath SHALL share a secret located in a subfolder of a shared folder to the union of every ancestor team folder's recipient set. A subfolder MAY additively widen the inherited recipient set; it MUST NOT narrow it below any ancestor's set in v1.

#### Scenario: Secret in a subfolder inherits the ancestor's members

- **GIVEN** a shared folder F with members M, and a subfolder S of F carrying no team folder of its own
- **WHEN** a secret is added to S
- **THEN** the secret MUST be shared to all members M of F

#### Scenario: Subfolder additively widens membership

- **GIVEN** a shared folder F with members M and a subfolder S carrying its own team folder with additional members M2
- **WHEN** a secret is added to S
- **THEN** the secret MUST be shared to the union of M and M2

### Requirement: Team-folder membership propagates with group membership

Doriath SHALL propagate group membership changes to team-folder-derived shares using the same owner-approval-on-join and auto-revoke-on-leave pattern as ordinary group shares. A user joining a member group SHALL trigger an owner-approval notification before the fan-out share is created; a user leaving SHALL have their team-folder-derived shares auto-revoked.

#### Scenario: New group member requires owner approval before inheriting

- **GIVEN** a shared folder whose members include group G
- **WHEN** user X joins group G
- **THEN** the folder owner MUST receive an approval notification per affected secret
- **AND** the fan-out share for X MUST be created only on the owner's approval, linked via `team_folder_id`

#### Scenario: Departing group member auto-loses inherited access

- **GIVEN** user Y holds team-folder-derived shares because Y is in a member group G
- **WHEN** Y leaves group G
- **THEN** all of Y's `team_folder_id`-derived shares for that folder MUST be automatically revoked and their encrypted copies deleted
- **AND** any independent direct shares Y holds MUST remain intact

#### Scenario: Owner removes a member directly

- **GIVEN** a shared folder with a direct user member Z
- **WHEN** the owner removes Z from the folder membership
- **THEN** all of Z's `team_folder_id`-derived shares for that folder MUST be revoked

### Requirement: Admin offboarding revokes inherited access and transfers owned team secrets

Doriath SHALL provide a single admin action that, given a leaving user and a named successor, revokes every team-folder-derived share the leaving user holds and transfers ownership of the team secrets the leaving user owned to the successor using the existing permanent-delegation mechanics — introducing no bespoke transfer path.

#### Scenario: Offboarding revokes inherited access

- **GIVEN** a leaving user U who holds team-folder-derived shares across one or more folders
- **WHEN** an administrator runs the offboarding action for U with successor S
- **THEN** every `team_folder_id`-derived share held by U MUST be revoked and its encrypted copy deleted

#### Scenario: Offboarding transfers owned team secrets via delegation

- **GIVEN** the leaving user U owns team secrets and the successor S already holds (or is made to hold) a share of each
- **WHEN** the offboarding action runs
- **THEN** for each such secret a permanent delegation to S MUST be created using the existing delegation mechanism, granting S co-owner rights

#### Scenario: Non-admin cannot offboard

- **GIVEN** a non-administrator user
- **WHEN** they attempt to invoke the offboarding action
- **THEN** the request MUST be rejected with a 403/forbidden response

### Requirement: Team-folder operations are auditable without exposing secret material

Doriath SHALL dispatch typed events for team-folder share, unshare, member add, member remove, and offboarding, carrying only identifiers (folder id, owner id, member ids, secret ids, timestamps) and never key material or secret plaintext.

#### Scenario: Fan-out dispatches an audit event with no key material

- **GIVEN** an owner shares a folder
- **WHEN** the fan-out completes
- **THEN** a typed team-folder-shared event MUST be dispatched carrying only folder id, owner id, member ids, and timestamps — never key material or secret content
