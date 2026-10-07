## ADDED Requirements

### Requirement: Versioned admin API index

The system MUST serve `GET /api/v1/admin` to any user holding at least one Keepiq admin area. The response MUST contain `apiVersion`, the list of served admin API versions and every v1 path with its method. A breaking change to a path, field or status code MUST ship as a new version next to the old one, never as a change to v1.

#### Scenario: Script discovers the admin API

- **GIVEN** a service account in a group delegated the "Audit and compliance" area, with a Nextcloud app password
- **WHEN** a script calls `GET /api/v1/admin` with HTTP Basic auth and the header `OCS-APIRequest: true`
- **THEN** the response MUST contain `apiVersion` `1` and the paths of the v1 admin endpoints

### Requirement: Admin API authenticates with Nextcloud credentials and honours admin areas

Every admin API endpoint MUST accept a Nextcloud session or a Nextcloud app password over HTTP Basic, and MUST be guarded by exactly one Keepiq admin area. A caller outside that area MUST be refused before the controller runs. CSRF protection MUST stay enabled.

#### Scenario: Audit token cannot change policies

- **GIVEN** a service account whose group holds only the "Audit and compliance" area
- **WHEN** a script calls `PUT /api/v1/admin/policies` with its app password
- **THEN** the response MUST be a refusal and no setting MUST change

#### Scenario: People token offboards a leaver

- **GIVEN** a service account whose group holds the "People and offboarding" area, leaving user `carol` and successor `dave`
- **WHEN** a script calls `POST /api/v1/admin/offboarding` with `leavingUserId` `carol` and `successorUserId` `dave`
- **THEN** the response MUST report the revoked, transferred and removed counts, as the admin screen does

### Requirement: Admin API covers the administration jobs

The v1 admin API MUST offer: the member overview, offboarding, suite listing, reading and updating policies, registering, listing, reading, approving, rejecting and deleting applications and setting their lease policy, reading audit events, generating and reading compliance reports, and managing SIEM sinks. Each endpoint MUST call the same service the admin screen calls.

#### Scenario: Script approves a pending application

- **GIVEN** a service account whose group holds the "Applications and machine access" area and a pending application `ci-runner`
- **WHEN** a script calls `POST /api/v1/admin/applications/{id}/approve` for `ci-runner`
- **THEN** `ci-runner` MUST be approved with the service account recorded as approver
- **AND** the audit trail MUST show the approval

### Requirement: Admin API returns metadata only

No admin API response MUST contain a private key blob, a secret value, secret ciphertext or a SIEM sink credential in plain form. Suite force revocation and suite reinstatement MUST NOT be reachable through the admin API, because both require a fresh password confirmation.

#### Scenario: Suite listing carries no key material

- **GIVEN** a service account holding the "People and offboarding" area
- **WHEN** a script calls `GET /api/v1/admin/suites`
- **THEN** each suite row MUST carry id, owner, status and dates
- **AND** no row MUST contain `privateKey`

#### Scenario: Force revocation is not offered

- **GIVEN** any admin API caller
- **WHEN** they read the path list from `GET /api/v1/admin`
- **THEN** the list MUST NOT contain a force revocation path

### Requirement: Admin API is documented and contract-tested

The system MUST ship an OpenAPI 3.1 document at `docs/api/admin-v1.openapi.json` that describes every v1 admin path. An automated test MUST fail when a `/api/v1/admin` route in `appinfo/routes.php` is missing from the document, or a documented path has no route.

#### Scenario: Undocumented route fails the build

- **GIVEN** a developer adds a new `/api/v1/admin` route without documenting it
- **WHEN** the PHPUnit suite runs
- **THEN** the admin API contract test MUST fail and name the undocumented route
