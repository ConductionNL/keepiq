## MODIFIED Requirements

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
