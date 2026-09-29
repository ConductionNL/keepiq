---
kind: code
---

# Public admin API

## Why

The admin screens call internal routes with unstable paths and no documentation. A script can reach them with an app password, but nothing promises they stay the same. Organisations that automate onboarding, offboarding and audits need a documented, versioned admin API.

| Row | Capability | What keepiq does today |
|---|---|---|
| admin-13 | Manage the organisation through a public admin API | The admin screens call internal REST routes that a script could reach with a Nextcloud app password, but there is no documented, versioned admin API or scoped admin token. |

Matrix: keepiq `openspec/parity/capabilities.json`

### Demand

No demand row.

### Competitors rated yes

- Bitwarden: "bitwarden/server@v2026.9.1 src/Api/AdminConsole/Public/Controllers/MembersController.cs, GroupsController.cs, CollectionsController.cs, PoliciesController.cs, OrganizationController.cs:48 import; src/Api/Dirt/Public/Controllers/EventsController.cs Note: Public REST API (organisation API key) for members, groups, collections, policies, import and events."
- Passbolt: "passbolt/passbolt_api@v5.16.0 config/routes.php:133-155 /groups CRUD, users, permissions and share routes; plugins/PassboltEe/AuditLog action log routes; plugins/PassboltCe/JwtAuthentication/config/routes.php:36 JWT login for scripted admin access Note: Everything the admin UI does goes through a documented JSON API that an admin account can script."
- Keeper: "https://docs.keeper.io/enterprise-guide/developer-tools : Commander CLI and Python SDK manage the enterprise (users, roles, teams, reports); SCIM API for provisioning"
- HashiCorp Vault: "hashicorp/vault@v2.1.1 vault/logical_system_paths.go:2899 sys/internal/specs/openapi documents every admin path; api/ Go client Note: Every admin operation is an HTTP API call; the UI and CLI are clients of it."

## What Changes

- A versioned admin API under `/api/v1/admin/`, with an index at `GET /api/v1/admin` that returns the API version and every path.
- v1 covers members, offboarding, policies, applications, audit events, compliance reports, SIEM sinks and suite listing and reinstatement. Each endpoint delegates to the service the admin screen already uses.
- Authentication is Nextcloud's own: a browser session, or a Nextcloud app password over HTTP Basic with the `OCS-APIRequest: true` header. A scoped admin token is an app password of a service account whose group holds only the Keepiq admin areas it needs (change `admin-scoped-roles`).
- Every endpoint is guarded by one admin area, so a token can do exactly what its account may do.
- Suite force revocation stays out of the API. It needs a fresh password confirmation that a stored token cannot give.
- An OpenAPI 3.1 document at `docs/api/admin-v1.openapi.json`, a contract test that keeps it equal to `appinfo/routes.php`, a Newman collection, and a docs page.
- A versioning rule: v1 only grows; a breaking change ships as v2 next to v1.

## Capabilities

### New Capabilities

- `admin-api`: a documented, versioned HTTP API for Keepiq administration, authenticated with Nextcloud credentials and scoped by admin area.

### Modified Capabilities

None.

## Impact

- **Backend**: new thin controllers under `lib/Controller/Admin/` that call `AdminSettingsService`, `ApplicationService`, `AuditService`, `ComplianceReportService`, `SiemSinkService`, `EncryptionSuiteService`, `TeamFolderOffboardingService` and the member overview service; new routes in `appinfo/routes.php` before the SPA catch-all.
- **Frontend**: none required. The admin screens may move to the new paths later; the old routes stay.
- **Database**: none.
- **Security**: no new credential type. The API returns metadata only, never a private key blob, a secret value or ciphertext. CSRF protection stays on; script clients pass it with the `OCS-APIRequest` header as Nextcloud clients do.
- **Cross-app**: the Terraform provider (change `apps-terraform-provider`) uses this API for application resources.
