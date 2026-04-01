## Why

Doriath can store secrets for users, but external and internal applications (e.g., OpenConnector, CI/CD pipelines, microservices) have no way to register as vault consumers and manage their own secrets. Without application management, Doriath cannot serve as a shared secret store for the broader Nextcloud ecosystem — the core value proposition that differentiates it from browser-only password managers. Application management is an MVP-tier feature that unblocks OpenConnector integration and any external system that needs to securely store and retrieve credentials through Doriath.

## What Changes

- Add a new `doriath_applications` database table (Version000012) for registering external and internal applications
- Implement application registration open to any user (including anonymous); admin-registered applications are auto-approved, non-admin registrations enter a pending approval queue
- Implement EncryptionSuite provisioning for applications via two paths: CSR upload (app manages own private key) or server-generated key pair (private key returned once, never stored in plaintext)
- Implement approval queue for vault administrators to approve or reject pending applications
- Implement application deletion with hard cascade: application record + EncryptionSuite + all attributed secrets are permanently removed
- Implement write-without-read: users can write secrets for an application encrypted with the app's public certificate, but cannot read them back
- Implement RFC 7523 JWT Bearer API authentication so applications can authenticate and retrieve their own secrets via REST API
- Dispatch Nextcloud notifications to all vault administrators when a non-admin registers an application
- Wire pending applications counter into the existing dashboard summary (already built in implement-dashboard-settings)

## Capabilities

### New Capabilities
- `application-mgmt`: Application entity CRUD, registration (open + approval queue), EncryptionSuite provisioning (CSR and generated key pair), deletion with cascade, admin notification, and pending counter integration
- `application-api-auth`: RFC 7523 JWT Bearer authentication for application API access — token endpoint, JWT verification against stored public certificate, short-lived access token issuance, and authenticated secret retrieval

### Modified Capabilities
- `encryption-suites`: EncryptionSuite creation now supports application ownership (owner_type=application) with CSR-based provisioning where private_key is null (held externally by the app)
- `secrets`: Secrets can now be owned by applications (owner_type=application, owner_id=application.id); write-without-read semantics for application-attributed secrets
- `dashboard`: Dashboard summary includes pending applications counter for admin users

## Impact

- **Database**: One new table `doriath_applications` (Version000012 migration)
- **Backend**: New entities (Application), mappers (ApplicationMapper), services (ApplicationService, JwtAuthService), controllers (ApplicationController, ApplicationTokenController), middleware (JwtAuthMiddleware for application API routes)
- **Frontend**: New Pinia store (useApplicationStore), Vue views (ApplicationList, ApplicationDetail, ApprovalQueue), registration form with CSR upload, application detail with secrets list
- **API**: New REST endpoints for application CRUD, approval/rejection, and a `/api/v1/token` endpoint for JWT Bearer token exchange; new application-authenticated endpoints for secret retrieval
- **Dependencies**: PHP JWT library (firebase/php-jwt or lcobucci/jwt) for RFC 7523 token verification; no new frontend dependencies
- **Cross-app**: OpenConnector can register as an internal application and use JWT auth to retrieve connector credentials from Doriath
- **Security**: CSR processing validates PKCS#10 format; generated private keys are returned once over HTTPS and never persisted in plaintext; JWT tokens are short-lived (5-minute default); application deletion is irreversible with hard cascade
- **Notifications**: Reuses existing NotificationService and DoriathNotifier from implement-user-sharing
- **Dashboard**: Reuses existing DashboardService from implement-dashboard-settings; adds pending application count to the summary response
