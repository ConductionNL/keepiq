# vault-policies Specification

## Purpose
TBD - created by archiving change admin-vault-policies. Update Purpose after archive.

## Requirements

### Requirement: Administrator configures vault policies per group

The system MUST offer three vault policies in the Keepiq admin settings: a personal vault export ban, a two-factor login requirement before unlock, and team folder ownership of work logins. Each policy MUST be off by default and MUST apply either to every user or to members of administrator-chosen Nextcloud groups. Only an administrator MUST be able to change them through `PUT /api/settings/admin`. Every change MUST dispatch one audit event carrying a before and after snapshot. `GET /api/settings/policy` MUST return, for the session user, whether each policy applies to them, and MUST NOT return the group lists.

#### Scenario: Administrator scopes the export ban to a group

- **GIVEN** an administrator on the Keepiq admin settings page
- **WHEN** they switch on "Block personal vault export" for group `staff` in the "Vault policies" section and save
- **THEN** `GET /api/settings/policy` MUST report the export ban as applying for a member of `staff`
- **AND** it MUST report the ban as not applying for a user outside `staff`
- **AND** one policy audit event with the before and after values MUST be recorded

### Requirement: Personal vault export can be blocked

When the export ban applies to a user, `POST /api/v1/export/events` MUST refuse the modes `encrypted-backup`, `plaintext-csv`, `cxf` and `cxp` with 403 and code `export_disabled_by_policy`, and the browser MUST NOT offer the export file. The export dialog MUST hide the blocked modes. The GDPR access package MUST stay available.

#### Scenario: Blocked user gets no backup file

- **GIVEN** the export ban applies to vault owner `erin`
- **WHEN** `erin` opens the export dialog from the secret list at `/secrets`
- **THEN** the encrypted backup and CSV options MUST NOT be offered
- **AND** a direct `POST /api/v1/export/events` with mode `encrypted-backup` MUST return 403 with code `export_disabled_by_policy`

#### Scenario: GDPR package still downloads

- **GIVEN** the export ban applies to vault owner `erin`
- **WHEN** `erin` requests her GDPR data package from the user settings
- **THEN** the package MUST download

### Requirement: Vault unlock requires Nextcloud two-factor login

When the two-factor policy applies to a user and Nextcloud reports no enabled two-factor provider for them other than backup codes, the system MUST omit `privateKey` from `GET /api/v1/suites` and `GET /api/v1/suites/{id}` and MUST add `unlockBlocked` with value `two_factor_required`. It MUST leave the suite out of `GET /api/v1/offline/manifest` and MUST refuse `POST /api/v1/suites` with 428 and `error` and `code` `two_factor_required` (428 rather than 403 because Nextcloud's OCS layer turns a 403 of an OCS controller into an HTTP 200 envelope). The lock screen MUST explain the reason and link to the Nextcloud security settings. Enforcing two-factor login itself MUST stay with Nextcloud.

#### Scenario: User without two-factor login cannot unlock

- **GIVEN** the two-factor policy applies to vault owner `frank` and `frank` has no two-factor provider enabled
- **WHEN** `frank` opens the lock screen at `/lock` and enters his master password
- **THEN** the vault MUST stay locked
- **AND** the lock screen MUST show that the organisation requires two-factor login, with a link to `/settings/user/security`
- **AND** `GET /api/v1/suites` MUST return his suite without `privateKey`

#### Scenario: Enabling two-factor login restores access

- **GIVEN** the two-factor policy applies to vault owner `frank` and he enables a TOTP provider in Nextcloud
- **WHEN** `frank` enters his master password on the lock screen
- **THEN** the vault MUST unlock

#### Scenario: The CLI gets the same answer

- **GIVEN** the two-factor policy applies to vault owner `frank`, who has no two-factor provider enabled
- **WHEN** `frank` runs `keepiq list` with an app password
- **THEN** the CLI MUST exit with an error naming `two_factor_required`

### Requirement: Work logins are kept in team folders

When the ownership policy applies to a user, the system MUST refuse to create, import or move a secret of an in-scope type (default `login`, `api_key` and `database`) into a folder that has no team folder owned by that user among its ancestors. The refusal MUST carry the code `org_ownership_required` as `error` and as `code`. On the secrets API (`POST /api/v1/secrets`, `PUT /api/v1/secrets/{id}`), which runs on OCS controllers, its status MUST be 428: Nextcloud's OCS layer turns a 403 there into an HTTP 200 envelope, so the browser would take the refusal for a saved secret. Secrets of other types MUST stay unaffected.

#### Scenario: Personal login refused

- **GIVEN** the ownership policy applies to vault owner `gina`
- **WHEN** `gina` calls `POST /api/v1/secrets` for a `login` secret in her personal folder `Private`
- **THEN** the response MUST be 428 with `error` and `code` `org_ownership_required`, and the browser MUST show the refusal
- **AND** no secret MUST be stored

#### Scenario: Exempt type stays personal

- **GIVEN** the ownership policy applies to vault owner `gina` with the default types
- **WHEN** `gina` saves a `card` secret in her personal folder `Private`
- **THEN** the secret MUST be stored

### Requirement: Write-grade members save new secrets into a team folder

The system MUST let a member whose effective grade on a team folder is `write` create a new secret in that folder through `POST /api/v1/team-folders/{id}/secrets`. The request MUST carry the value encrypted in the member's browser under the folder owner's certificate and under every effective member's certificate. The server MUST store the owner row as owned by the folder owner, MUST register a derived copy per member, MUST audit the creation with the member as actor, and MUST NOT decrypt any blob. A member with a `read` grade and a non-member MUST be refused.

#### Scenario: Member saves a work login into the team folder

- **GIVEN** `hank` holds a `write` grade on team folder `Ops`, owned by `iris`, with members `hank` and `jack`
- **WHEN** `hank` saves a new `login` secret into `Ops` from the secret form
- **THEN** `iris` MUST own the stored secret
- **AND** `hank` and `jack` MUST each receive a copy they can decrypt
- **AND** the audit trail MUST show `hank` as the actor of the creation

#### Scenario: Read-grade member is refused

- **GIVEN** `jack` holds a `read` grade on team folder `Ops`
- **WHEN** `jack` calls `POST /api/v1/team-folders/{id}/secrets` for `Ops`
- **THEN** the response MUST be 403 and no secret MUST be stored

### Requirement: Users see personal items that break the ownership policy

When the ownership policy applies to a user, the health report MUST list their secrets of in-scope types that sit outside a team folder, and MUST offer a move action into a team folder they own or can contribute to.

#### Scenario: Existing personal login is listed

- **GIVEN** vault owner `gina` has an older `login` secret in folder `Private` and the ownership policy now applies to her
- **WHEN** `gina` opens the health report
- **THEN** the "Not in a team folder" list MUST show that secret with a "Move to a team folder" action
