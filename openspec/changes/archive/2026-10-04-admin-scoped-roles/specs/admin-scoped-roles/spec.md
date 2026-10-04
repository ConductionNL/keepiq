## ADDED Requirements

### Requirement: Keepiq administration is split into delegable areas

The system MUST register five named Keepiq admin settings areas that Nextcloud can delegate separately: General, Policies, Applications and machine access, People and offboarding, and Audit and compliance. Each area MUST implement `IDelegatedSettings` with a translated name so it appears on Nextcloud's "Administration privileges" page. A role MUST be a Nextcloud group delegated one or more areas.

#### Scenario: Administrator builds an auditor role

- **GIVEN** an instance administrator on Nextcloud's "Administration privileges" page
- **WHEN** they delegate the Keepiq "Audit and compliance" area to group `keepiq-auditors`
- **THEN** a member of `keepiq-auditors` MUST see the audit log, compliance and SIEM sections in the Keepiq admin settings
- **AND** that member MUST NOT see the policy, application or offboarding sections

### Requirement: Every Keepiq admin endpoint is guarded by exactly one area

Every Keepiq endpoint that requires administration MUST be guarded by `#[AuthorizedAdminSetting]` naming exactly one area class, or by `AdminAreaAuthorizer::holds()` naming exactly one area class inside a service. Instance administrators MUST pass every guard. A user outside the area MUST be refused before the controller body runs. The admin settings MUST be read and written per area, through `/api/settings/admin/{general,policies,applications,audit}`, and an area write MUST refuse a key of another area. Version and trash retention MUST belong to Policies.

#### Scenario: Auditor cannot change policies

- **GIVEN** a member of a group delegated only the "Audit and compliance" area
- **WHEN** they call `PUT /api/settings/admin/policies`
- **THEN** Nextcloud MUST refuse the request and no setting MUST change

#### Scenario: An area write carries only its own keys

- **GIVEN** a member of a group delegated only the "Audit and compliance" area
- **WHEN** they call `PUT /api/settings/admin/audit` with `audit_retention_days` and `min_password_length`
- **THEN** the request MUST answer 400 and no setting MUST change

#### Scenario: Applications holder approves an application

- **GIVEN** a member of a group delegated only the "Applications and machine access" area and a pending application
- **WHEN** they call `POST /api/v1/applications/{id}/approve`
- **THEN** the application MUST be approved with that member recorded as approver

#### Scenario: Instance administrator keeps every action

- **GIVEN** an instance administrator with no Keepiq delegation
- **WHEN** they call `POST /api/v1/suites/{id}/force-revoke` after password confirmation
- **THEN** the guard MUST let the request through

### Requirement: In-app admin actions follow the People and offboarding area

The admin handover panel in the secret sidebar and the team offboarding action MUST be available exactly to instance administrators and holders of the "People and offboarding" area. `GET /api/v1/delegations/capabilities` MUST report `canHandover` from the same check the handover endpoint enforces. Membership of the `vault_admin` group MUST count as holding that area only until the alias is removed, and the admin settings MUST warn while that group has members.

#### Scenario: Helpdesk member sees the handover panel

- **GIVEN** a member of a group delegated only "People and offboarding", holding a share of a secret owned by another user
- **WHEN** they open that secret's sidebar at `/secrets/{id}`
- **THEN** the admin handover panel MUST be shown

#### Scenario: Legacy vault_admin member is warned about

- **GIVEN** the `vault_admin` group has one member and no area is delegated to it
- **WHEN** an instance administrator opens the Keepiq admin settings
- **THEN** the General area MUST show a notice asking to delegate the "People and offboarding" area instead
