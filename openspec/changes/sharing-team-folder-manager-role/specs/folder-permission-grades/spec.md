## RENAMED Requirements

- FROM: `### Requirement: Team-folder membership carries a read or write grade`
- TO: `### Requirement: Team-folder membership carries a read, write or manage grade`

## MODIFIED Requirements

### Requirement: Team-folder membership carries a read, write or manage grade

The system MUST record a `read` (default), `write` or `manage` grade on every team-folder membership, shown in the interface as Viewer, Editor and Manager. A `read` grade MUST grant exactly the access team-folder-sharing grants today. A `write` grade MUST additionally authorize value updates that propagate to all recipients. A `manage` grade MUST include everything `write` allows and MUST authorize membership management within the limits of the requirement "Only the owner governs managers and the folder itself". Only the folder owner, or a member whose effective grade on the folder is `manage`, MUST be able to set or change a grade.

#### Scenario: New membership defaults to read

- **GIVEN** an owner shares a folder without specifying a grade
- **WHEN** the membership is created
- **THEN** the system MUST set the grade to `read` and the member MUST NOT be able to push a value update to the team

#### Scenario: Non-owner cannot change a grade

- **GIVEN** a member of a shared folder who is not its owner and whose effective grade is `read` or `write`
- **WHEN** they attempt to change any member's grade
- **THEN** the system MUST reject the request with a forbidden response

#### Scenario: A manager promotes a viewer to editor

- **GIVEN** a member Olga with grade `manage` on a team folder, and a member Bob with grade `read`
- **WHEN** Olga calls `PATCH /api/v1/team-folders/{id}/members/{memberId}` for Bob with grade `write`
- **THEN** Bob's grade MUST become `write`

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

## ADDED Requirements

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

The system MUST refuse, for a member who is not the owner, setting or clearing the `manage` grade, removing or changing a member whose grade is `manage`, changing or removing the owner, stopping sharing of the folder, and deleting the team folder. A manager MUST be able to remove their own membership.

#### Scenario: A manager cannot create another manager

- **GIVEN** Olga with grade `manage` and Bob with grade `read`
- **WHEN** Olga tries to set Bob's grade to `manage`
- **THEN** the system MUST reject the request with a forbidden response and Bob's grade MUST stay `read`

#### Scenario: A manager cannot unshare the folder

- **GIVEN** Olga with grade `manage`
- **WHEN** she calls `DELETE /api/v1/team-folders/{id}`
- **THEN** the system MUST reject the request and the team folder MUST remain

#### Scenario: A manager leaves

- **GIVEN** Olga with grade `manage`
- **WHEN** she removes her own membership
- **THEN** the membership MUST be removed and her derived copies revoked as for any member who leaves
