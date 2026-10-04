## ADDED Requirements

### Requirement: Offboarding removes the leaver's direct team folder memberships

The admin offboarding action (`POST /api/v1/team-folders/offboard`) MUST, after revoking derived shares and transferring owned team secrets, delete every direct `user` membership row of the leaving user in every team folder. It MUST NOT delete a group membership row. The response MUST report the number of removed rows as `membershipsRemoved` and MUST list each group row that still covers the leaver as `stillCoveredByGroups`. The `TEAM_FOLDER_OFFBOARDED` audit event MUST carry the removed row count and the covering group ids, and no key material.

#### Scenario: Direct membership rows are removed

- **GIVEN** leaving user `carol` is a direct member of team folders `Finance` and `Ops`, and successor `dave`
- **WHEN** an administrator runs the offboarding action for `carol` with successor `dave`
- **THEN** no `user` membership row for `carol` MUST remain in `keepiq_team_folder_members`
- **AND** the response MUST report `membershipsRemoved` as 2

#### Scenario: Group coverage is reported, not deleted

- **GIVEN** leaving user `carol` is also covered by group `finance-team`, which is a member of team folder `Finance`
- **WHEN** an administrator runs the offboarding action for `carol`
- **THEN** the `finance-team` membership row MUST remain
- **AND** the response MUST list `Finance` with group `finance-team` under `stillCoveredByGroups`
- **AND** the "Team offboarding" section MUST show that group as a warning

### Requirement: The fan-out never re-shares to a disabled account

The team folder reconcile (`GET /api/v1/team-folders/{id}/reconcile`) MUST exclude every user whose Nextcloud account is disabled from the recipients and from the missing pairs, even when a membership row still covers that user.

#### Scenario: Disabled leaver in a member group gets no new copy

- **GIVEN** user `carol` is disabled in Nextcloud, still has an active suite, and is covered by group `finance-team` on team folder `Finance`
- **WHEN** the owner of `Finance` opens the team folder dialog and the reconcile runs
- **THEN** the missing pairs MUST NOT contain `carol`
- **AND** the fan-out MUST create no share for `carol`
