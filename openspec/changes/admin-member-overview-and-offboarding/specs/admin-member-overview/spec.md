## ADDED Requirements

### Requirement: Administrator lists vault status per user

The system MUST let an administrator list every Nextcloud user with their vault status through `GET /api/v1/admin/members`. Each row MUST carry the user id, display name, whether the account is enabled, the vault status (`none`, `active`, `revoked` or `compromised`), the active suite id when there is one, the secret count, the direct team folder membership count and whether an emergency contact is set. The endpoint MUST be guarded by `#[AuthorizedAdminSetting(AdminSettings::class)]` and MUST support paging, a status filter and a search on user id or display name.

#### Scenario: Administrator sees who has not set up a vault

- **GIVEN** user `alice` has an active encryption suite and user `bob` has never unlocked keepiq
- **WHEN** an administrator calls `GET /api/v1/admin/members?status=none`
- **THEN** the response MUST list `bob` with `vaultStatus` `none` and no `activeSuiteId`
- **AND** the response MUST NOT list `alice`

#### Scenario: Administrator reads the active suite id

- **GIVEN** user `alice` has an active encryption suite
- **WHEN** an administrator calls `GET /api/v1/admin/members?search=alice`
- **THEN** the row for `alice` MUST carry `vaultStatus` `active` and her active suite id

#### Scenario: A non-administrator is refused

- **GIVEN** an authenticated user who is not an administrator and holds no delegation for the keepiq admin settings
- **WHEN** they call `GET /api/v1/admin/members`
- **THEN** Nextcloud MUST refuse the request before the controller runs

### Requirement: Member overview returns metadata only

The member overview MUST return identifiers, statuses, counts and dates only. It MUST NOT return a certificate, a private key blob, a secret name or any ciphertext.

#### Scenario: No key material in the list

- **GIVEN** a page of users with active suites and secrets
- **WHEN** an administrator calls `GET /api/v1/admin/members`
- **THEN** no row MUST contain a `certificate`, `privateKey`, `key`, `login` or `additionalFields` field

### Requirement: Administrator acts on a member row

The admin settings MUST show the member overview in a "Members" section with a status filter and a search field. Each row MUST offer "Offboard", which prefills the leaving user in the team offboarding section, and "Revoke suite", which prefills the suite id in the encryption suites section, when the user has an active suite.

#### Scenario: Revoke a suite without typing its id

- **GIVEN** an administrator on the Keepiq admin settings page and user `alice` with an active suite
- **WHEN** they choose "Revoke suite" on the `alice` row of the "Members" section
- **THEN** the "Encryption suites" section MUST show `alice`'s active suite id in its suite id field

#### Scenario: Start offboarding from the list

- **GIVEN** an administrator on the Keepiq admin settings page
- **WHEN** they choose "Offboard" on the `bob` row of the "Members" section
- **THEN** the "Team offboarding" section MUST show `bob` as the leaving user
