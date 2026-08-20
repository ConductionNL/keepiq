---
status: proposed
---

# Folder Permission Grades

## Purpose

Give each team-folder membership a `read` (default) or `write` permission grade. A `read` grade is today's behavior — the member holds a per-recipient RSA copy and can view it. A `write` grade additionally authorizes the member to update a folder secret's value for the whole team: the writer's browser re-encrypts the new value under every recipient's public certificate and pushes it through the existing sync path, and the server accepts the fan-out only because the writer holds a `write` grant. No shared symmetric key, no new envelope crypto, zero server-side plaintext (ADR-003). The folder owner alone manages membership and grades; grade changes and non-owner writes are audited and attributed to the actor.

## ADDED Requirements

### Requirement: Team-folder membership carries a read or write grade

Doriath SHALL record a permission grade of `read` or `write` on every team-folder membership, defaulting to `read`. A `read` grade SHALL grant exactly the access `team-folder-sharing` grants today (hold a per-recipient copy, view only). A `write` grade SHALL additionally authorize the member to update the value of secrets in that folder for all recipients. Only the folder owner SHALL set or change a member's grade.

#### Scenario: New membership defaults to read

- **GIVEN** a folder owner shares a folder with a user without specifying a grade
- **WHEN** the membership is created
- **THEN** the membership's grade MUST be `read`
- **AND** the member MUST receive per-recipient copies they can view but MUST NOT be able to push a value update to the team

#### Scenario: Owner grants a write grade

- **GIVEN** a shared folder with a `read`-grade member M
- **WHEN** the owner sets M's grade to `write`
- **THEN** M's membership grade MUST become `write`
- **AND** no secret ciphertext MUST be re-encrypted or changed as a result of the grade change

#### Scenario: Non-owner cannot change a grade

- **GIVEN** a user who is a member of a shared folder but not its owner
- **WHEN** they attempt to set any member's grade
- **THEN** the request MUST be rejected with a 403/forbidden response

### Requirement: A write-grade member may update a folder secret for all recipients

Doriath SHALL allow a member holding a `write` grade to update a folder secret's value such that the change propagates to every recipient. The updated value MUST be re-encrypted under each recipient's public certificate by the writer's client and submitted through the existing per-recipient sync path; the server MUST NOT decrypt or re-encrypt any value and MUST NOT hold plaintext at any step.

#### Scenario: Write-grade member rotates a shared credential

- **GIVEN** user W holds a `write` grade on a folder containing secret S shared to recipients R
- **WHEN** W submits a value update for S with one re-encrypted blob per recipient in R
- **THEN** the system MUST accept the fan-out and update every recipient's copy of S
- **AND** the server MUST NOT decrypt any submitted blob

#### Scenario: Read-grade member cannot write

- **GIVEN** user V holds a `read` grade on a folder containing secret S
- **WHEN** V attempts to submit a value update that would propagate to recipients
- **THEN** the request MUST be rejected with a 403/forbidden response
- **AND** no recipient's copy of S MUST be changed

#### Scenario: Server enforces the write grant, not the client

- **GIVEN** a caller who is neither owner, delegate, nor a `write`-grade member of any folder containing secret S
- **WHEN** they submit re-encrypted blobs to the sync path for S
- **THEN** the server MUST reject the request regardless of the blobs supplied

### Requirement: Effective grade is the highest grade along the ancestor folder chain

Doriath SHALL compute a member's effective grade for a secret as the highest grade (`write` outranks `read`) granted by any ancestor team folder of the secret's folder. A subfolder membership MAY raise the effective grade; it MUST NOT lower it below any ancestor's grade in v1. A group membership's grade SHALL apply to each user the group expands to.

#### Scenario: Subfolder raises the effective grade

- **GIVEN** a shared folder F granting member M a `read` grade, and a subfolder T of F whose own membership grants M a `write` grade
- **WHEN** the effective grade for a secret in T is resolved for M
- **THEN** the effective grade MUST be `write`

#### Scenario: Ancestor write grade is not narrowed by a subfolder

- **GIVEN** a shared folder F granting member M a `write` grade, and a subfolder T of F with no membership of its own for M
- **WHEN** the effective grade for a secret in T is resolved for M
- **THEN** the effective grade MUST be `write`

### Requirement: Grade changes and non-owner writes are audited

Doriath SHALL dispatch a typed audit event when a member's grade is changed and SHALL attribute every non-owner write of a folder secret to the writer. Audit events MUST carry only identifiers (folder id, member id, secret id, grade values, actor id, timestamps) and MUST NOT carry key material or secret plaintext.

#### Scenario: Grade change is audited

- **GIVEN** an owner changes a member's grade from `read` to `write`
- **WHEN** the change is applied
- **THEN** a typed grade-changed event MUST be dispatched carrying the folder id, member id, old and new grade, actor id, and timestamp — never key material

#### Scenario: Non-owner write is attributed to the writer

- **GIVEN** a `write`-grade member W (not the owner) updates a folder secret S
- **WHEN** the fan-out is accepted
- **THEN** a typed secret-updated audit event MUST be dispatched with the actor set to W — not the folder owner
