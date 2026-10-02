# Design: public admin API

## Context

Read at development `4c214a9d`.

- `appinfo/routes.php:27` and `:28` serve the admin settings at `/api/settings/admin`, outside the `/api/v1/` prefix; the application admin routes sit at `:271` to `:286` (`/api/v1/applications*`); audit at `:377` to `:379`; compliance at `:195` to `:199`; SIEM sinks at `:203` to `:207`; suites at `:35` to `:43`.
- The only documented, versioned contract is the machine API under `/api/v1/app/*` (`appinfo/routes.php:295` to `:321`), with a discovery document and a rule that breaking changes ship as a new version (`openspec/specs/secret-store-api/spec.md`, "Machine API Discovery Document").
- `lib/Controller/AuditController.php:180` `index()` and the settings methods are guarded by `#[AuthorizedAdminSetting(AdminSettings::class)]`. Other admin checks are inline `isAdmin()` calls (see change `admin-scoped-roles`).
- Nextcloud's `Request::passesCSRFCheck()` accepts a request carrying an `OCS-APIRequest` header when no session cookie is present (`server/lib/private/AppFramework/Http/Request.php:436`). An app password over HTTP Basic plus that header therefore reaches a regular controller with CSRF protection on.
- `tests/integration/machine-secret-api.postman_collection.json` and `run-newman.sh` already run in CI (`.github/workflows/code-quality.yml:159`, `enable-newman: true`).
- `docs/` is a Docusaurus site with `docs/tutorials/admin/`.

## Goals / Non-Goals

**Goals:**

- One stable, documented path per admin job a script needs.
- No new credential type: Nextcloud app passwords, scoped by the account's admin areas.
- The document and the routes cannot drift apart unnoticed.

**Non-Goals:**

- Suite force revocation over the API (see D4).
- User provisioning. Nextcloud's own provisioning API and SCIM apps create users; Keepiq has no user store.
- Reading secrets, certificates or key material. The admin API is metadata only, like the admin screens.
- Moving the admin screens to the new paths in this change.

## Decisions

### D1: A new `/api/v1/admin/` prefix with thin controllers

v1 endpoints:

| Method and path | Area | Service |
|---|---|---|
| `GET /api/v1/admin` | any area | index: `apiVersion`, versions, the caller's areas, paths |
| `GET /api/v1/admin/members` | People | member overview (change `admin-member-overview-and-offboarding`, PR #895); added once that lands |
| `POST /api/v1/admin/offboarding` | People | `TeamFolderService::offboard()` |
| `GET /api/v1/admin/suites` | People | `EncryptionSuiteMapper::findAllActiveWithLimit()`, metadata only |
| `GET`, `PUT /api/v1/admin/policies` | Policies | the Policies area settings (`AdminAreaSettingsController`) |
| `GET`, `POST /api/v1/admin/applications`, `GET`, `DELETE .../{id}`, `POST .../{id}/approve`, `POST .../{id}/reject` | Applications | `ApplicationService` |
| `GET`, `PUT /api/v1/admin/applications/{id}/lease-policy` | Applications | `LeaseService` |
| `GET /api/v1/admin/audit` | Audit | `AuditService::adminQuery()` |
| `GET`, `POST /api/v1/admin/compliance/reports`, `GET .../{id}` | Audit | `ComplianceReportService` |
| `GET`, `POST /api/v1/admin/siem/sinks`, `PUT`, `DELETE .../{id}` | Audit | `SiemService` |

Registering an application (`POST /applications`), reading one (`GET .../{id}`) and its lease policy were added for the Terraform provider (change `apps-terraform-provider`, D5): an administrator's registration is active at once. Suite reinstatement is out of v1: since keepiq#865 it carries `#[PasswordConfirmationRequired]` like force revocation (see D4).

Controllers (`AdminIndexController`, `AdminPeopleController`, `AdminApplicationController`, `AdminAuditController` in `lib/Controller/`; Nextcloud resolves route names to that namespace only) hold no logic beyond parameter mapping. Every one except the index carries one `#[AuthorizedAdminSetting(<Area>::class)]`, so a caller outside the area is refused in the middleware. The policies pair reuses the area settings methods under a route `postfix`, so the screen and the API share one code path. Responses use the same shapes and error envelope as the existing endpoints (org ADR-050).

Alternative considered: document the existing internal routes as the public API. Rejected: their paths are inconsistent (`/api/settings/admin` next to `/api/v1/...`) and some mix owner and admin behaviour behind one path, so freezing them would freeze that.

Alternative considered: OCS controllers with Nextcloud's openapi-extractor. Rejected: every other Keepiq endpoint uses the ADR-050 envelope; an OCS envelope for admin only would give scripts two response shapes.

### D2: Nextcloud credentials, scoped by admin area

A script authenticates as a Nextcloud user: a session, or an app password over HTTP Basic with `OCS-APIRequest: true`. The guard on each endpoint is one admin area (change `admin-scoped-roles`), so the recommended setup is a service account in a group that holds only the needed areas, with one app password per integration. Revoking the app password in the account's security settings cuts the integration off.

Alternative considered: admin tokens as Keepiq applications with admin scopes over the RFC 7523 flow. Rejected: an application is a vault owner with its own suite; making it an admin principal mixes two roles and adds a second admin credential store to secure.

### D3: The document is checked in and contract-tested

`docs/api/admin-v1.openapi.json` (OpenAPI 3.1) describes every v1 path, parameter, response and the auth scheme. A PHPUnit test parses `appinfo/routes.php` and the document and fails when a `/api/v1/admin` route is missing from the document or the other way round. A Newman collection `tests/integration/admin-api.postman_collection.json` runs every endpoint against the CI instance, including a refusal for a user outside the area. The docs site renders the document on an "Admin API" page.

### D4: No force revocation over the API

`POST /api/v1/suites/{id}/force-revoke` carries `#[PasswordConfirmationRequired]` (ADR-005): the administrator re-confirms their own password at that moment. A stored app password cannot give that proof, so the API leaves force revocation out and the index says so. Since keepiq#865 reinstatement carries the same guard, so it is out too (POLICY.md, lane G2).

### D5: v1 only grows

Additive fields and endpoints may land in v1. Removing or renaming a field, or changing a status code, ships as `/api/v2/admin/` next to v1, with v1 kept for at least one minor release and marked with a `Sunset` header. The index lists every served version.

## Security and zero-knowledge

- The server never holds plaintext in any admin flow, and the admin API adds none. It returns identifiers, statuses, counts, dates and settings.
- Stored encrypted versus plain: nothing new is stored. App passwords are Nextcloud's, hashed by Nextcloud.
- Every endpoint runs Nextcloud's admin area guard before the controller. CSRF stays on, so a logged-in browser cannot be tricked into an admin call from another site.
- Rate limiting uses `#[UserRateLimit]` on the write endpoints so a leaked app password cannot hammer them.

## Risks / Trade-offs

- Two paths serve the same admin action until the screens move over. Both call one service, so behaviour cannot differ.
- A service account with an app password is a standing credential. The docs page tells administrators to hold it in a secret store, ideally Keepiq's own machine API.
- The document is hand-written. The contract test catches missing paths, not wrong field types; the Newman collection covers the shapes.

## Seed data

None in the app. The Newman collection creates its own service account and delegation through `tests/e2e/ci-seed.sh` before it runs.

## Migration

None. No table or column; new routes only. `<version>` in `appinfo/info.xml` does not need a bump for schema reasons.
