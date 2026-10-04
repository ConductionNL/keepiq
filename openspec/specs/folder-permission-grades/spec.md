# Folder Permission Grades Specification

**Status**: done

**OpenSpec changes:**
- `folder-permission-grades` (2026-07-17) — Read/write permission grades on team-folder membership: a `write` grade authorizes a non-owner to update a folder secret for all recipients via the existing client-side re-encrypt-for-all sync path, server-authorized on the grant; owner keeps membership management; grade changes and non-owner writes audited. Depends on `team-folder-sharing`.

## Purpose

@e2e exclude The permission-grade authorization contract is enforced server-side on the sync path and exercised through PHPUnit; the one browser-observable flow (write member edits, second recipient sees the new value) is covered by the change's Playwright e2e task, not by this evergreen spec.

`team-folder-sharing` makes every folder member read-only: a member holds a per-recipient RSA copy and can view it, but only the folder owner (or a delegate) can push a new value to the whole team. Real teams need shared **editable** credentials — rotating a shared service password or CI token — without funnelling every rotation through the owner.

This feature adds a permission grade (`read` default, or `write`) to each team-folder membership. A `write` grade authorizes the member to update a folder secret's value for all recipients. Because Keepiq is zero-knowledge (ADR-003), a `write` grade is **not** a shared key: the writer's browser re-encrypts the new value under every recipient's public certificate and pushes it through the existing sync path, and the server accepts the fan-out only because the writer holds a `write` grant. Envelope crypto is unchanged; the server holds zero plaintext. The owner alone manages membership and grades.

## Requirements

### Requirement: Team-folder membership carries a read, write or manage grade

The system MUST record a `read` (default), `write` or `manage` grade on every team-folder membership, shown in the interface as Viewer, Editor and Manager. A `read` grade MUST grant exactly the access team-folder-sharing grants today. A `write` grade MUST additionally authorize value updates that propagate to all recipients. A `manage` grade MUST include everything `write` allows and MUST authorize membership management within the limits of the requirement "Only the owner governs managers and the folder itself". Only the folder owner, or a member whose effective grade on the folder is `manage`, MUST be able to set or change a grade. Any other member's request MUST be refused as forbidden with `error: manager_only` and the reason, with HTTP status 428 for the same reason as `owner_only`: Nextcloud's OCS layer turns a 403 of an OCS controller into an HTTP 200 envelope.

#### Scenario: New membership defaults to read

- **GIVEN** an owner shares a folder without specifying a grade
- **WHEN** the membership is created
- **THEN** the system MUST set the grade to `read` and the member MUST NOT be able to push a value update to the team

#### Scenario: Non-owner cannot change a grade

- **GIVEN** a member of a shared folder who is not its owner and whose effective grade is `read` or `write`
- **WHEN** they attempt to change any member's grade
- **THEN** the system MUST reject the request with a forbidden refusal (HTTP 428, `error: manager_only`)
- **AND** the member's grade MUST stay as it was

#### Scenario: A manager promotes a viewer to editor

- **GIVEN** a member Olga with grade `manage` on a team folder, and a member Bob with grade `read`
- **WHEN** Olga calls `PATCH /api/v1/team-folders/{id}/members/{memberId}` for Bob with grade `write`
- **THEN** Bob's grade MUST become `write`

### Requirement: A write-grade member may update a folder secret for all recipients
The system MUST allow a `write`-grade member to update a folder secret such that the change propagates to every recipient. The value MUST be re-encrypted under each recipient's public certificate by the writer's client; the server MUST NOT decrypt, re-encrypt, or hold plaintext.

#### Scenario: Write-grade member rotates a shared credential
- GIVEN user W holds a `write` grade on a folder containing secret S shared to recipients R
- WHEN W submits an update for S with one re-encrypted blob per recipient
- THEN the system MUST accept the fan-out and update every recipient's copy without decrypting any blob

#### Scenario: Read-grade member cannot write
- GIVEN user V holds a `read` grade on a folder containing secret S
- WHEN V attempts a value update that would propagate to recipients
- THEN the system MUST reject the request and change no recipient's copy

### Requirement: Effective grade is the highest grade along the ancestor folder chain

The system MUST compute a member's effective grade for a secret or a team folder as the highest grade granted by any ancestor team folder, with `manage` above `write` above `read`. A subfolder MAY raise the grade; it MUST NOT lower it below any ancestor's grade.

#### Scenario: Subfolder raises the effective grade

- **GIVEN** folder F grants member M `read`, and subfolder T of F grants M `write`
- **WHEN** the effective grade for a secret in T is resolved for M
- **THEN** it MUST be `write`

#### Scenario: An ancestor manager outranks a subfolder editor

- **GIVEN** folder F grants member M `manage`, and subfolder T of F grants M `write`
- **WHEN** the effective grade for T is resolved for M
- **THEN** it MUST be `manage`

### Requirement: Grade changes and non-owner writes are audited
The system MUST dispatch a typed event when a grade changes and MUST attribute every non-owner write to the writer. Events MUST carry only identifiers — never key material or plaintext.

#### Scenario: Non-owner write attributed to the writer
- GIVEN a `write`-grade member W (not the owner) updates a folder secret
- WHEN the fan-out is accepted
- THEN a secret-updated audit event MUST be dispatched with the actor set to W

### Requirement: Managers keep the membership current

A member with an effective `manage` grade MUST be able to add users and groups as Viewers or Editors, remove Viewers and Editors, change a member between `read` and `write`, approve a group join, and run reconcile, on the team folder and its subtree. When a manager adds a member, the manager's browser MUST encrypt each folder secret for the new users from the manager's own recipient copies and post only ciphertext; the system MUST accept those rows under the existing subtree and not-the-owner checks. Secrets the manager holds no copy of MUST be skipped and reported, and MUST appear as missing in the owner's reconcile.

#### Scenario: A manager adds a colleague while the owner is away

- **GIVEN** a team folder owned by Anna, with Olga as Manager and three secrets that Olga holds copies of
- **WHEN** Olga adds Bob as a Viewer in the team folder dialog
- **THEN** Bob MUST receive a recipient copy of all three secrets, encrypted in Olga's browser
- **AND** the audit trail MUST record Olga as the actor of the member addition

#### Scenario: A missing copy is reported, not faked

- **GIVEN** a manager who holds no copy of one folder secret
- **WHEN** they add a new member
- **THEN** that secret MUST be listed as skipped to the manager
- **AND** the owner's reconcile MUST list the pair as missing

### Requirement: Only the owner governs managers and the folder itself

The system MUST refuse, for a member who is not the owner, setting or clearing the `manage` grade, removing or changing a member whose grade is `manage`, changing or removing the owner, stopping sharing of the folder, and deleting the team folder. A manager MUST be able to remove their own membership. A manager's request to set the `manage` grade, or to change or remove a manager, MUST be refused as forbidden with `error: owner_only` and the reason. Its HTTP status MUST be 428, because Nextcloud's OCS layer turns a 403 of an OCS controller into an HTTP 200 envelope that the browser would read as a success.

#### Scenario: A manager cannot create another manager

- **GIVEN** Olga with grade `manage` and Bob with grade `read`
- **WHEN** Olga tries to set Bob's grade to `manage`
- **THEN** the system MUST reject the request with a forbidden refusal (HTTP 428, `error: owner_only`) and Bob's grade MUST stay `read`
- **AND** Olga's browser MUST show the refusal

#### Scenario: A manager cannot unshare the folder

- **GIVEN** Olga with grade `manage`
- **WHEN** she calls `DELETE /api/v1/team-folders/{id}`
- **THEN** the system MUST reject the request and the team folder MUST remain

#### Scenario: A manager leaves

- **GIVEN** Olga with grade `manage`
- **WHEN** she removes her own membership
- **THEN** the membership MUST be removed and her derived copies revoked as for any member who leaves

## User Stories

- As a team member trusted with a shared service account, I want to rotate its password so that my whole team gets the new value without waiting for the folder owner
- As a folder owner, I want to grant specific members write access while keeping others read-only, so that only trusted people can change shared credentials
- As a folder owner, I want to keep sole control of who is in the folder and at what grade, so that access management stays with me
- As a security officer, I want every shared-credential change attributed to the person who made it, so that rotations are traceable

## Acceptance Criteria

- [ ] Every team-folder membership carries a `read` (default) or `write` grade
- [ ] Only the folder owner can set or change a member's grade
- [ ] A `read` member behaves exactly as under team-folder-sharing (hold a copy, view only)
- [ ] A `write` member can update a folder secret's value; the change propagates to all recipients as per-recipient RSA copies re-encrypted in the writer's browser
- [ ] The server accepts a non-owner fan-out only when the writer holds a `write` grade on an ancestor team folder
- [ ] The server never decrypts, re-encrypts, or holds plaintext at any step
- [ ] A secret's effective grade is the highest grade any ancestor membership grants; subfolders may raise but not lower it
- [ ] Grade changes re-encrypt nothing
- [ ] Grade changes and non-owner writes are audited with identifiers only; non-owner writes are attributed to the writer

## Notes

- Depends on `team-folder-sharing` (owns the `keepiq_team_folder_members` table this feature adds a `grade` column to).
- Out of scope for v1: a `manage`/co-owner grade (membership management stays owner-only), per-field grades, and narrowing a subfolder's grade below an ancestor's.
- Related ADRs: ADR-001 (own tables), ADR-003 (encryption architecture — write-without-read, public certs server-visible, zero server-side plaintext).
